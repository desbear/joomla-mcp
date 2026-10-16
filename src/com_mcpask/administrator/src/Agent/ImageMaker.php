<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Agent;

use Gss\Component\Mcpask\Administrator\Ask\State;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Pictures for reports, made by an image service: through the "AI image" plugin when it is there
 * and set up (its service, key and model), otherwise with the component's own settings (OpenAI or
 * an Open WebUI server). A picture is kept by its prompt, so the same prompt costs once; a day's
 * pictures are capped. Files go to images/mcpask/reports.
 *
 * @since  0.9.0
 */
final class ImageMaker
{
    public const FOLDER = 'images/mcpask/reports';

    private const PLUGIN = 'Gss\\Plugin\\System\\GssAiImage\\Service\\Settings';

    private const WIDTH = 1200;

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * The service that would draw, for the back end: "plugin:openai", "own:openwebui", or "".
     */
    public function source(): string
    {
        if ($this->plugin()) {
            return 'plugin:' . $this->plugin()->defaultProvider();
        }

        $own = $this->own();

        return $own['key'] !== '' || $own['provider'] === 'openwebui' && $own['base'] !== '' ? 'own:' . $own['provider'] : '';
    }

    /**
     * A picture for a prompt: the one made before for the same prompt, or a new one while the
     * day's cap allows. Null when none can be had (no service, the cap reached, the service failed).
     *
     * @return  array{url: string, path: string}|null
     */
    public function make(string $prompt, int $perDay): ?array
    {
        $prompt = trim(mb_substr($prompt, 0, 2000));

        if ($prompt === '') {
            return null;
        }

        $name = sha1($prompt . '|' . $this->source());

        foreach (['webp', 'jpg', 'png'] as $ext) {
            $path = self::FOLDER . '/' . $name . '.' . $ext;

            if (is_file(JPATH_ROOT . '/' . $path)) {
                return ['url' => '/' . $path, 'path' => $path];
            }
        }

        if ($this->source() === '' || !$this->count($perDay)) {
            return null;
        }

        try {
            $binary = $this->plugin() ? $this->byPlugin($prompt) : $this->byOwn($prompt);
            $image  = $this->encode($binary);
        } catch (\Throwable) {
            return null;
        }

        if (!is_dir(JPATH_ROOT . '/' . self::FOLDER)) {
            mkdir(JPATH_ROOT . '/' . self::FOLDER, 0755, true);
            file_put_contents(JPATH_ROOT . '/' . self::FOLDER . '/index.html', '<!DOCTYPE html><title></title>');
        }

        $path = self::FOLDER . '/' . $name . '.' . $image['ext'];
        file_put_contents(JPATH_ROOT . '/' . $path, $image['data']);

        return ['url' => '/' . $path, 'path' => $path];
    }

    /**
     * The AI image plugin's settings, when it is installed, enabled and its service is set up.
     */
    private function plugin(): ?object
    {
        static $settings = false;

        if ($settings !== false) {
            return $settings;
        }

        $settings = null;
        $params   = ComponentHelper::getParams('com_mcpask');

        if ($params->get('image_source', 'auto') !== 'auto' || !class_exists(self::PLUGIN) || !PluginHelper::isEnabled('system', 'gssaiimage')) {
            return null;
        }

        $row = $this->db->setQuery(
            $this->db->getQuery(true)->select('params')->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
                ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('system'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('gssaiimage'))
        )->loadResult();

        $candidate = new (self::PLUGIN)(new Registry((string) $row));

        if (!\in_array($candidate->defaultProvider(), ['openai', 'openwebui'], true) || !$candidate->providerReady($candidate->defaultProvider())) {
            return null;
        }

        return $settings = $candidate;
    }

    private function byPlugin(string $prompt): string
    {
        $settings = $this->plugin();
        $base     = 'Gss\\Plugin\\System\\GssAiImage\\';
        $http     = new ($base . 'Service\\HttpClient')($settings->timeout());
        $provider = $settings->defaultProvider() === 'openwebui'
            ? new ($base . 'Provider\\OpenWebUiProvider')($settings, $http)
            : new ($base . 'Provider\\OpenAiProvider')($settings, $http);

        return $provider->generate($prompt, ($base . 'Service\\Ratio')::parse('3:2'))->data;
    }

