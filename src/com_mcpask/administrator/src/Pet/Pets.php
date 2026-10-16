<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Pet;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Pets for the floating button (docs/pet-spec.md): animated pictures (APNG, animated AVIF, WebP or
 * GIF) of the site's own, one for each of the button's moments, and a still one for visitors who
 * ask for less motion. A pet is chosen by the module, or by an embed key; one comes with the
 * module ("default").
 *
 * Pictures are only files of the site: images/ (chosen with the media field) or the module's own.
 *
 * @since  0.12.0
 */
final class Pets
{
    /** The moments, and how long each plays when it plays once (0: over and over) */
    public const ACTIONS = [
        'idle'     => 0,
        'hover'    => 1.5,
        'drag'     => 0,
        'thinking' => 0,
        'talking'  => 0,
        'happy'    => 2,
        'confused' => 2,
        'sleep'    => 0,
        'wake'     => 1,
    ];

    public const EXTENSIONS = ['png', 'apng', 'avif', 'webp', 'gif'];

    /** Still pictures (the still one and the square thumbnail) may be JPEG too */
    private const STILL_EXTENSIONS = ['png', 'webp', 'gif', 'avif', 'jpg', 'jpeg'];

    /** Pictures larger than this slow pages down: the back end says so */
    public const ADVISED_BYTES = 1572864;

    /** Nothing larger is used */
    private const MAX_BYTES = 5242880;

    private const BUILTIN = 'media/mod_mcp_ask/pets/default/';

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * Every picture a pet may have, by the name it is uploaded as, with the setting it is kept in:
     * for each moment the whole pet ("idle") and its square thumbnail ("idle_thumb"); the still
     * picture of the whole pet ("static") and its thumbnail ("thumb").
     *
     * @return  array<string, string>
     */
    public static function pictures(): array
    {
        $fields = ['static' => 'static', 'thumb' => 'thumb'];

        foreach (array_keys(self::ACTIONS) as $name) {
            $fields[$name]            = $name . '_image';
            $fields[$name . '_thumb'] = $name . '_thumb';
        }

        return $fields;
    }

    /**
     * Whether a picture is a still one (which may be JPEG too).
     */
    private static function isStill(string $field): bool
    {
        return \in_array($field, ['static', 'thumb'], true);
    }

    /**
     * @return  object[]  With params as Registry
     */
    public function all(): array
    {
        return array_map([self::class, 'prepare'], $this->db->setQuery(
            $this->db->getQuery(true)->select('*')->from($this->db->quoteName('#__mcpask_pets'))->order('title')
        )->loadObjectList());
    }

