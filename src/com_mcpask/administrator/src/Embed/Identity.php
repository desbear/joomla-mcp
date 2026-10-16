<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Embed;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Who a visitor of an embedded Ask the Site is. A page in a frame on another website cannot count
 * on this site's session cookie (browsers keep such cookies from frames), so a signed token
 * stands in for it, sent with each request:
 *
 * - one this site gives after the visitor signs in here in a small window (the account centre's
 *   login, as on this site itself), bound to the embed key, for a few hours;
 * - one the other website's server signs with the key's secret (a JWT, HS256: aud = the public key,
 *   email, name, exp), for visitors already signed in there. Its email must belong to an account
 *   here; no account is made for it.
 *
 * Nothing the other website's page says about who the visitor is counts without a signature.
 *
 * @since  0.11.0
 */
final class Identity
{
    /** How long a sign-in in the small window lasts */
    public const HOURS = 8;

    /** The longest a token from the other website may last */
    private const PRODUCT_SECONDS = 3600;

    public function __construct(private DatabaseInterface $db, private string $secret)
    {
    }

    /**
     * A token for a visitor who signed in here, for one embed key.
     */
    public function issue(User $user, object $embed): string
    {
        $payload = self::encode(json_encode(['u' => (int) $user->id, 'k' => (int) $embed->id, 'x' => time() + self::HOURS * 3600]));

        return 'v1.' . $payload . '.' . self::encode(hash_hmac('sha256', 'v1.' . $payload, $this->key(), true));
    }

    /**
     * The visitor a token stands for, if it is good for this key: one this site gave, or one the
     * other website signed (when the key accepts those).
     */
    public function user(string $token, object $embed): ?User
    {
        $token = trim($token);

        if (str_starts_with($token, 'v1.')) {
            return $this->siteUser($token, $embed);
        }

        if ($embed->params->get('product_tokens', 0) && substr_count($token, '.') === 2) {
            return $this->productUser($token, $embed);
        }

        return null;
    }

    private function siteUser(string $token, object $embed): ?User
    {
        $parts = explode('.', $token);

        if (\count($parts) !== 3) {
            return null;
        }

        $expected = self::encode(hash_hmac('sha256', 'v1.' . $parts[1], $this->key(), true));

        if (!hash_equals($expected, $parts[2])) {
            return null;
        }

        $data = json_decode((string) self::decode($parts[1]), true);

        if (!\is_array($data) || (int) ($data['k'] ?? 0) !== (int) $embed->id || (int) ($data['x'] ?? 0) < time()) {
            return null;
        }

        return $this->active((int) ($data['u'] ?? 0));
    }

    /**
     * A JWT the other website's server signed with the key's secret.
     */
    private function productUser(string $token, object $embed): ?User
    {
        [$head, $body, $signature] = explode('.', $token);

        $header = json_decode((string) self::decode($head), true);

        if (!\is_array($header) || ($header['alg'] ?? '') !== 'HS256' || (string) $embed->secret === '') {
            return null;
        }

        if (!hash_equals(self::encode(hash_hmac('sha256', $head . '.' . $body, (string) $embed->secret, true)), $signature)) {
            return null;
        }

        $claims = json_decode((string) self::decode($body), true);
        $now    = time();

        if (!\is_array($claims)
            || ($claims['aud'] ?? '') !== $embed->public_key
            || (int) ($claims['exp'] ?? 0) < $now
            || (int) ($claims['exp'] ?? 0) - (int) ($claims['iat'] ?? $now) > self::PRODUCT_SECONDS
            || (int) ($claims['iat'] ?? 0) > $now + 60) {
            return null;
        }

        $email = filter_var((string) ($claims['email'] ?? ''), FILTER_VALIDATE_EMAIL);

        if (!$email) {
            return null;
        }

        $id = (int) $this->db->setQuery(
            $this->db->getQuery(true)->select('id')->from($this->db->quoteName('#__users'))
                ->where('LOWER(email) = :email')->bind(':email', strtolower($email))
        )->loadResult();

        return $this->active($id);
    }

    /**
     * An account that may sign in: there, not blocked, activated.
     */
    private function active(int $id): ?User
    {
        if ($id < 1) {
            return null;
        }

        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($id);

        return $user && (int) $user->id === $id && !$user->block && \in_array((string) $user->activation, ['', '0'], true) ? $user : null;
    }

    private function key(): string
    {
        return hash_hmac('sha256', 'mcpask-embed-identity', $this->secret, true);
    }

    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function decode(string $text): ?string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
