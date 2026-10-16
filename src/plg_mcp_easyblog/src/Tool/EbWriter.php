<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_easyblog
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Easyblog\Tool;

use Gss\Component\Mcp\Administrator\Helper\Resolver;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Writes blog posts through EasyBlog's own post API.
 *
 * EasyBlog keeps the editable copy of a post in a revision (JSON). Writing the post table
 * directly would be overwritten by the next save in the back-end, and would skip categories,
 * tags, permalink handling, the search index and the word counter.
 *
 * @since  0.5.0
 */
final class EbWriter
{
    /** EasyBlog splits the text at this marker into the intro and the rest. */
    private const READMORE = '<hr id="system-readmore" />';

    /**
     * EasyBlog stores the whole text in "content" and splits it at the read more marker, so intro
     * and content have to be composed into one string.
     */
    private static function composeText(array $args, ?object $post = null): ?string
    {
        $hasIntro   = isset($args['intro']);
        $hasContent = isset($args['content']);

        if (!$hasIntro && !$hasContent) {
            return null;
        }

        $intro   = $hasIntro ? $args['intro'] : (string) ($post->intro ?? '');
        $content = $hasContent ? $args['content'] : (string) ($post->content ?? '');

        if (trim($content) === '') {
            return $intro;
        }

        return str_contains($intro, 'system-readmore') ? $intro . $content : $intro . self::READMORE . $content;
    }

    /**
     * Create a post and return its ID.
     */
    public static function create(ToolContext $ctx, array $args): int
    {
        EbEnvironment::boot($ctx);

        $userId = (int) $ctx->user->id;

        if (!self::can($ctx, 'add_entry')) {
            throw new ToolException('You are not allowed to create blog posts in EasyBlog.');
        }

        $state = $args['state'] ?? ((int) $ctx->params->get('new_article_state', 0) === 1 ? 'published' : 'unpublished');

        if ($state === 'published' && !$ctx->params->get('allow_publish', 1)) {
            throw new ToolException('Publishing is disabled on this MCP server; create the post unpublished.');
        }

        if ($state === 'published' && !self::can($ctx, 'publish_entry')) {
            throw new ToolException('You are not allowed to publish blog posts; create it as a draft and let an editor publish it.');
        }

        [$categoryIds, $primary] = self::categories($ctx, $args);

        return self::run(function () use ($ctx, $args, $userId, $state, $categoryIds, $primary) {
            $post = \EB::post(null, $userId);
            $post->create(['overrideDoctType' => 'legacy', 'checkAcl' => false, 'triggerPlugins' => false]);

            $now  = \EB::date()->toSql();
            $data = (object) [
                'created_by'   => $userId,
                'title'        => $args['title'],
                'permalink'    => $args['alias'] ?? '',
                'intro'        => '',
                'content'      => self::composeText($args) ?? '',
                'category_id'  => $primary,
                'categories'   => $categoryIds,
                'tags'         => isset($args['tags']) ? implode(',', array_map('strval', (array) $args['tags'])) : '',
                'published'    => EbData::PUBLISHED[$state],
                'state'        => EbData::STATE['normal'],
                'created'      => $now,
                'publish_up'   => !empty($args['publish_up']) ? Resolver::sqlDate($ctx, $args['publish_up']) : $now,
                'publish_down' => !empty($args['publish_down']) ? Resolver::sqlDate($ctx, $args['publish_down']) : EASYBLOG_NO_DATE,
                'autopost_date' => EASYBLOG_NO_DATE,
                'language'     => $args['language'] ?? '*',
                'access'       => 0,
                'posttype'     => '',
                'doctype'      => 'legacy',
                'source_type'  => EASYBLOG_POST_SOURCE_SITEWIDE,
                'source_id'    => 0,
                'frontpage'    => 1,
                'keywords'     => $args['metakey'] ?? '',
                'description'  => $args['metadesc'] ?? '',
            ];

            $post->bind($data, ['force' => true]);
            $post->save(EbEnvironment::saveOptions());

            $id = (int) $post->id;

            if (!empty($args['featured'])) {
                self::setFeatured($ctx, [$id], true);
            }

            return $id;
        });
    }

    /**
     * Update an existing post. Only the given properties change.
     */
    public static function update(ToolContext $ctx, int $id, array $args): void
    {
        EbEnvironment::boot($ctx);

        $post   = EbData::post($ctx, $id);
        $userId = (int) $ctx->user->id;

        self::assertCanEdit($ctx, $post);

        $changesText = isset($args['intro']) || isset($args['content']);

        if ($changesText && $post->doctype === 'ebd') {
            throw new ToolException(\sprintf(
                'Post %d was written with the EasyBlog composer, where the text is stored as blocks. MCP cannot rewrite it safely; edit the text in the back-end. Other properties (title, categories, tags, state) can be changed.',
                $id
            ));
        }

        $categories = isset($args['categories']) || isset($args['category']);
        [$categoryIds, $primary] = $categories ? self::categories($ctx, $args, $post) : [[], 0];

        $text = self::composeText($args, $post);

        self::run(function () use ($ctx, $args, $id, $userId, $categories, $categoryIds, $primary, $changesText, $text) {
            $post  = \EB::post($id, $userId);
            $patch = new \stdClass();

            foreach (['title' => 'title', 'alias' => 'permalink', 'language' => 'language'] as $arg => $property) {
                if (isset($args[$arg])) {
                    $patch->$property = $args[$arg];
                }
            }

            if ($text !== null) {
                // The whole text goes into "content"; EasyBlog splits off the intro itself
                $patch->content = $text;
                $patch->intro   = '';
            }

            foreach (['metakey' => 'keywords', 'metadesc' => 'description'] as $arg => $property) {
                if (isset($args[$arg])) {
                    $patch->$property = $args[$arg];
                }
            }

            foreach (['publish_up', 'publish_down'] as $key) {
                if (\array_key_exists($key, $args)) {
                    $patch->$key = empty($args[$key]) ? EASYBLOG_NO_DATE : Resolver::sqlDate($ctx, $args[$key]);
                }
            }

            if ($categories) {
                $patch->category_id = $primary;
                $patch->categories  = $categoryIds;
            }

            if (isset($args['tags'])) {
                $patch->tags = implode(',', array_map('strval', (array) $args['tags']));
            }

            $post->bind($patch);

            $post->save(EbEnvironment::saveOptions([
                // Normalising splits the text at the read more marker; only wanted when it changed
                'normalizeData'         => $changesText,
                'updateModifiedTime'    => true,
                'skipCategoriesUpdate'  => !$categories,
                'skipTagsUpdate'        => !isset($args['tags']),
                'skipCustomFields'      => true,
                'skipAssociationUpdate' => true,
            ]));

            return null;
        });

        if (\array_key_exists('featured', $args)) {
            self::setFeatured($ctx, [$id], (bool) $args['featured']);
        }
    }

