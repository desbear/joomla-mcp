<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_media
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Media\Tool;

use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;

\defined('_JEXEC') or die;

/**
 * The media folders, read through com_media's own API.
 *
 * Everything goes through com_media's ApiModel and its filesystem adapters, so the paths it will
 * touch, the extensions it will accept and the real MIME check are Joomla's, configured in the
 * Media options. Nothing here reaches the filesystem directly.
 *
 * Two policies are added on top, because an assistant is not the Media Manager:
 *
 * 1. What may be written is tiered. Anyone who may upload at all may upload images; only a Super
 *    User may upload the extra types an administrator has listed. The floor below is absolute and
 *    no option can lower it, because the model that calls these tools reads text written by site
 *    visitors and may be told to save something.
 * 2. Where it may look is tiered. A non-Super-User sees the image folder only, so a "files"
 *    adapter holding documents that are not meant to be public stays out of reach.
 *
 * @since  0.9.2
 */
final class MediaData
{
    /**
     * Images, and nothing that a browser will execute. This is the whole of what a non-Super-User
     * may upload, and the ceiling for everyone.
     */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /**
     * Never written, whatever the options say, for either tier.
     *
     * SVG is an image to a designer and a script host to a browser: it is XML and carries
     * <script>. The others are markup or code that the web server may hand to an interpreter.
     * A Super User who really needs one of these has the Media Manager; a tool driven by a model
     * reading visitor text should not be the way they arrive.
     */
    public const NEVER = [
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'shtml', 'xml', 'xsl', 'xslt', 'js', 'mjs', 'json',
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar', 'pht',
        'htaccess', 'htpasswd', 'ini', 'sh', 'bash', 'zsh', 'cgi', 'pl', 'py', 'rb', 'exe', 'dll', 'so',
    ];

    /** The provider the local filesystem plugin registers. */
    public const LOCAL = 'local';

    /** Largest upload this plugin will accept, before com_media's own upload_maxsize applies. */
    public const MAX_UPLOAD_BYTES = 8388608;

    /** Longest listing one call will return. */
    public const MAX_FILES = 500;

    public static function isAvailable(): bool
    {
        static $available = null;

        if ($available === null) {
            $available = ComponentHelper::isInstalled('com_media') && ComponentHelper::isEnabled('com_media');
        }

        return $available;
    }

    /**
     * com_media's API model, booted the way the component's own controllers boot it.
     */
    public static function api(): object
    {
        static $model = null;

        if ($model === null) {
            $model = Factory::getApplication()
                ->bootComponent('com_media')
                ->getMVCFactory()
                ->createModel('Api', 'Administrator');
        }

        return $model;
    }

    /**
     * One of com_media's filesystem adapters, by its full name ("local-images").
     *
     * Reading goes through the adapter rather than through ApiModel on purpose. ApiModel::getFiles()
     * silently drops every file whose extension is not in the "mediatypes" of the *request*, which
     * defaults to images: a folder holding a PDF or a stray .txt would look empty of them, and these
     * tools are meant to show what is actually there. The adapter still refuses to leave its own
     * root, so nothing is given up by going one level down.
     */
    public static function adapterObject(string $name): object
    {
        return self::api()->getAdapter($name);
    }

    /**
     * Let com_media see every type it is configured for, not just images.
     *
     * ApiModel decides what counts as a media file from the "mediatypes" request variable, which a
     * browser sends and an API client does not, and its default is images only. Without this a
     * Super User could not upload one of the extra types an administrator had allowed. The upload
     * is still gated by allowedExtensions() above, which is stricter.
     */
    public static function widenMediaTypes(ToolContext $ctx): void
    {
        $ctx->app->getInput()->set('mediatypes', '0,1,2,3');
    }

    /**
     * Whether the caller may read the media folders at all: the same permission that opens the
     * Media Manager in the back end.
     */
    public static function canRead(ToolContext $ctx): bool
    {
        return $ctx->user->authorise('core.manage', 'com_media');
    }