    public function get(int $id): ?object
    {
        $row = $this->db->setQuery(
            $this->db->getQuery(true)->select('*')->from($this->db->quoteName('#__mcpask_pets'))->where('id = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadObject();

        return $row ? self::prepare($row) : null;
    }

    public function save(array $data): int
    {
        $now    = Factory::getDate()->toSql();
        $id     = (int) ($data['id'] ?? 0);
        $params = (array) ($data['params'] ?? []);

        // Only paths of the site's own pictures are kept
        foreach (self::pictures() as $field) {
            $params[$field] = self::clean((string) ($params[$field] ?? ''));
        }

        $row = (object) [
            'title'    => mb_substr(trim((string) ($data['title'] ?? '')), 0, 255),
            'params'   => json_encode($params, JSON_UNESCAPED_UNICODE),
            'modified' => $now,
        ];

        if ($id > 0 && $this->get($id)) {
            $row->id = $id;
            $this->db->updateObject('#__mcpask_pets', $row, 'id');

            return $id;
        }

        $row->created = $now;
        $this->db->insertObject('#__mcpask_pets', $row, 'id');

        return (int) $row->id;
    }

    public function delete(int $id): void
    {
        $this->db->setQuery($this->db->getQuery(true)->delete($this->db->quoteName('#__mcpask_pets'))->where('id = :id')->bind(':id', $id, ParameterType::INTEGER))->execute();

        // Its pictures go with it
        $folder = JPATH_ROOT . '/' . self::folder($id);

        foreach (glob($folder . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($folder)) {
            rmdir($folder);
        }
    }

    /**
     * Where a pet's uploaded pictures are kept.
     */
    public static function folder(int $id): string
    {
        return 'images/mcpask/pets/' . $id;
    }

    /**
     * Keep an uploaded picture for one of a pet's moments (or its still picture): checked to be the
     * picture it says it is, and named anew, so browsers do not keep showing the old one.
     *
     * @param   string  $field  "static" or a moment's name
     *
     * @return  string  Its path from the site's root
     *
     * @throws  \RuntimeException  What is wrong with it, for the administrator
     */
    public static function store(int $id, string $field, string $temp, string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $still     = self::isStill($field);

        if (!isset(self::pictures()[$field]) || !\in_array($extension, $still ? self::STILL_EXTENSIONS : self::EXTENSIONS, true)) {
            throw new \RuntimeException(Text::sprintf('COM_MCPASK_PET_ERROR_TYPE', $name));
        }

        if (!is_uploaded_file($temp) && !is_file($temp) || filesize($temp) > self::MAX_BYTES) {
            throw new \RuntimeException(Text::sprintf('COM_MCPASK_PET_ERROR_SIZE', $name));
        }

        if (!self::isPicture($temp)) {
            throw new \RuntimeException(Text::sprintf('COM_MCPASK_PET_ERROR_TYPE', $name));
        }

        $folder = JPATH_ROOT . '/' . self::folder($id);

        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
            file_put_contents($folder . '/index.html', '<!DOCTYPE html><title></title>');
        }

        // The picture this one takes the place of
        foreach (glob($folder . '/' . $field . '-*') ?: [] as $old) {
            unlink($old);
        }

        $path = self::folder($id) . '/' . $field . '-' . bin2hex(random_bytes(4)) . '.' . $extension;

        if (!(is_uploaded_file($temp) ? move_uploaded_file($temp, JPATH_ROOT . '/' . $path) : copy($temp, JPATH_ROOT . '/' . $path))) {
            throw new \RuntimeException(Text::sprintf('COM_MCPASK_PET_ERROR_SAVE', $name));
        }

        return $path;
    }

    /**
     * A pet of the library made from the one that comes with the module, to change as wished.
     */
    public function copyBuiltin(string $title): int
    {
        $builtin = self::builtin()->toArray();
        $id      = $this->save(['title' => $title, 'params' => array_intersect_key($builtin, array_flip(['size', 'mobile_size', 'bubble', 'sleep_after']))]);
        $params  = $this->get($id)->params->toArray();

        foreach (self::pictures() as $field => $param) {
            $params[$param] = self::store($id, $field, JPATH_ROOT . '/' . $builtin[$param], basename($builtin[$param]));
        }

        foreach (self::ACTIONS as $name => $length) {
            $params[$name . '_seconds'] = $builtin[$name . '_seconds'];
        }

        $this->save(['id' => $id, 'title' => $title, 'params' => $params]);

        return $id;
    }

    /**
     * The pets that can be chosen, for lists: the module's own and those of the library.
     *
     * @return  array<string, string>  By choice ("default" or the ID)
     */
    public function choices(): array
    {
        $choices = ['default' => Text::_('COM_MCPASK_PET_BUILTIN')];

        foreach ($this->all() as $pet) {
            $choices[(string) $pet->id] = $pet->title;
        }

        return $choices;
    }

    /**
     * What the button needs of a pet: its sizes, words, how soon it sleeps, and the address and
     * length of each moment it has. Null when there is no such pet, or it has no idle picture.
     *
     * @return  array{thumb: string, size: int, mobile: int, bubble: string, sleep: int, still: string, actions: array<string, array{src: string, thumb: string, seconds: float}>}|null
     */
    public function display(string $choice): ?array
    {
        $params = $choice === 'default' ? self::builtin() : (ctype_digit($choice) ? $this->get((int) $choice)?->params : null);

        if (!$params) {
            return null;
        }

        $actions = [];

        foreach (self::ACTIONS as $name => $length) {
            $path = self::clean((string) $params->get($name . '_image', ''));

            if ($path !== '') {
                $seconds        = $length > 0 ? max(0.3, min(10.0, (float) $params->get($name . '_seconds', $length) ?: $length)) : 0;
                $thumb          = self::clean((string) $params->get($name . '_thumb', ''));
                $actions[$name] = ['src' => self::url($path), 'thumb' => $thumb !== '' ? self::url($thumb) : '', 'seconds' => round($seconds, 2)];
            }
        }

        if (!isset($actions['idle'])) {
            return null;
        }

        $still = self::clean((string) $params->get('static', ''));
        $thumb = self::clean((string) $params->get('thumb', ''));

        return [
            'thumb'   => $thumb !== '' ? self::url($thumb) : ($still !== '' ? self::url($still) : ''),
            'size'    => max(48, min(200, (int) $params->get('size', 96))),
            'mobile'  => max(40, min(160, (int) $params->get('mobile_size', 72))),
            'bubble'  => mb_substr(trim((string) $params->get('bubble', '')), 0, 60),
            'sleep'   => max(0, min(3600, (int) $params->get('sleep_after', 60))),
            'still'   => $still !== '' ? self::url($still) : '',
            'actions' => $actions,
        ];
    }

    /**
     * How an agent shows itself (0.12.3): a pet of the library (its moments), or one picture of the
     * site's (an APNG moves too). Null for neither.
     *
     * @return  array{actions: array<string, array{src: string, seconds: float}>, still: string}|null
     */
    public function avatar(string $choice, string $image): ?array
    {
        $path = self::clean($image);

        if ($path !== '') {
            return ['actions' => ['idle' => ['src' => self::url($path), 'thumb' => self::url($path), 'seconds' => 0]], 'still' => '', 'thumb' => ''];
        }

        $pet = $choice !== '' ? $this->display($choice) : null;

        return $pet ? ['actions' => $pet['actions'], 'still' => $pet['still'], 'thumb' => $pet['thumb']] : null;
    }

    /**
     * The size of each picture of a pet, for the back end.
     *
     * @return  array<string, int>  Bytes, by field
     */
    public static function sizes(Registry $params): array
    {
        $sizes = [];

        foreach (self::pictures() as $field) {
            $path = self::clean((string) $params->get($field, ''));

            if ($path !== '') {
                $sizes[$field] = (int) filesize(JPATH_ROOT . '/' . $path);
            }
        }

        return $sizes;
    }

    /**
     * The pet that comes with the module.
     */
    public static function builtin(): Registry
    {
        // Its words are the component's, wherever it is shown
        Factory::getApplication()->getLanguage()->load('com_mcpask', JPATH_ADMINISTRATOR);

        $params = ['size' => 96, 'mobile_size' => 72, 'bubble' => Text::_('COM_MCPASK_PET_BUBBLE_DEFAULT'), 'sleep_after' => 60, 'static' => self::BUILTIN . 'static.png', 'thumb' => self::BUILTIN . 'thumbs/static.png'];

        foreach (self::ACTIONS as $name => $length) {
            $params[$name . '_image']   = self::BUILTIN . $name . '.avif';
            $params[$name . '_thumb']   = self::BUILTIN . 'thumbs/' . $name . '.avif';
            $params[$name . '_seconds'] = $length;
        }

        return new Registry($params);
    }

    /**
     * A picture's path from the site's root, if it may be used: in images/ or the module's own
     * pets, of a picture type, there, and not too large. Otherwise empty.
     */
    public static function clean(string $value): string
    {
        $path = ltrim(str_replace('\\', '/', HTMLHelper::_('cleanImageURL', trim($value))->url ?? ''), '/');
        $path = preg_replace('#^' . preg_quote(rtrim(Uri::root(true), '/') . '/', '#') . '#', '', '/' . $path) ?? '';
        $path = ltrim(rawurldecode((string) strtok($path, '?#')), '/');

        if ($path === '' || str_contains($path, '..') || !preg_match('#^(images/|' . preg_quote(self::BUILTIN, '#') . ')#', $path)) {
            return '';
        }

        if (!\in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::STILL_EXTENSIONS, true) && !\in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            return '';
        }

        $file = JPATH_ROOT . '/' . $path;

        return is_file($file) && filesize($file) <= self::MAX_BYTES && self::isPicture($file) ? $path : '';
    }

    /**
     * Whether the file is the picture its name says (by its first bytes).
     */
    private static function isPicture(string $file): bool
    {
        $head = (string) file_get_contents($file, false, null, 0, 32);

        return str_starts_with($head, "\x89PNG\r\n\x1a\n")
            || str_starts_with($head, "\xFF\xD8\xFF")
            || str_starts_with($head, 'GIF8')
            || (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP')
            || (substr($head, 4, 4) === 'ftyp' && \in_array(substr($head, 8, 4), ['avif', 'avis', 'mif1', 'msf1'], true));
    }

    /**
     * The address of a picture, with when it last changed, so a new one is not mistaken for the old.
     */
    private static function url(string $path): string
    {
        return rtrim(Uri::root(true), '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $path))) . '?' . filemtime(JPATH_ROOT . '/' . $path);
    }

    private static function prepare(object $row): object
    {
        $row->params = new Registry($row->params ?: '{}');

        return $row;
    }
}