    /**
     * Change the state of posts, and/or their featured flag.
     *
     * @param  int[]  $ids
     */
    public static function setState(ToolContext $ctx, array $ids, ?string $state, ?bool $featured): array
    {
        EbEnvironment::boot($ctx);

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            throw new ToolException('No post IDs given.');
        }

        if ($state === 'published' && !self::can($ctx, 'publish_entry')) {
            throw new ToolException('You are not allowed to publish blog posts.');
        }

        $userId = (int) $ctx->user->id;

        foreach ($ids as $id) {
            self::assertCanEdit($ctx, EbData::post($ctx, $id));
        }

        if ($state !== null) {
            self::run(function () use ($ids, $userId, $state) {
                foreach ($ids as $id) {
                    $post = \EB::post($id, $userId);

                    if ($state === 'trashed') {
                        $post->state        = EbData::STATE['trashed'];
                        $post->publish_down = EASYBLOG_NO_DATE;
                    } elseif ($state === 'archived') {
                        $post->state = EbData::STATE['archived'];
                    } else {
                        $post->state     = EbData::STATE['normal'];
                        $post->published = EbData::PUBLISHED[$state];
                    }

                    $post->save(EbEnvironment::saveOptions([
                        'normalizeData'         => false,
                        'skipCategoriesUpdate'  => true,
                        'skipTagsUpdate'        => true,
                        'skipCustomFields'      => true,
                        'skipAssociationUpdate' => true,
                        'triggerStateChangeOnly' => true,
                    ]));
                }

                return null;
            });
        }

        if ($featured !== null) {
            self::setFeatured($ctx, $ids, $featured);
        }

        $db   = $ctx->db;
        $rows = $db->setQuery($db->getQuery(true)->select('id, title, published, state')->from($db->quoteName('#__easyblog_post'))->whereIn('id', $ids))->loadObjectList();
        $feat = EbData::featured($ctx, $ids);

        return array_map(fn ($r) => [
            'id'       => (int) $r->id,
            'title'    => $r->title,
            'state'    => EbData::stateName($r),
            'featured' => \in_array((int) $r->id, $feat, true),
        ], $rows);
    }

    // ------------------------------------------------------------------ Helpers

    /**
     * @param  int[]  $ids
     */
    private static function setFeatured(ToolContext $ctx, array $ids, bool $featured): void
    {
        if ($featured && !self::can($ctx, 'feature_entry')) {
            throw new ToolException('You are not allowed to feature blog posts.');
        }

        self::run(function () use ($ids, $featured) {
            $model = \EB::model('Featured');

            foreach ($ids as $id) {
                $featured ? $model->makeFeatured('post', $id) : $model->removeFeatured('post', $id);
            }

            return null;
        });
    }

    /**
     * Category IDs to store, and the primary one.
     *
     * @return array{0: int[], 1: int}
     */
    private static function categories(ToolContext $ctx, array $args, ?object $post = null): array
    {
        $ids = [];

        if (isset($args['category'])) {
            $primary = EbData::category($ctx, $args['category'])->id;
        } elseif ($post) {
            $primary = (int) $post->category_id;
        } else {
            throw new ToolException('Pass "category" (the main blog category).');
        }

        $ids[] = $primary;

        foreach ((array) ($args['categories'] ?? []) as $ref) {
            $ids[] = EbData::category($ctx, $ref)->id;
        }

        return [array_values(array_unique($ids)), $primary];
    }

    private static function assertCanEdit(ToolContext $ctx, object $post): void
    {
        $user = $ctx->user;

        if ($user->authorise('core.admin') || self::can($ctx, 'moderate_entry')) {
            return;
        }

        if ((int) $post->created_by === (int) $user->id && self::can($ctx, 'edit_entry')) {
            return;
        }

        throw new ToolException(\sprintf('You are not allowed to edit blog post %d.', (int) $post->id));
    }

    /**
     * Check an EasyBlog permission for the connected user.
     */
    private static function can(ToolContext $ctx, string $action): bool
    {
        EbEnvironment::boot($ctx);

        if ($ctx->user->authorise('core.admin')) {
            return true;
        }

        try {
            return (bool) \EB::acl((int) $ctx->user->id)->get($action);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Run EasyBlog code and turn its exceptions into tool errors.
     */
    private static function run(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            throw new ToolException('EasyBlog could not save the post: ' . $e->getMessage());
        }
    }
}