    /**
     * Whether the caller may upload. Needs the component switch, the Media Manager's own create
     * permission, and a server that is not read-only.
     */
    public static function canUpload(ToolContext $ctx): bool
    {
        return !$ctx->isReadOnly()
            && (bool) $ctx->params->get('allow_upload', 0)
            && $ctx->user->authorise('core.create', 'com_media');
    }

    public static function isSuperUser(ToolContext $ctx): bool
    {
        return (bool) $ctx->user->authorise('core.admin');
    }

    /**
     * The extensions this caller may upload, lower-cased.
     *
     * Images for everyone, plus the configured extra types for a Super User, then intersected with
     * what com_media itself allows and with the absolute deny list. The result can only ever be
     * narrower than the Media Manager would accept.
     *
     * @return string[]
     */
    public static function allowedExtensions(ToolContext $ctx): array
    {
        $allowed = self::IMAGE_EXTENSIONS;

        if (self::isSuperUser($ctx)) {
            $extra = array_filter(array_map(
                static fn ($e) => strtolower(trim((string) $e, " \t\n\r\0\x0B.")),
                explode(',', (string) $ctx->params->get('upload_extra_extensions', ''))
            ));

            $allowed = array_merge($allowed, $extra);
        }

        // What the Media options themselves permit: never widen past the Media Manager
        $mediaParams = ComponentHelper::getParams('com_media');
        $restricted  = array_filter(array_map(
            'strtolower',
            array_map('trim', explode(',', (string) $mediaParams->get('restrict_uploads_extensions', '')))
        ));

        if ($restricted) {
            $allowed = array_intersect($allowed, $restricted);
        }

        return array_values(array_diff(array_unique($allowed), self::NEVER));
    }

