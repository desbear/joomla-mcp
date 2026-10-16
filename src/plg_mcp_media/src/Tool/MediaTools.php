<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_media
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Media\Tool;

use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;

\defined('_JEXEC') or die;

/**
 * Tools for walking the media folders and adding an image to them.
 *
 * Reading needs the Media Manager permission. Uploading needs a switch in the MCP options that is
 * off by default, and even then only a Super User may upload anything that is not an image.
 *
 * @since  0.9.2
 */
final class MediaTools
{
    public static function isAvailable(): bool
    {
        return MediaData::isAvailable();
    }

    /**
     * @return Tool[]
     */
    public static function tools(): array
    {
        $canRead   = [MediaData::class, 'canRead'];
        $canUpload = [MediaData::class, 'canUpload'];

        return [
            new Tool(
                'list_media',
                'List what is in a media folder: the sub-folders and the files, with each file\'s size, pixel dimensions and public URL. '
                . 'Use "path" to walk into a folder, or "search" with "recursive" to find a file anywhere below it. '
                . 'Call this to get the URL of an image that is already on the site, for use in article HTML.',
                [
                    'properties' => [
                        'path'      => ['type' => 'string', 'description' => 'Folder inside the media root, e.g. "/banners". Default the root.'],
                        'folder'    => ['type' => 'string', 'description' => 'Which media folder, when the site has more than one (see media_overview). Default the image folder.'],
                        'search'    => ['type' => 'string', 'description' => 'Part of a file name to look for.'],
                        'recursive' => ['type' => 'boolean', 'description' => 'Search below the folder as well. Only with "search".'],
                        'only'      => ['type' => 'string', 'enum' => ['files', 'folders', 'images', 'other'], 'description' => '"other" means files that are not images.'],
                        'order_by'  => ['type' => 'string', 'enum' => ['name', 'size', 'modified'], 'description' => 'Default name.'],
                        'direction' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'Default asc.'],
                        'limit'     => ['type' => 'integer', 'description' => 'Default 100.', 'minimum' => 1],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'listMedia'],
                'List media files',
                Tool::SCOPE_READ,
                'media',
                true,
                $canRead
            ),
            new Tool(
                'media_overview',
                'A survey of the media folders: which folders exist, how many files and how much space in each, the mix of file '
                . 'types, the largest files, the most recently changed ones, and anything worth a second look such as files that '
                . 'are not images or are unusually large. Call this first to see the shape of the folder before listing it.',
                [
                    'properties' => [
                        'path'   => ['type' => 'string', 'description' => 'Survey this folder and below instead of the whole root.'],
                        'folder' => ['type' => 'string', 'description' => 'Which media folder. Default the image folder.'],
                        'depth'  => ['type' => 'integer', 'description' => 'How many folder levels to break down separately. Default 2.', 'minimum' => 1, 'maximum' => 5],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'overview'],
                'Media folder survey',
                Tool::SCOPE_READ,
                'media',
                true,
                $canRead
            ),
            new Tool(
                'upload_media',
                'Upload a file to a media folder and return its public URL, for use in article HTML. '
                . 'Pass the bytes as base64 in "content_base64". The content type is verified against the file name, and the '
                . 'file is never written over an existing one. Images may be uploaded by anyone allowed to upload at all; '
                . 'other types only by a Super User, and only those an administrator has listed. '
                . 'Call media_upload_policy if a file is refused and you need to know what is accepted.',
                [
                    'properties' => [
                        'name'           => ['type' => 'string', 'description' => 'File name with its extension, e.g. "spring-sale.jpg". It is sanitised before use.'],
                        'content_base64' => ['type' => 'string', 'description' => 'The file content, base64 encoded. A data: URI is accepted.'],
                        'path'           => ['type' => 'string', 'description' => 'Folder to put it in, e.g. "/banners". Default the root. The folder must already exist.'],
                        'folder'         => ['type' => 'string', 'description' => 'Which media folder. Default the image folder.'],
                    ],
                    'required'             => ['name', 'content_base64'],
                    'additionalProperties' => false,
                ],
                [self::class, 'upload'],
                'Upload a media file',
                Tool::SCOPE_WRITE,
                'media',
                false,
                $canUpload
            ),
            new Tool(
                'media_upload_policy',
                'What this user may upload and where: the extensions allowed, the size limit, the folders reachable, and whether '
                . 'uploading is switched on at all. Read-only; call it to explain a refusal rather than retrying an upload.',
                ['properties' => [], 'additionalProperties' => false],
                [self::class, 'policy'],
                'Upload policy',
                Tool::SCOPE_READ,
                'media',
                true,
                $canRead
            ),
        ];
    }

    /**
     * Fetch a folder's entries through com_media.
     *
     * @return object[]
     */
    /**
     * The reliable location of a file: the site-relative path always, and an absolute URL when the
     * site root can be established. com_media's own url is not used (see MediaData::siteRoot).
     *
     * @return array{src: string, url: string|null}
     */
    private static function location(ToolContext $ctx, string $adapter, object $item): array
    {
        if (($item->type ?? '') !== 'file') {
            return ['src' => '', 'url' => null];
        }

        $src  = MediaData::relativePath($adapter, (string) ($item->path ?? ''));
        $root = MediaData::siteRoot($ctx);

        return ['src' => $src, 'url' => $root !== null ? $root . $src : null];
    }

    private static function fetch(ToolContext $ctx, string $adapter, string $path, array $args = []): array
    {
        try {
            $filesystem = MediaData::adapterObject($adapter);
            $search     = (string) ($args['search'] ?? '');

            return $search !== ''
                ? (array) $filesystem->search($path, $search, !empty($args['recursive']))
                : (array) $filesystem->getFiles($path);
        } catch (\Throwable $e) {
            throw new ToolException(self::explain($e, $path));
        }
    }

    /**
     * Turn com_media's exceptions into something a caller can act on.
     */
    private static function explain(\Throwable $e, string $path): string
    {
        $class = (new \ReflectionClass($e))->getShortName();

        return match ($class) {
            'FileNotFoundException'          => \sprintf('There is nothing at "%s" in this media folder.', $path),
            'InvalidPathException'            => \sprintf(
                '"%s" is not a path this media folder allows, or its file type is not one the Media options list.',
                $path
            ),
            'FileExistsException'             => 'A file of that name is already there. Choose another name.',
            'ProviderAccountNotFoundException',
            'ProviderAccountIsEmptyException' => 'That media folder is not configured on this site.',
            default                           => 'The Media Manager refused the request: ' . $e->getMessage(),
        };
    }

    public static function listMedia(array $args, ToolContext $ctx): array
    {
        $adapter = MediaData::adapter($ctx, $args['folder'] ?? null);
        $path    = MediaData::path($args['path'] ?? null);

        if (!empty($args['recursive']) && ($args['search'] ?? '') === '') {
            throw new ToolException('"recursive" only applies together with "search". Use "path" to walk into a folder.');
        }

        $entries = self::fetch($ctx, $adapter, $path, $args);
        $only    = $args['only'] ?? null;
        $images  = MediaData::IMAGE_EXTENSIONS;

        $entries = array_values(array_filter($entries, static function ($item) use ($only, $images) {
            $isFile    = ($item->type ?? '') === 'file';
            $extension = strtolower((string) ($item->extension ?? ''));

            return match ($only) {
                'files'   => $isFile,
                'folders' => !$isFile,
                'images'  => $isFile && \in_array($extension, $images, true),
                'other'   => $isFile && !\in_array($extension, $images, true),
                default   => true,
            };
        }));

        $direction = strtolower($args['direction'] ?? 'asc') === 'desc' ? -1 : 1;
        $orderBy   = $args['order_by'] ?? 'name';

        usort($entries, static function ($a, $b) use ($orderBy, $direction) {
            // Folders stay at the top whichever way the files are sorted
            $aFolder = ($a->type ?? '') !== 'file';
            $bFolder = ($b->type ?? '') !== 'file';

            if ($aFolder !== $bFolder) {
                return $aFolder ? -1 : 1;
            }

            return $direction * match ($orderBy) {
                'size'     => (int) ($a->size ?? 0) <=> (int) ($b->size ?? 0),
                'modified' => strcmp((string) ($a->modified_date ?? ''), (string) ($b->modified_date ?? '')),
                default    => strcasecmp((string) ($a->name ?? ''), (string) ($b->name ?? '')),
            };
        });

        $total = \count($entries);
        $limit = min($ctx->limit($args['limit'] ?? null, 100), MediaData::MAX_FILES);
        $shown = \array_slice($entries, 0, $limit);

        return array_filter([
            'folder'    => $adapter,
            'path'      => $path,
            'total'     => $total,
            'shown'     => \count($shown),
            'has_more'  => $total > \count($shown),
            'entries'   => array_map(
                static fn ($item) => MediaData::describe($item, self::location($ctx, $adapter, $item)),
                $shown
            ),
            'note'      => $total > \count($shown)
                ? 'Narrow the listing with "search", "only" or "path" rather than raising "limit".'
                : null,
        ], static fn ($v) => $v !== null);
    }

    public static function overview(array $args, ToolContext $ctx): array
    {
        $adapter = MediaData::adapter($ctx, $args['folder'] ?? null);
        $root    = MediaData::path($args['path'] ?? null);
        $depth   = max(1, min((int) ($args['depth'] ?? 2), 5));

        $files   = [];
        $folders = [];

        self::walk($ctx, $adapter, $root, 0, $depth + 2, $files, $folders);

        $images     = MediaData::IMAGE_EXTENSIONS;
        $byType     = [];
        $byFolder   = [];
        $totalBytes = 0;
        $notImages  = [];

        foreach ($files as $file) {
            $extension = strtolower((string) ($file->extension ?? '')) ?: '(none)';
            $size      = (int) ($file->size ?? 0);
            $totalBytes += $size;

            $byType[$extension] ??= ['files' => 0, 'bytes' => 0];
            $byType[$extension]['files']++;
            $byType[$extension]['bytes'] += $size;

            // Group into the folder at the requested depth. The root is removed as a prefix, not
            // with str_replace: when the root is "/" that would strip every slash in the path.
            $full     = MediaData::stripAdapter((string) ($file->path ?? ''));
            $prefix   = rtrim($root, '/');
            $relative = ltrim($prefix !== '' && str_starts_with($full, $prefix) ? substr($full, \strlen($prefix)) : $full, '/');
            // The bucket is the folder, so the file's own name is dropped before slicing
            $parts    = \array_slice(explode('/', $relative), 0, -1);
            $bucket   = $parts ? implode('/', \array_slice($parts, 0, $depth)) : '(root)';

            $byFolder[$bucket] ??= ['files' => 0, 'bytes' => 0];
            $byFolder[$bucket]['files']++;
            $byFolder[$bucket]['bytes'] += $size;

            if (!\in_array($extension, $images, true)) {
                $notImages[] = $file;
            }
        }

        $format = static function (array $map) {
            $out = [];

            foreach ($map as $key => $row) {
                $out[] = [
                    'key'        => $key,
                    'files'      => $row['files'],
                    'size_human' => MediaData::readableSize($row['bytes']),
                    'bytes'      => $row['bytes'],
                ];
            }

            usort($out, static fn ($a, $b) => $b['bytes'] <=> $a['bytes']);

            return $out;
        };

        $largest = $files;
        usort($largest, static fn ($a, $b) => (int) ($b->size ?? 0) <=> (int) ($a->size ?? 0));

        $recent = $files;
        usort($recent, static fn ($a, $b) => strcmp((string) ($b->modified_date ?? ''), (string) ($a->modified_date ?? '')));

        // Anything an administrator would want to look at twice
        $attention = [];

        if ($notImages) {
            $attention[] = \sprintf(
                '%d of the %d files are not images (%s). Use list_media with only="other" to see them.',
                \count($notImages),
                \count($files),
                implode(', ', \array_slice(array_unique(array_map(
                    static fn ($f) => '.' . strtolower((string) ($f->extension ?? '?')),
                    $notImages
                )), 0, 8))
            );
        }

        $big = array_values(array_filter($files, static fn ($f) => (int) ($f->size ?? 0) > 2097152));

        if ($big) {
            $attention[] = \sprintf(
                '%d file(s) are over 2 MB, the largest %s. Large images slow pages down.',
                \count($big),
                MediaData::readableSize((int) ($largest[0]->size ?? 0))
            );
        }

        return array_filter([
            'folder'          => $adapter,
            'path'            => $root,
            'folders_available' => \count(MediaData::allowedAdapters($ctx)) > 1 ? MediaData::allowedAdapters($ctx) : null,
            'folders'         => \count($folders),
            'files'           => \count($files),
            'total_size_human' => MediaData::readableSize($totalBytes),
            'by_type'         => $format($byType),
            'by_folder'       => \count($byFolder) > 1 ? \array_slice($format($byFolder), 0, 25) : null,
            'largest_files'   => array_map(
                static fn ($f) => MediaData::describe($f, self::location($ctx, $adapter, $f)),
                \array_slice($largest, 0, 10)
            ),
            'recently_changed' => array_map(
                static fn ($f) => MediaData::describe($f, self::location($ctx, $adapter, $f)),
                \array_slice($recent, 0, 10)
            ),
            'worth_a_look'    => $attention ?: null,
            'truncated'       => \count($files) >= MediaData::MAX_FILES
                ? 'The survey stopped at ' . MediaData::MAX_FILES . ' files. Narrow it with "path".'
                : null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * Walk a folder tree, collecting files and folder paths.
     *
     * @param  object[]  $files    Collected files, by reference
     * @param  string[]  $folders  Collected folder paths, by reference
     */
    private static function walk(ToolContext $ctx, string $adapter, string $path, int $level, int $maxLevel, array &$files, array &$folders): void
    {
        if ($level > $maxLevel || \count($files) >= MediaData::MAX_FILES) {
            return;
        }

        foreach (self::fetch($ctx, $adapter, $path) as $item) {
            if (\count($files) >= MediaData::MAX_FILES) {
                return;
            }

            if (($item->type ?? '') === 'file') {
                $files[] = $item;

                continue;
            }

            $folders[] = $item->path ?? '';

            // com_media returns paths with the adapter prefixed ("local-images:/banners"),
            // which is not the form it accepts back
            self::walk($ctx, $adapter, MediaData::stripAdapter((string) ($item->path ?? '')), $level + 1, $maxLevel, $files, $folders);
        }
    }

    public static function upload(array $args, ToolContext $ctx): array
    {
        $adapter   = MediaData::adapter($ctx, $args['folder'] ?? null);
        $path      = MediaData::path($args['path'] ?? null);
        $allowed   = MediaData::allowedExtensions($ctx);

        // Otherwise com_media judges the type by a request variable only a browser sends
        MediaData::widenMediaTypes($ctx);

        if (!$allowed) {
            throw new ToolException('No file type is allowed for upload on this site. Check "restrict_uploads_extensions" in the Media options.');
        }

        // Joomla's File::write() creates missing parent folders, so an upload to a mistyped path
        // would quietly build a folder tree. The folder is required to exist instead.
        self::assertFolderExists($ctx, $adapter, $path);

        $name      = MediaData::safeName($ctx, (string) $args['name'], $allowed);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $bytes     = MediaData::decode((string) $args['content_base64']);

        // Refuse a mislabelled file before com_media writes it: its own check writes first and
        // validates afterwards, so this keeps bad content off the disk entirely
        MediaData::assertRealType($bytes, $extension);

        try {
            // Never override: replacing a file that pages already load is not something to do
            // on a model's say-so
            $created = MediaData::api()->createFile($adapter, $name, $path, $bytes, false);
        } catch (\Throwable $e) {
            throw new ToolException(self::explain($e, rtrim($path, '/') . '/' . $name));
        }

        $stored = \is_string($created) && $created !== '' ? $created : $name;
        $full   = rtrim($path, '/') . '/' . $stored;

        $src  = MediaData::relativePath($adapter, $full);
        $root = MediaData::siteRoot($ctx);
        $url  = $root !== null ? $root . $src : null;
        $info = @getimagesizefromstring($bytes);

        return array_filter([
            'uploaded'   => true,
            'name'       => $stored,
            'renamed'    => $stored !== $args['name'] ? 'The name was sanitised from "' . $args['name'] . '".' : null,
            'folder'     => $adapter,
            'path'       => $full,
            'src'        => $src,
            'url'        => $url,
            'size_human' => MediaData::readableSize(\strlen($bytes)),
            'width'      => $info ? (int) $info[0] : null,
            'height'     => $info ? (int) $info[1] : null,
            // Relative, which is what the Media Manager inserts and what survives a domain change
            'html'       => $info
                ? \sprintf('<img src="%s" width="%d" height="%d" alt="">', htmlspecialchars($src, ENT_QUOTES), $info[0], $info[1])
                : null,
            'next'       => 'Put "src" in the article HTML with update_article, and write a real "alt" description.',
        ], static fn ($v) => $v !== null);
    }

    private static function assertFolderExists(ToolContext $ctx, string $adapter, string $path): void
    {
        if ($path === '/') {
            return;
        }

        try {
            $entry = MediaData::adapterObject($adapter)->getFile($path);
        } catch (\Throwable) {
            throw new ToolException(\sprintf(
                'There is no folder "%s" in this media folder. Create it in the Media Manager first, or leave "path" out to upload to the root.',
                $path
            ));
        }

        if (($entry->type ?? '') === 'file') {
            throw new ToolException(\sprintf('"%s" is a file, not a folder.', $path));
        }
    }

    public static function policy(array $args, ToolContext $ctx): array
    {
        $superUser = MediaData::isSuperUser($ctx);
        $allowed   = MediaData::allowedExtensions($ctx);
        $enabled   = (bool) $ctx->params->get('allow_upload', 0);

        $why = [];

        if ($ctx->isReadOnly()) {
            $why[] = 'The MCP server is in read-only mode.';
        } elseif (!$enabled) {
            $why[] = 'Uploading is switched off in the MCP options ("Allow uploads").';
        } elseif (!$ctx->user->authorise('core.create', 'com_media')) {
            $why[] = 'This user does not have the Media Manager\'s Create permission.';
        }

        return array_filter([
            'uploading_allowed'   => MediaData::canUpload($ctx),
            'why_not'             => $why ?: null,
            'is_super_user'       => $superUser,
            'allowed_extensions'  => $allowed,
            'extensions_note'     => $superUser
                ? 'As a Super User this includes the extra types an administrator listed in the MCP options.'
                : 'Images only. Only a Super User may upload other types, and only those an administrator has listed.',
            'never_allowed'       => 'SVG, HTML, XML, JavaScript and anything executable are refused for everyone, whatever the options say.',
            'max_size_human'      => MediaData::readableSize(MediaData::MAX_UPLOAD_BYTES),
            'folders_available'   => MediaData::allowedAdapters($ctx),
            'folders_note'        => $superUser
                ? null
                : 'Only the image folder is reachable. A Super User can also reach the other media folders.',
        ], static fn ($v) => $v !== null);
    }
}