    /**
     * @return  array{provider: string, base: string, key: string, model: string, quality: string}
     */
    private function own(): array
    {
        $params   = ComponentHelper::getParams('com_mcpask');
        $provider = $params->get('image_provider', 'openai') === 'openwebui' ? 'openwebui' : 'openai';

        return [
            'provider' => $provider,
            'base'     => rtrim(trim((string) $params->get('image_base', '')) ?: ($provider === 'openai' ? 'https://api.openai.com/v1' : ''), '/'),
            'key'      => trim((string) $params->get('image_key', '')),
            'model'    => trim((string) $params->get('image_model', '')),
            'quality'  => \in_array($params->get('image_quality'), ['low', 'medium', 'high'], true) ? $params->get('image_quality') : 'medium',
        ];
    }

    private function byOwn(string $prompt): string
    {
        $own     = $this->own();
        $http    = HttpFactory::getHttp();
        $headers = ['Content-Type' => 'application/json'] + ($own['key'] !== '' ? ['Authorization' => 'Bearer ' . $own['key']] : []);

        if ($own['provider'] === 'openwebui') {
            $payload  = ['prompt' => $prompt, 'size' => '1536x1024', 'n' => 1] + ($own['model'] !== '' ? ['model' => $own['model']] : []);
            $response = $http->post($own['base'] . '/api/v1/images/generations', json_encode($payload), $headers, 180);
            $data     = json_decode((string) $response->getBody(), true);
            $url      = (string) ($data[0]['url'] ?? $data['data'][0]['url'] ?? '');

            if ($response->getStatusCode() !== 200 || $url === '') {
                throw new \RuntimeException('Open WebUI made no image.');
            }

            // Only from the server asked: a picture's address on another host is not fetched
            $url = str_starts_with($url, '/') ? $own['base'] . $url : $url;

            if (parse_url($url, PHP_URL_HOST) !== parse_url($own['base'], PHP_URL_HOST)) {
                throw new \RuntimeException('The image is on another server.');
            }

            return (string) $http->get($url, $headers, 120)->getBody();
        }

        $payload  = ['model' => $own['model'] ?: 'gpt-image-1', 'prompt' => $prompt, 'size' => '1536x1024', 'quality' => $own['quality'], 'n' => 1];
        $response = $http->post($own['base'] . '/images/generations', json_encode($payload), $headers, 180);
        $data     = json_decode((string) $response->getBody(), true);
        $binary   = base64_decode((string) ($data['data'][0]['b64_json'] ?? ''), true);

        if ($response->getStatusCode() !== 200 || !$binary) {
            throw new \RuntimeException('OpenAI made no image.');
        }

        return $binary;
    }

    /**
     * As WebP (or JPEG where WebP cannot be written), no wider than WIDTH.
     *
     * @return  array{data: string, ext: string}
     */
    private function encode(string $binary): array
    {
        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            throw new \RuntimeException('Not an image.');
        }

        $width  = imagesx($source);
        $height = imagesy($source);
        $out    = min($width, self::WIDTH);
        $target = imagecreatetruecolor($out, (int) round($height * $out / $width));

        imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);
        ob_start();

        $webp = \function_exists('imagewebp') && imagewebp($target, null, 82);

        if (!$webp) {
            imagejpeg($target, null, 85);
        }

        return ['data' => (string) ob_get_clean(), 'ext' => $webp ? 'webp' : 'jpg'];
    }

    /**
     * One more picture today, when the cap allows it.
     */
    private function count(int $perDay): bool
    {
        if ($perDay < 1) {
            return false;
        }

        $state = new State($this->db);
        $day   = Factory::getDate()->format('Y-m-d');
        $made  = $state->get('report:images');
        $made  = ($made['day'] ?? '') === $day ? (int) ($made['count'] ?? 0) : 0;

        if ($made >= $perDay) {
            return false;
        }

        $state->set('report:images', ['day' => $day, 'count' => $made + 1]);

        return true;
    }
}