    /**
     * The adapters this caller may touch, as com_media names them ("local-images").
     *
     * @return string[]
     */
    public static function allowedAdapters(ToolContext $ctx): array
    {
        $names = [];

        foreach (self::adapterNames() as $name) {
            // A non-Super-User only ever sees the image folder
            if (!self::isSuperUser($ctx) && $name !== self::LOCAL . '-' . self::imageFolder()) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * Every adapter com_media has registered.
     *
     * @return string[]
     */
    public static function adapterNames(): array
    {
        $names = [];

        try {
            $providers = self::api()->getProviderManager()->getProviders();
        } catch (\Throwable) {
            return [self::LOCAL . '-' . self::imageFolder()];
        }

        foreach ($providers as $provider) {
            foreach ($provider->getAdapters() as $account => $adapter) {
                $names[] = $provider->getID() . '-' . $account;
            }
        }

        return $names;
    }

    /**
     * The folder com_media treats as the image root, "images" on a default site.
     */
    public static function imageFolder(): string
    {
        return trim((string) ComponentHelper::getParams('com_media')->get('file_path', 'images'), '/') ?: 'images';
    }

    /**
     * Resolve and authorise the adapter for a call.
     */
    public static function adapter(ToolContext $ctx, ?string $requested): string
    {
        $allowed = self::allowedAdapters($ctx);

        if (!$allowed) {
            throw new ToolException('No media folder is available to this user.');
        }

        if ($requested === null || $requested === '') {
            return $allowed[0];
        }

        // Accept a bare folder name as well as the full adapter name
        $name = str_contains($requested, '-') ? $requested : self::LOCAL . '-' . trim($requested, '/');

        if (!\in_array($name, $allowed, true)) {
            throw new ToolException(\sprintf(
                'Not an available media folder: "%s". This user may use: %s.',
                $requested,
                implode(', ', $allowed)
            ));
        }

        return $name;
    }

    /**
     * A path inside an adapter, normalised. Traversal is rejected here as well as by com_media,
     * so a bad path never reaches the filesystem layer at all.
     */
    public static function path(?string $path): string
    {
        $path = self::stripAdapter(trim((string) $path));
        $path = str_replace('\\', '/', $path);

        if ($path === '' || $path === '/') {
            return '/';
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw new ToolException('A media path may not contain "..".');
            }
        }

        if (preg_match('/[\x00-\x1F]/', $path)) {
            throw new ToolException('A media path may not contain control characters.');
        }

        return '/' . ltrim($path, '/');
    }

    /**
     * Drop the adapter prefix com_media puts on the paths it returns, so a path handed straight
     * back to one of these tools ("local-images:/banners") works as well as a plain "/banners".
     */
    public static function stripAdapter(string $path): string
    {
        // Only a leading "<name>:" prefix, never a colon elsewhere in the path
        return preg_replace('#^[A-Za-z0-9_.-]+:#', '', $path) ?? $path;
    }

    /**
     * The path to use in article HTML, relative to the site root: "images/banners/sale.jpg".
     *
     * This is what the Media Manager itself inserts and what Joomla's image fields store, and it
     * is derived from the adapter's own folder rather than from a URL, so it is always right.
     */
    public static function relativePath(string $adapter, string $path): string
    {
        // "local-images" -> "images"
        $folder = str_contains($adapter, '-') ? substr($adapter, strpos($adapter, '-') + 1) : $adapter;

        return trim($folder, '/') . '/' . ltrim(self::stripAdapter($path), '/');
    }

    /**
     * The site's own root, with a trailing slash, or null when it cannot be established.
     *
     * Uri::root() is not usable here: under an API request to /api/index.php/v1/mcp it returns
     * "https://site/index.php/v1/mcp/", because the API application's base carries the route. The
     * configured live site is trusted first, otherwise the root is derived from the entry point.
     */
    public static function siteRoot(ToolContext $ctx): ?string
    {
        $live = trim((string) $ctx->app->get('live_site', ''));

        if ($live !== '') {
            return rtrim($live, '/') . '/';
        }

        $host = $ctx->app->getInput()->server->getString('HTTP_HOST', '');

        if ($host === '') {
            return null;
        }

        $https  = $ctx->app->getInput()->server->getString('HTTPS', '');
        $scheme = ($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';
        $script = $ctx->app->getInput()->server->getString('SCRIPT_NAME', '');
        $base   = rtrim(str_replace('\\', '/', \dirname($script)), '/');

        // The entry point lives in api/ or administrator/; the site root is their parent
        foreach (['/api', '/administrator'] as $suffix) {
            if (str_ends_with($base, $suffix)) {
                $base = substr($base, 0, -\strlen($suffix));

                break;
            }
        }

        return $scheme . '://' . $host . rtrim($base, '/') . '/';
    }

    /**
     * A file name that is safe to create

    /**
     * A file name that is safe to create: Joomla's own filter, then our own extension policy.
     *
     * The name the model supplied is never used as given. A name that collides is not overwritten;
     * the caller is told instead, because silently replacing a file in use is worse than failing.
     */
    public static function safeName(ToolContext $ctx, string $name, array $allowedExtensions): string
    {
        $name = InputFilter::getInstance()->clean(basename(str_replace('\\', '/', $name)), 'string');
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?? '';
        $name = trim(preg_replace('/-+/', '-', $name) ?? '', '-.');

        if ($name === '' || !str_contains($name, '.')) {
            throw new ToolException('Give a file name with an extension, for example "photo.jpg".');
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base      = pathinfo($name, PATHINFO_FILENAME);

        // A name like "report.php.jpg" must not pass on its last segment alone
        foreach (explode('.', strtolower($base)) as $segment) {
            if (\in_array($segment, self::NEVER, true)) {
                throw new ToolException(\sprintf('The file name may not contain ".%s".', $segment));
            }
        }

        if (!\in_array($extension, $allowedExtensions, true)) {
            throw new ToolException(\sprintf(
                'This user may not upload ".%s" files. Allowed here: %s.%s',
                $extension,
                implode(', ', $allowedExtensions) ?: 'nothing',
                self::isSuperUser($ctx) ? '' : ' Only a Super User may upload anything other than an image.'
            ));
        }

        if (mb_strlen($base) > 100) {
            $base = mb_substr($base, 0, 100);
        }

        return ($base !== '' ? $base : 'file') . '.' . $extension;
    }

    /**
     * Decode the supplied file content.
     */
    public static function decode(string $content): string
    {
        // Tolerate a data: URI, which is how a model is most likely to hand over an image
        if (preg_match('#^data:[^;,]*;base64,#i', $content)) {
            $content = substr($content, strpos($content, ',') + 1);
        }

        $decoded = base64_decode(str_replace(["\n", "\r", ' '], '', $content), true);

        if ($decoded === false || $decoded === '') {
            throw new ToolException('"content_base64" is not valid base64 data.');
        }

        if (\strlen($decoded) > self::MAX_UPLOAD_BYTES) {
            throw new ToolException(\sprintf(
                'The file is %s; this tool accepts at most %s.',
                self::readableSize(\strlen($decoded)),
                self::readableSize(self::MAX_UPLOAD_BYTES)
            ));
        }

        return $decoded;
    }

    /**
     * The real type of the decoded bytes, so a mislabelled file is refused before com_media is
     * asked to write it. com_media checks this too; failing here keeps it off the disk entirely,
     * since its own check writes the file first and validates afterwards.
     */
    public static function assertRealType(string $bytes, string $extension): void
    {
        $info = @getimagesizefromstring($bytes);

        if (\in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            if ($info === false) {
                throw new ToolException('The content is not a readable image.');
            }

            $expected = [
                'jpg'  => [IMAGETYPE_JPEG],
                'jpeg' => [IMAGETYPE_JPEG],
                'png'  => [IMAGETYPE_PNG],
                'gif'  => [IMAGETYPE_GIF],
                'webp' => [IMAGETYPE_WEBP],
                'avif' => \defined('IMAGETYPE_AVIF') ? [\constant('IMAGETYPE_AVIF')] : [],
            ][$extension] ?? [];

            if ($expected && !\in_array($info[2], $expected, true)) {
                throw new ToolException(\sprintf(
                    'The content is a %s, not a %s. Name the file with the extension that matches its content.',
                    image_type_to_extension($info[2], false) ?: 'different image type',
                    $extension
                ));
            }

            return;
        }

        // A non-image type a Super User allowed: refuse anything carrying a script or markup,
        // whatever it is called
        $head = strtolower(substr($bytes, 0, 512));

        foreach (['<?php', '<?=', '<script', '<!doctype html', '<html', '<svg'] as $marker) {
            if (str_contains($head, $marker)) {
                throw new ToolException('The content looks like markup or code, which this tool does not upload.');
            }
        }
    }

    public static function readableSize(int $bytes): string
    {
        foreach ([['GB', 1073741824], ['MB', 1048576], ['KB', 1024]] as [$unit, $step]) {
            if ($bytes >= $step) {
                return round($bytes / $step, $bytes >= 10 * $step ? 0 : 1) . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }

    /**
     * One entry as it is reported.
     */
    /**
     * @param  array{src: string, url: string|null}  $location  From MediaTools::location()
     */
    public static function describe(object $item, array $location = ['src' => '', 'url' => null]): array
    {
        $isFile = ($item->type ?? '') === 'file';

        return array_filter([
            'name'      => $item->name ?? '',
            'path'      => self::stripAdapter((string) ($item->path ?? '')),
            'type'      => $item->type ?? '',
            'extension' => $isFile ? strtolower((string) ($item->extension ?? '')) : null,
            'size'      => $isFile && !empty($item->size) ? (int) $item->size : null,
            'size_human' => $isFile && !empty($item->size) ? self::readableSize((int) $item->size) : null,
            'width'     => !empty($item->width) ? (int) $item->width : null,
            'height'    => !empty($item->height) ? (int) $item->height : null,
            'modified'  => $item->modified_date ?? null,
            // What to put in article HTML, and the absolute form for convenience
            'src'       => $isFile ? ($location['src'] ?: null) : null,
            'url'       => $isFile ? ($location['url'] ?? null) : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
