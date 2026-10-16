<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_menus
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Menus\Tool;

use Gss\Component\Mcp\Administrator\Helper\ModelHelper;
use Gss\Component\Mcp\Administrator\Helper\Resolver;
use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Menu and menu item tools.
 *
 * Writes go through the core com_menus models (nested set handling, save events, action log).
 * The back-end edit form is not used because it depends on session state; the link, type and
 * component are built here from a simple target description instead.
 *
 * @since  0.3.0
 */
final class MenuTools
{
    public const STATES = ['published' => 1, 'unpublished' => 0, 'trashed' => -2];

    /** Target types a menu item can point to. */
    private const TARGETS = ['article', 'category_blog', 'category_list', 'featured', 'tag', 'url', 'alias', 'heading', 'separator', 'component'];

    /** Commonly used menu item parameters. */
    private const PARAM_HINT = 'Menu item parameters to merge, e.g. {"menu_show": 0} to hide from the menu, "menu-anchor_title", "menu-anchor_css", '
        . '"menu_image", "page_title", "show_page_heading", "page_heading", "menu-meta_description", "robots". Empty values use the global defaults.';

    /** Most menu items one bulk call may touch: each one is a full model save. */
    public const MAX_BULK = 100;

    /**
     * @return Tool[]
     */
    public static function tools(): array
    {
        $ref    = ['type' => ['integer', 'string']];
        $target = [
            'type'   => ['type' => 'string', 'enum' => self::TARGETS, 'description' => 'What the menu item opens. article: one article; category_blog / category_list: a category; '
                . 'featured: featured articles; tag: articles with tags; url: an external or internal URL; alias: another menu item; '
                . 'heading / separator: no link; component: any Joomla link (index.php?option=...).'],
            'article'  => $ref + ['description' => 'For type article: article ID or exact title.'],
            'category' => $ref + ['description' => 'For category_blog / category_list: category ID, title or path.'],
            'tags'     => ['type' => 'array', 'items' => $ref, 'description' => 'For type tag: tag IDs, titles or paths.'],
            'url'      => ['type' => 'string', 'description' => 'For type url: the URL. For type component: the link, e.g. index.php?option=com_contact&view=contact&id=1.'],
            'alias_of' => $ref + ['description' => 'For type alias: the menu item to point to (ID, title or path).'],
        ];
        $common = [
            'alias'              => ['type' => 'string', 'description' => 'URL segment; generated from the title when empty.'],
            'parent'             => $ref + ['description' => 'Parent menu item (ID, title or path). Default: top level.'],
            'position'           => ['type' => 'string', 'description' => 'Position among its siblings: "first", "last", or "after:<menu item ID>". Default: last.'],
            'language'           => ['type' => 'string', 'description' => 'Language tag, or "*" for all languages (default).'],
            'access'             => ['type' => 'integer', 'description' => 'Access level ID (1 = Public).'],
            'open_in_new_window' => ['type' => 'boolean'],
            'note'               => ['type' => 'string', 'description' => 'Internal note.'],
            'params'             => ['type' => 'object', 'description' => self::PARAM_HINT],
        ];

        $can = fn (string $action) => fn (ToolContext $c) => $c->user->authorise($action, 'com_menus');

        return [
            new Tool(
                'list_menus',
                'List the menus of the site (or the administrator) with their number of items and the modules that display them.',
                [
                    'properties'           => ['client' => ['type' => 'string', 'enum' => ['site', 'administrator'], 'description' => 'Default site.']],
                    'additionalProperties' => false,
                ],
                [self::class, 'listMenus'],
                'List menus',
                Tool::SCOPE_READ,
                'menus',
                false,
                $can('core.manage')
            ),
            new Tool(
                'list_menu_items',
                'List menu items in tree order with what each item links to.',
                [
                    'properties' => [
                        'menu'      => ['type' => 'string', 'description' => 'Menu name or title (see list_menus). Default: all site menus.'],
                        'parent'    => $ref + ['description' => 'Only this item and its descendants.'],
                        'max_level' => ['type' => 'integer', 'description' => 'Maximum depth (1 = top level only).'],
                        'state'     => ['type' => 'string', 'enum' => ['published', 'unpublished', 'trashed', 'any'], 'description' => 'Default: published and unpublished.'],
                        'query'     => ['type' => 'string', 'description' => 'Filter by title, alias or link.'],
                        'language'  => ['type' => 'string'],
                        'limit'     => ['type' => 'integer', 'description' => 'Default 100.'],
                        'offset'    => ['type' => 'integer'],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'listItems'],
                'List menu items',
                Tool::SCOPE_READ,
                'menus',
                false,
                $can('core.manage')
            ),
            new Tool(
                'get_menu_item',
                'Get one menu item with its target, parameters, parent, children count and the modules shown on its page.',
                [
                    'properties' => ['id' => $ref + ['description' => 'Menu item ID, title or path.']],
                    'required'   => ['id'],
                ],
                [self::class, 'getItem'],
                'Get menu item',
                Tool::SCOPE_READ,
                'menus',
                false,
                $can('core.manage')
            ),
            new Tool(
                'create_menu',
                'Create a new menu. To show it on the site, create a "Menu" module (mod_menu) for it with create_module.',
                [
                    'properties' => [
                        'title'       => ['type' => 'string'],
                        'menutype'    => ['type' => 'string', 'description' => 'Unique system name (a-z, 0-9, -). Generated from the title when empty.'],
                        'description' => ['type' => 'string'],
                    ],
                    'required'             => ['title'],
                    'additionalProperties' => false,
                ],
                [self::class, 'createMenu'],
                'Create menu',
                Tool::SCOPE_WRITE,
                'menus',
                false,
                $can('core.create')
            ),
            new Tool(
                'create_menu_item',
                'Create a menu item. Unless "state" is given the configured default state for new content is used (normally unpublished).',
                [
                    'properties' => [
                        'menu'  => ['type' => 'string', 'description' => 'Menu name or title (see list_menus).'],
                        'title' => ['type' => 'string'],
                        'state' => ['type' => 'string', 'enum' => ['published', 'unpublished']],
                    ] + $target + $common,
                    'required'             => ['menu', 'title', 'type'],
                    'additionalProperties' => false,
                ],
                [self::class, 'createItem'],
                'Create menu item',
                Tool::SCOPE_WRITE,
                'menus',
                false,
                $can('core.create')
            ),
            new Tool(
                'update_menu_item',
                'Change a menu item: title, target, parent, position, menu, parameters and more. Only the given properties change; '
                . 'pass "type" together with its target to change what it links to. Use set_menu_item_state to publish or unpublish.',
                [
                    'properties' => [
                        'id'    => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'menu'  => ['type' => 'string', 'description' => 'Move to another menu (the item goes to the top level unless "parent" is given).'],
                    ] + $target + $common,
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
                [self::class, 'updateItem'],
                'Update menu item',
                Tool::SCOPE_WRITE,
                'menus',
                true,
                $can('core.edit')
            ),
            new Tool(
                'set_menu_item_state',
                'Publish or unpublish menu items.',
                [
                    'properties' => [
                        'ids'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => self::MAX_BULK],
                        'state' => ['type' => 'string', 'enum' => ['published', 'unpublished']],
                    ],
                    'required'             => ['ids', 'state'],
                    'additionalProperties' => false,
                ],
                [self::class, 'setState'],
                'Publish menu items',
                Tool::SCOPE_PUBLISH,
                'menus',
                true,
                $can('core.edit.state')
            ),
            new Tool(
                'trash_menu_items',
                'Move menu items to the trash (restorable in the back-end). The home page item cannot be trashed.',
                [
                    'properties'           => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => self::MAX_BULK]],
                    'required'             => ['ids'],
                    'additionalProperties' => false,
                ],
                [self::class, 'trash'],
                'Trash menu items',
                Tool::SCOPE_DELETE,
                'menus',
                true,
                $can('core.edit.state')
            ),
        ];
    }

    // ------------------------------------------------------------------ Read

    public static function listMenus(array $args, ToolContext $ctx): array
    {
        $db       = $ctx->db;
        $clientId = ($args['client'] ?? 'site') === 'administrator' ? 1 : 0;

        $count = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__menu', 'm'))
            ->where('m.menutype = t.menutype')
            ->where('m.published IN (0, 1)');

        $query = $db->getQuery(true)
            ->select('t.id, t.menutype, t.title, t.description')
            ->select('(' . $count . ') AS item_count')
            ->from($db->quoteName('#__menu_types', 't'))
            ->where('t.client_id = ' . $clientId)
            ->order('t.id');

        $menus = $db->setQuery($query)->loadObjectList();

        // Modules that display each menu (mod_menu stores the menu in its "menutype" parameter)
        $query = $db->getQuery(true)
            ->select('id, title, position, published, params')
            ->from($db->quoteName('#__modules'))
            ->where('module = ' . $db->quote('mod_menu'))
            ->where('client_id = ' . $clientId)
            ->where('published IN (0, 1)');

        $modules = [];

        foreach ($db->setQuery($query)->loadObjectList() as $module) {
            $menutype = (new Registry($module->params))->get('menutype');
            $modules[$menutype][] = ['id' => (int) $module->id, 'title' => $module->title, 'position' => $module->position, 'published' => (int) $module->published === 1];
        }

        return ['menus' => array_map(fn ($m) => [
            'id'          => (int) $m->id,
            'menutype'    => $m->menutype,
            'title'       => $m->title,
            'description' => $m->description,
            'item_count'  => (int) $m->item_count,
            'modules'     => $modules[$m->menutype] ?? [],
        ], $menus)];
    }

    public static function listItems(array $args, ToolContext $ctx): array
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->from($db->quoteName('#__menu', 'a'))
            ->where('a.id > 1');

        if (!empty($args['menu'])) {
            $menu = Resolver::menu($ctx, $args['menu']);
            $query->where('a.menutype = :menutype')->bind(':menutype', $menu->menutype);
        } else {
            $query->where('a.client_id = 0');
        }

        if (isset($args['parent'])) {
            $parent = Resolver::menuItem($ctx, $args['parent'], isset($menu) ? $menu->menutype : null);
            $query->join('INNER', $db->quoteName('#__menu', 'p'), 'a.lft >= p.lft AND a.rgt <= p.rgt')
                ->where('p.id = ' . $parent->id);
        }

        if (!empty($args['max_level'])) {
            $query->where('a.level <= ' . (int) $args['max_level']);
        }

        $state = $args['state'] ?? null;
        $query->where($state === null ? 'a.published IN (0, 1)' : ($state === 'any' ? 'a.published IN (-2, 0, 1)' : 'a.published = ' . self::STATES[$state]));

        if (($args['query'] ?? '') !== '') {
            $search = '%' . Resolver::likeValue(mb_strtolower($args['query'])) . '%';
            $query->where('(LOWER(a.title) LIKE :s1 OR LOWER(a.alias) LIKE :s2 OR LOWER(a.link) LIKE :s3)')
                ->bind(':s1', $search)
                ->bind(':s2', $search)
                ->bind(':s3', $search);
        }

        if (!empty($args['language'])) {
            $query->where('a.language = :language')->bind(':language', $args['language']);
        }

        $total  = (int) $db->setQuery((clone $query)->select('COUNT(*)'))->loadResult();
        $limit  = $ctx->limit($args['limit'] ?? null, 100);
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $query->select('a.id, a.menutype, a.title, a.alias, a.path, a.link, a.type, a.published, a.parent_id, a.level, a.home, a.language, a.access, a.params, a.note')
            ->order('a.menutype, a.lft')
            ->setLimit($limit, $offset);

        $items = array_map(fn ($row) => self::summary($ctx, $row), $db->setQuery($query)->loadObjectList());

        return ['total' => $total, 'offset' => $offset, 'has_more' => $offset + \count($items) < $total, 'items' => $items];
    }

    public static function getItem(array $args, ToolContext $ctx): array
    {
        $item  = Resolver::menuItem($ctx, $args['id']);
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('a.*, v.title AS access_title, p.title AS parent_title')
            ->from($db->quoteName('#__menu', 'a'))
            ->join('LEFT', $db->quoteName('#__viewlevels', 'v'), 'v.id = a.access')
            ->join('LEFT', $db->quoteName('#__menu', 'p'), 'p.id = a.parent_id')
            ->where('a.id = ' . $item->id);

        $row = $db->setQuery($query)->loadObject();

        $children = (int) $db->setQuery(
            $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__menu'))->where('parent_id = ' . $item->id)->where('published <> -2')
        )->loadResult();

        // Modules shown on this page: assigned to all pages, to this item, or to all pages except others
        $query = $db->getQuery(true)
            ->select('DISTINCT m.id, m.title, m.module, m.position')
            ->from($db->quoteName('#__modules', 'm'))
            ->join('INNER', $db->quoteName('#__modules_menu', 'mm'), 'mm.moduleid = m.id')
            ->where('m.published = 1')
            ->where('m.client_id = ' . (int) $row->client_id)
            ->where('(mm.menuid = 0 OR mm.menuid = ' . $item->id . ' OR (mm.menuid < 0 AND NOT EXISTS (SELECT 1 FROM '
                . $db->quoteName('#__modules_menu') . ' x WHERE x.moduleid = m.id AND x.menuid = ' . -$item->id . ')))')
            ->order('m.position, m.ordering');

        $modules = array_map(fn ($m) => ['id' => (int) $m->id, 'title' => $m->title, 'module' => $m->module, 'position' => $m->position], $db->setQuery($query)->loadObjectList());

        return self::summary($ctx, $row) + [
            'parent'   => $row->parent_id > 1 ? ['id' => (int) $row->parent_id, 'title' => $row->parent_title] : null,
            'children' => $children,
            'access'   => ['id' => (int) $row->access, 'title' => $row->access_title],
            'params'   => array_filter(json_decode((string) $row->params, true) ?: [], fn ($v) => $v !== '' && $v !== null),
            'publish_up'   => Resolver::localDate($ctx, $row->publish_up),
            'publish_down' => Resolver::localDate($ctx, $row->publish_down),
            'modules_on_page' => $modules,
        ];
    }

    // ------------------------------------------------------------------ Write

    public static function createMenu(array $args, ToolContext $ctx): array
    {
        $model = ModelHelper::admin($ctx, 'com_menus', 'Menu');
        $data  = [
            'id'          => 0,
            'title'       => $args['title'],
            'menutype'    => $args['menutype'] ?? $args['title'],
            'description' => $args['description'] ?? '',
            'client_id'   => 0,
        ];

        if (!$model->save($data)) {
            throw new ToolException('Could not create the menu: ' . self::error($model));
        }

        $menu = Resolver::menu($ctx, (int) $model->getState('menu.id'));

        return ['result' => 'created', 'menu' => ['id' => $menu->id, 'menutype' => $menu->menutype, 'title' => $menu->title]];
    }

    public static function createItem(array $args, ToolContext $ctx): array
    {
        $menu = Resolver::menu($ctx, $args['menu']);

        self::authorise($ctx, 'core.create', $menu);

        $state = isset($args['state']) ? self::STATES[$args['state']] : (int) $ctx->params->get('new_article_state', 0);

        if ($state === 1 && !$ctx->params->get('allow_publish', 1)) {
            throw new ToolException('Publishing is disabled on this MCP server; create the menu item unpublished.');
        }

        $data = [
            'id'           => 0,
            'menutype'     => $menu->menutype,
            'title'        => $args['title'],
            'alias'        => $args['alias'] ?? '',
            'parent_id'    => 1,
            'published'    => $state,
            'language'     => $args['language'] ?? '*',
            'access'       => (int) ($args['access'] ?? 1),
            'browserNav'   => 0,
            'params'       => [],
            'note'         => $args['note'] ?? '',
            'menuordering' => 0,
        ];

        $data = self::applyTarget($ctx, $data, $args);
        $data = self::applyCommon($ctx, $data, $args, $menu);

        $id = self::save($ctx, $data);

        // New items are appended; move them afterwards if another position was requested
        if (!empty($args['position']) && $args['position'] !== 'last') {
            $data               = self::load($ctx, $id);
            $data['menuordering'] = self::ordering($ctx, $args['position'], $data);
            self::save($ctx, $data);
        }

        return ['result' => 'created'] + self::getItem(['id' => $id], $ctx);
    }

    public static function updateItem(array $args, ToolContext $ctx): array
    {
        $id   = (int) $args['id'];
        $data = self::load($ctx, $id);
        $menu = Resolver::menu($ctx, $data['menutype']);

        self::authorise($ctx, 'core.edit', $menu);

        if (!empty($args['menu'])) {
            $target = Resolver::menu($ctx, $args['menu']);

            if ($target->menutype !== $menu->menutype) {
                self::authorise($ctx, 'core.create', $target);

                if ($target->client_id !== $menu->client_id) {
                    throw new ToolException('Menu items cannot be moved between site and administrator menus.');
                }

                $menu              = $target;
                $data['menutype']  = $target->menutype;
                $data['parent_id'] = 1;
            }
        }

        if (isset($args['title'])) {
            $data['title'] = $args['title'];
        }

        if (isset($args['type'])) {
            $data = self::applyTarget($ctx, $data, $args);
        } elseif (array_intersect_key($args, array_flip(['article', 'category', 'tags', 'url', 'alias_of']))) {
            throw new ToolException('Pass "type" together with the new target.');
        }

        $data = self::applyCommon($ctx, $data, $args, $menu);

        self::save($ctx, $data);

        return ['result' => 'updated'] + self::getItem(['id' => $id], $ctx);
    }

    public static function setState(array $args, ToolContext $ctx): array
    {
        if ($args['state'] === 'published' && !$ctx->params->get('allow_publish', 1)) {
            throw new ToolException('Publishing is disabled on this MCP server.');
        }

        return self::publish($ctx, $args['ids'], self::STATES[$args['state']]);
    }

    public static function trash(array $args, ToolContext $ctx): array
    {
        return self::publish($ctx, $args['ids'], -2);
    }

    // ------------------------------------------------------------------ Helpers

    /**
     * Set type, link, component and target parameters from the tool arguments.
     */
    private static function applyTarget(ToolContext $ctx, array $data, array $args): array
    {
        $type = $args['type'] ?? 'component';
        $need = fn (string $key) => $args[$key] ?? throw new ToolException(\sprintf('Type "%s" needs "%s".', $type, $key));

        $data['params'] = (array) ($data['params'] ?? []);
        unset($data['params']['aliasoptions']);

        switch ($type) {
            case 'article':
                $article = Resolver::article($ctx, $need('article'));

                return self::componentLink($ctx, $data, 'index.php?option=com_content&view=article&id=' . $article->id);

            case 'category_blog':
            case 'category_list':
                $category = Resolver::category($ctx, $need('category'));
                $layout   = $type === 'category_blog' ? '&layout=blog' : '';

                return self::componentLink($ctx, $data, 'index.php?option=com_content&view=category' . $layout . '&id=' . $category->id);

            case 'featured':
                return self::componentLink($ctx, $data, 'index.php?option=com_content&view=featured');

            case 'tag':
                $ids  = Resolver::tags($ctx, (array) $need('tags'));
                $link = 'index.php?option=com_tags&view=tag';

                foreach (array_values($ids) as $i => $id) {
                    $link .= '&id[' . $i . ']=' . (int) $id;
                }

                return self::componentLink($ctx, $data, $link);

            case 'component':
                $link = trim((string) $need('url'));

                if (!preg_match('/^index\.php\?option=(com_[a-z0-9_]+)/', $link)) {
                    throw new ToolException('A component link must start with index.php?option=com_…');
                }

                return self::componentLink($ctx, $data, $link);

            case 'url':
                // The value is stored verbatim and rendered as an href, and browsers drop control
                // characters and whitespace before they resolve the scheme ("java\nscript:" runs),
                // so strip those first and then accept only schemes that are safe in a link
                $url = (string) preg_replace('/[\x00-\x20\x7f]/', '', (string) $need('url'));

                if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $url, $scheme)
                    && !\in_array(strtolower($scheme[1]), ['http', 'https', 'mailto', 'tel'], true)) {
                    throw new ToolException('Only site-relative URLs and the http, https, mailto and tel schemes are allowed.');
                }

                return ['type' => 'url', 'link' => $url, 'component_id' => 0] + $data;

            case 'alias':
                $target = Resolver::menuItem($ctx, $need('alias_of'));

                if (!empty($data['id']) && $target->id === (int) $data['id']) {
                    throw new ToolException('A menu item cannot be an alias of itself.');
                }

                $data['params']['aliasoptions'] = $target->id;

                return ['type' => 'alias', 'link' => 'index.php?Itemid=', 'component_id' => 0] + $data;

            case 'heading':
            case 'separator':
                return ['type' => $type, 'link' => '', 'component_id' => 0] + $data;
        }

        throw new ToolException('Unknown menu item type.');
    }

    private static function componentLink(ToolContext $ctx, array $data, string $link): array
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $db     = $ctx->db;
        $option = (string) ($query['option'] ?? '');
        $id     = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('extension_id')
                ->from($db->quoteName('#__extensions'))
                ->where('type = ' . $db->quote('component'))
                ->where('element = :option')
                ->where('enabled = 1')
                ->bind(':option', $option)
        )->loadResult();

        if (!$id) {
            throw new ToolException(\sprintf('Component "%s" is not installed or not enabled.', $option));
        }

        return ['type' => 'component', 'link' => $link, 'component_id' => $id] + $data;
    }

    private static function applyCommon(ToolContext $ctx, array $data, array $args, object $menu): array
    {
        foreach (['alias', 'language', 'note'] as $key) {
            if (isset($args[$key])) {
                $data[$key] = $args[$key];
            }
        }

        if (isset($args['access'])) {
            $data['access'] = (int) $args['access'];
        }

        if (isset($args['open_in_new_window'])) {
            $data['browserNav'] = $args['open_in_new_window'] ? 1 : 0;
        }

        if (isset($args['params'])) {
            $data['params'] = array_merge((array) ($data['params'] ?? []), (array) $args['params']);
        }

        if (isset($args['parent'])) {
            $parent = Resolver::menuItem($ctx, $args['parent'], $menu->menutype);

            if ($parent->menutype !== $menu->menutype) {
                throw new ToolException('The parent must be in the same menu.');
            }

            if (!empty($data['id']) && ($parent->id === (int) $data['id'] || self::isDescendant($ctx, $parent->id, (int) $data['id']))) {
                throw new ToolException('A menu item cannot be moved below itself.');
            }

            $data['parent_id'] = $parent->id;
        }

        $data['menuordering'] = 0;

        if (!empty($args['position']) && !empty($data['id'])) {
            $data['menuordering'] = self::ordering($ctx, $args['position'], $data);
        }

        return $data;
    }

    /**
     * Translate "first" / "last" / "after:ID" into the model's menuordering value.
     */
    private static function ordering(ToolContext $ctx, string $position, array $data): int
    {
        if ($position === 'first') {
            return -1;
        }

        if ($position === 'last') {
            return -2;
        }

        if (preg_match('/^after:(\d+)$/', $position, $m)) {
            $sibling = Resolver::menuItem($ctx, (int) $m[1]);

            if ($sibling->parent_id !== (int) $data['parent_id'] || $sibling->menutype !== $data['menutype']) {
                throw new ToolException(\sprintf('Menu item %d is not a sibling (same parent and menu).', $sibling->id));
            }

            return $sibling->id;
        }

        throw new ToolException('position must be "first", "last" or "after:<menu item ID>".');
    }

    private static function isDescendant(ToolContext $ctx, int $candidate, int $ancestor): bool
    {
        $db = $ctx->db;

        return (bool) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__menu', 'c'))
                ->join('INNER', $db->quoteName('#__menu', 'a'), 'c.lft > a.lft AND c.rgt < a.rgt')
                ->where('c.id = ' . $candidate)
                ->where('a.id = ' . $ancestor)
        )->loadResult();
    }

    private static function load(ToolContext $ctx, int $id): array
    {
        $db  = $ctx->db;
        $row = $db->setQuery($db->getQuery(true)->select('*')->from($db->quoteName('#__menu'))->where('id = :id')->bind(':id', $id, ParameterType::INTEGER))->loadAssoc();

        if (!$row || $id <= 1) {
            throw new ToolException(\sprintf('Menu item %d not found.', $id));
        }

        $row['params']       = json_decode((string) $row['params'], true) ?: [];
        $row['menuordering'] = 0;

        return $row;
    }

    private static function save(ToolContext $ctx, array $data): int
    {
        // The model's save events expect the request to describe the menu item being saved
        $model = ModelHelper::admin($ctx, 'com_menus', 'Item', [
            'id'       => (int) $data['id'],
            'menutype' => $data['menutype'],
            'type'     => $data['type'],
            'link'     => $data['link'],
        ]);

        if (!$model->save($data)) {
            throw new ToolException('Save failed: ' . self::error($model));
        }

        return (int) $model->getState('item.id');
    }

    /**
     * @param  int[]  $ids
     */
    private static function publish(ToolContext $ctx, array $ids, int $state): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 1)));

        if (!$ids) {
            throw new ToolException('No menu item IDs given.');
        }

        // The model refuses items the user may not change, but it reports that through the message
        // queue and returns false without setting an error, so checking getError() would report a
        // refusal as success. Authorise up front instead, on the asset core itself checks.
        foreach ($ids as $id) {
            self::authorise($ctx, 'core.edit.state', Resolver::menu($ctx, Resolver::menuItem($ctx, $id)->menutype));
        }

        // Publish parents before their children (Joomla refuses children of unpublished parents)
        $db    = $ctx->db;
        $ids   = array_map('intval', $db->setQuery(
            $db->getQuery(true)->select('id')->from($db->quoteName('#__menu'))->whereIn('id', $ids)->order($state === 1 ? 'lft ASC' : 'lft DESC')
        )->loadColumn());
        $model = ModelHelper::admin($ctx, 'com_menus', 'Item');

        if (!$model->publish($ids, $state)) {
            $error = self::error($model);

            if (str_contains($error, 'ancestors have lower state')) {
                throw new ToolException('A menu item can only be published when its parent items are published. Publish the parent first (see "parent" in get_menu_item), or publish parent and child in one call, parent first.');
            }

            throw new ToolException('Could not change the state' . ($error !== '' ? ': ' . $error : '. The site refused the change.'));
        }

        $db   = $ctx->db;
        $rows = $db->setQuery($db->getQuery(true)->select('id, title, published, home')->from($db->quoteName('#__menu'))->whereIn('id', $ids))->loadObjectList();

        return ['results' => array_map(fn ($r) => [
            'id'    => (int) $r->id,
            'title' => $r->title,
            'state' => array_search((int) $r->published, self::STATES, true) ?: (string) $r->published,
            'home'  => (bool) $r->home,
        ], $rows)];
    }

    private static function authorise(ToolContext $ctx, string $action, object $menu): void
    {
        // The per-asset check already inherits the component rules, so a component-level fallback
        // would only make an explicit Deny on this menu unreachable
        if (!$ctx->user->authorise($action, 'com_menus.menu.' . $menu->id)) {
            throw new ToolException(\sprintf('You are not allowed to do this in menu "%s".', $menu->title));
        }
    }

    /**
     * Summary row with a readable description of the target.
     */
    private static function summary(ToolContext $ctx, object $row): array
    {
        return [
            'id'        => (int) $row->id,
            'menu'      => $row->menutype,
            'title'     => $row->title,
            'alias'     => $row->alias,
            'path'      => $row->path,
            'level'     => (int) $row->level,
            'parent_id' => (int) $row->parent_id,
            'state'     => array_search((int) $row->published, self::STATES, true) ?: (string) $row->published,
            'home'      => (bool) $row->home,
            'language'  => $row->language,
            'target'    => self::describeTarget($ctx, $row),
            'note'      => $row->note ?: null,
        ];
    }

    private static function describeTarget(ToolContext $ctx, object $row): array
    {
        if ($row->type === 'url') {
            return ['type' => 'url', 'url' => $row->link];
        }

        if ($row->type === 'alias') {
            $params = new Registry($row->params);

            return ['type' => 'alias', 'alias_of' => (int) $params->get('aliasoptions')];
        }

        if ($row->type !== 'component') {
            return ['type' => $row->type];
        }

        parse_str((string) parse_url($row->link, PHP_URL_QUERY), $q);

        $db     = $ctx->db;
        $option = $q['option'] ?? '';
        $view   = $q['view'] ?? '';
        $id     = $q['id'] ?? null;
        $title  = fn (string $table, int $id) => $db->setQuery(
            $db->getQuery(true)->select('title')->from($db->quoteName($table))->where('id = ' . $id)
        )->loadResult();

        if ($option === 'com_content' && $view === 'article' && $id) {
            return ['type' => 'article', 'article' => ['id' => (int) $id, 'title' => $title('#__content', (int) $id)]];
        }

        if ($option === 'com_content' && $view === 'category' && $id) {
            return [
                'type'     => ($q['layout'] ?? '') === 'blog' ? 'category_blog' : 'category_list',
                'category' => ['id' => (int) $id, 'title' => $title('#__categories', (int) $id)],
            ];
        }

        if ($option === 'com_content' && $view === 'featured') {
            return ['type' => 'featured'];
        }

        if ($option === 'com_tags' && $view === 'tag' && $id) {
            return ['type' => 'tag', 'tags' => array_map(fn ($t) => ['id' => (int) $t, 'title' => $title('#__tags', (int) $t)], (array) $id)];
        }

        return ['type' => 'component', 'link' => $row->link];
    }

    private static function error(object $model): string
    {
        $error = $model->getError();

        return $error instanceof \Throwable ? $error->getMessage() : (string) $error;
    }
}
