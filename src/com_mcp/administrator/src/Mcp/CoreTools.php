<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Mcp;

use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Version;

\defined('_JEXEC') or die;

/**
 * Tools that are always available, independent of the installed tool plugins.
 *
 * @since  0.1.0
 */
final class CoreTools
{
    /**
     * @return Tool[]
     */
    public static function tools(): array
    {
        return [
            new Tool(
                'site_info',
                'Information about the Joomla site and this connection: site name and URL, Joomla version, languages, time zone, '
                . 'the connected user and their permissions, and the MCP server configuration. Call this first.',
                ['properties' => [], 'additionalProperties' => false],
                [self::class, 'siteInfo'],
                'Site information',
                Tool::SCOPE_READ,
                'core'
            ),
        ];
    }

    public static function siteInfo(array $args, ToolContext $ctx): array
    {
        $app    = $ctx->app;
        $user   = $ctx->user;
        $params = $ctx->params;

        $languages = array_map(
            fn ($l) => ['tag' => $l->lang_code, 'title' => $l->title, 'published' => (int) $l->published === 1],
            array_values(LanguageHelper::getContentLanguages([0, 1]))
        );

        $can = [];

        foreach (['core.create', 'core.edit', 'core.edit.own', 'core.edit.state', 'core.delete'] as $action) {
            $can[substr($action, 5)] = $user->authorise($action, 'com_content');
        }

        return [
            'site' => [
                'name'             => $app->get('sitename'),
                'url'              => $ctx->siteUrl(),
                'joomla_version'   => (new Version())->getShortVersion(),
                'default_language' => \Joomla\CMS\Component\ComponentHelper::getParams('com_languages')->get('site', 'en-GB'),
                'content_languages' => $languages,
                'multilingual'     => \count($languages) > 1,
                'time_zone'        => $app->get('offset', 'UTC'),
                'offline'          => (bool) $app->get('offline'),
            ],
            'user' => [
                'id'          => (int) $user->id,
                'username'    => $user->username,
                'name'        => $user->name,
                'super_user'  => $user->authorise('core.admin'),
                'can_articles' => $can,
            ],
            'server' => [
                'version'             => Server::VERSION,
                'read_only'           => $ctx->isReadOnly(),
                'publishing_allowed'  => !$ctx->isReadOnly() && (bool) $params->get('allow_publish', 1),
                'trashing_allowed'    => !$ctx->isReadOnly() && (bool) $params->get('allow_delete', 0),
                'new_article_state'   => (int) $params->get('new_article_state', 0) === 1 ? 'published' : 'unpublished',
                'max_page_size'       => $ctx->limit(PHP_INT_MAX),
                'limited_to_categories' => $ctx->allowedCategories() ?: null,
                // The option restricts Joomla categories, so it cannot restrict content that is
                // not filed in them; saying so stops the limit being read as a site-wide boundary
                'category_limit_covers' => $ctx->allowedCategories()
                    ? 'Articles and the content that lives in Joomla categories. It does not restrict menus, modules, or blog and directory extensions with their own categories.'
                    : null,
            ],
            'notes' => 'Dates are in the site time zone. Article text is HTML.',
        ];
    }
}
