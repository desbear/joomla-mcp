<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_modules
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Modules\Tool;

use Gss\Component\Mcp\Administrator\Helper\ModelHelper;
use Gss\Component\Mcp\Administrator\Helper\Resolver;
use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Module tools: find, read, create and change modules, their positions, settings and page assignment.
 *
 * Writes go through the core com_modules model (save events, action log, cache cleaning).
 *
 * @since  0.3.0
 */
final class ModuleTools
{
    public const STATES = ['published' => 1, 'unpublished' => 0, 'trashed' => -2];

    private const ASSIGNMENT = ['all' => 0, 'only' => 1, 'except' => -1, 'none' => '-'];

    /** Most modules one bulk call may touch: each one is a full model save. */
    public const MAX_BULK = 100;

    /**
     * @return Tool[]
     */
    public static function tools(): array
    {
        $ref    = ['type' => ['integer', 'string']];
        $client = ['type' => 'string', 'enum' => ['site', 'administrator'], 'description' => 'Default site.'];
        $assign = [
            'type'        => 'object',
            'description' => 'Pages the module appears on: {"mode": "all" | "none" | "only" | "except", "menu_items": [menu item IDs, titles or paths]}. '
                . 'menu_items is needed for "only" and "except".',
            'properties' => [
                'mode'       => ['type' => 'string', 'enum' => array_keys(self::ASSIGNMENT)],
                'menu_items' => ['type' => 'array', 'items' => $ref, 'maxItems' => 500],
            ],
        ];
        $common = [
            'title'        => ['type' => 'string'],
            'position'     => ['type' => 'string', 'description' => 'Template position, see list_module_positions. Empty: not shown anywhere.'],
            'content'      => ['type' => 'string', 'description' => 'HTML content, only for "Custom" modules (mod_custom).'],
            'params'       => ['type' => 'object', 'description' => 'Module settings to merge, keyed by field name (see describe_module_type).'],
            'show_title'   => ['type' => 'boolean'],
            'assignment'   => $assign,
            'language'     => ['type' => 'string', 'description' => 'Language tag, or "*" for all (default).'],
            'access'       => ['type' => 'integer', 'description' => 'Access level ID (1 = Public).'],
            'note'         => ['type' => 'string', 'description' => 'Internal note.'],
            'publish_up'   => ['type' => 'string', 'description' => 'Start publishing, site time zone, YYYY-MM-DD HH:MM.'],
            'publish_down' => ['type' => 'string', 'description' => 'Finish publishing, site time zone, YYYY-MM-DD HH:MM.'],
        ];

        $can = fn (string $action) => fn (ToolContext $c) => $c->user->authorise($action, 'com_modules');

        return [
            new Tool(
                'list_module_types',
                'List the installed module types (e.g. mod_custom for HTML content, mod_menu for a menu, mod_articles for article lists).',
                ['properties' => ['client' => $client], 'additionalProperties' => false],
                [self::class, 'listTypes'],
                'List module types',
                Tool::SCOPE_READ,
                'modules',
                false,
                $can('core.manage')
            ),
            new Tool(
                'describe_module_type',
                'Describe the settings of a module type: field names, types, defaults and allowed options. Use the names as keys in "params".',
                [
                    'properties'           => ['module' => ['type' => 'string', 'description' => 'Module type, e.g. mod_menu.'], 'client' => $client],
                    'required'             => ['module'],
                    'additionalProperties' => false,
                ],
                [self::class, 'describeType'],
                'Describe module type',
                Tool::SCOPE_READ,
                'modules',
                false,
                $can('core.manage')
            ),
            new Tool(
                'list_module_positions',
                'List the module positions of the default template and the positions in use, with module counts.',
                ['properties' => ['client' => $client], 'additionalProperties' => false],
                [self::class, 'listPositions'],
                'List module positions',
                Tool::SCOPE_READ,
                'modules',
                false,
                $can('core.manage')
            ),
            new Tool(
                'list_modules',
                'List modules with their type, position, state and page assignment.',
                [
                    'properties' => [
                        'client'    => $client,
                        'position'  => ['type' => 'string'],
                        'module'    => ['type' => 'string', 'description' => 'Module type, e.g. mod_custom.'],
                        'state'     => ['type' => 'string', 'enum' => ['published', 'unpublished', 'trashed', 'any'], 'description' => 'Default: published and unpublished.'],
                        'query'     => ['type' => 'string', 'description' => 'Filter by title or note.'],
                        'menu_item' => $ref + ['description' => 'Only modules shown on this page (menu item ID, title or path).'],
                        'language'  => ['type' => 'string'],
                        'limit'     => ['type' => 'integer', 'description' => 'Default 100.'],
                        'offset'    => ['type' => 'integer'],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'listModules'],
                'List modules',
                Tool::SCOPE_READ,
                'modules',
                false,
                $can('core.manage')
            ),
            new Tool(
                'get_module',
                'Get one module with its settings, content (for Custom modules) and the pages it is assigned to.',
                ['properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
                [self::class, 'getModule'],
                'Get module',
                Tool::SCOPE_READ,
                'modules',
                false,
                $can('core.manage')
            ),
            new Tool(
                'create_module',
                'Create a module. Default settings of the module type are filled in; unless "state" is given the configured default state '
                . 'for new content is used (normally unpublished). Default assignment: all pages.',
                [
                    'properties' => [
                        'module' => ['type' => 'string', 'description' => 'Module type, see list_module_types (e.g. mod_custom).'],
                        'client' => $client,
                        'state'  => ['type' => 'string', 'enum' => ['published', 'unpublished']],
                    ] + $common,
                    'required'             => ['module', 'title'],
                    'additionalProperties' => false,
                ],
                [self::class, 'createModule'],
                'Create module',
                Tool::SCOPE_WRITE,
                'modules',
                false,
                $can('core.create')
            ),
            new Tool(
                'update_module',
                'Change a module: title, position, content, settings, page assignment and more. Only the given properties change; '
                . '"params" are merged into the existing settings. Use set_module_state to publish or unpublish.',
                [
                    'properties' => ['id' => ['type' => 'integer']] + $common,
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
                [self::class, 'updateModule'],
                'Update module',
                Tool::SCOPE_WRITE,
                'modules',
                true,
                $can('core.edit')
            ),
            new Tool(
                'set_module_state',
                'Publish or unpublish modules.',
                [
                    'properties' => [
                        'ids'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => self::MAX_BULK],
                        'state' => ['type' => 'string', 'enum' => ['published', 'unpublished']],
                    ],
                    'required'             => ['ids', 'state'],
                    'additionalProperties' => false,
                ],
                [self::class, 'setState'],
                'Publish modules',
                Tool::SCOPE_PUBLISH,
                'modules',
                true,
                $can('core.edit.state')
            ),
            new Tool(
                'trash_modules',
                'Move modules to the trash (restorable in the back-end).',
                [
                    'properties'           => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => self::MAX_BULK]],
                    'required'             => ['ids'],
                    'additionalProperties' => false,
                ],
                [self::class, 'trash'],
                'Trash modules',
                Tool::SCOPE_DELETE,
                'modules',
                true,
                $can('core.edit.state')
            ),
        ];
    }

    // ------------------------------------------------------------------ Read

    public static function listTypes(array $args, ToolContext $ctx): array
    {
        $db       = $ctx->db;
        $clientId = self::clientId($args);
        $query    = $db->getQuery(true)
            ->select('element, name')
            ->from($db->quoteName('#__extensions'))
            ->where('type = ' . $db->quote('module'))
            ->where('client_id = ' . $clientId)
            ->where('enabled = 1')
            ->order('element');

        $types = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            self::loadLanguage($ctx, $row->element, $clientId);

            $types[] = [
                'module'      => $row->element,
                'name'        => Text::_($row->name),
                'description' => self::plain(Text::_(strtoupper($row->element) . '_XML_DESCRIPTION')),
            ];
        }

        return ['module_types' => $types];
    }

    public static function describeType(array $args, ToolContext $ctx): array
    {
        $clientId = self::clientId($args);
        $module   = self::moduleType($ctx, $args['module'], $clientId);

        return ['module' => $module, 'fields' => array_values(self::fields($ctx, $module, $clientId))];
    }

    public static function listPositions(array $args, ToolContext $ctx): array
    {
        $db       = $ctx->db;
        $clientId = self::clientId($args);

        $template = (string) $db->setQuery(
            $db->getQuery(true)
                ->select('template')
                ->from($db->quoteName('#__template_styles'))
                ->where('client_id = ' . $clientId)
                ->where('home = ' . $db->quote('1'))
        )->loadResult();

        $declared = self::templatePositions($template, $clientId);

        $query = $db->getQuery(true)
            ->select('position, COUNT(*) AS n, SUM(CASE WHEN published = 1 THEN 1 ELSE 0 END) AS published')
            ->from($db->quoteName('#__modules'))
            ->where('client_id = ' . $clientId)
            ->where('published IN (0, 1)')
            ->group('position');

        $used = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $used[$row->position] = ['modules' => (int) $row->n, 'published' => (int) $row->published];
        }

        $positions = [];

        foreach (array_unique(array_merge($declared, array_keys($used))) as $position) {
            $positions[] = [
                'position'            => $position === '' ? '(none)' : $position,
                'in_default_template' => \in_array($position, $declared, true),
                'modules'             => $used[$position]['modules'] ?? 0,
                'published'           => $used[$position]['published'] ?? 0,
            ];
        }

        return ['template' => $template, 'positions' => $positions];
    }

    public static function listModules(array $args, ToolContext $ctx): array
    {
        $db       = $ctx->db;
        $clientId = self::clientId($args);
        $query    = $db->getQuery(true)
            ->from($db->quoteName('#__modules', 'm'))
            ->where('m.client_id = ' . $clientId);

        $state = $args['state'] ?? null;
        $query->where($state === null ? 'm.published IN (0, 1)' : ($state === 'any' ? 'm.published IN (-2, 0, 1)' : 'm.published = ' . self::STATES[$state]));

        if (isset($args['position'])) {
            $position = $args['position'] === '(none)' ? '' : $args['position'];
            $query->where('m.position = :position')->bind(':position', $position);
        }

        if (!empty($args['module'])) {
            $query->where('m.module = :module')->bind(':module', $args['module']);
        }

        if (($args['query'] ?? '') !== '') {
            $search = '%' . Resolver::likeValue(mb_strtolower($args['query'])) . '%';
            $query->where('(LOWER(m.title) LIKE :s1 OR LOWER(m.note) LIKE :s2)')->bind(':s1', $search)->bind(':s2', $search);
        }

        if (!empty($args['language'])) {
            $query->where('m.language = :language')->bind(':language', $args['language']);
        }

        if (isset($args['menu_item'])) {
            $item = Resolver::menuItem($ctx, $args['menu_item']);
            $query->where(self::shownOnCondition($ctx, $item->id));
        }

        $total  = (int) $db->setQuery((clone $query)->select('COUNT(*)'))->loadResult();
        $limit  = $ctx->limit($args['limit'] ?? null, 100);
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $query->select('m.id, m.title, m.module, m.position, m.published, m.ordering, m.showtitle, m.language, m.access, m.note')
            ->order('m.position, m.ordering')
            ->setLimit($limit, $offset);

        $rows        = $db->setQuery($query)->loadObjectList();
        $assignments = self::assignments($ctx, array_map(fn ($r) => (int) $r->id, $rows));

        $items = array_map(fn ($r) => [
            'id'         => (int) $r->id,
            'title'      => $r->title,
            'module'     => $r->module,
            'position'   => $r->position,
            'state'      => array_search((int) $r->published, self::STATES, true) ?: (string) $r->published,
            'ordering'   => (int) $r->ordering,
            'show_title' => (bool) $r->showtitle,
            'language'   => $r->language,
            'assignment' => ['mode' => $assignments[(int) $r->id]['mode'], 'menu_item_count' => \count($assignments[(int) $r->id]['ids'])],
            'note'       => $r->note ?: null,
        ], $rows);

        return ['total' => $total, 'offset' => $offset, 'has_more' => $offset + \count($items) < $total, 'modules' => $items];
    }

    public static function getModule(array $args, ToolContext $ctx): array
    {
        $db  = $ctx->db;
        $id  = (int) $args['id'];
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select('m.*, v.title AS access_title')
                ->from($db->quoteName('#__modules', 'm'))
                ->join('LEFT', $db->quoteName('#__viewlevels', 'v'), 'v.id = m.access')
                ->where('m.id = :id')
                ->bind(':id', $id, ParameterType::INTEGER)
        )->loadObject();

        if (!$row) {
            throw new ToolException(\sprintf('Module %d not found.', $id));
        }

        $assignment = self::assignments($ctx, [$id])[$id];
        $menuItems  = [];

        if ($assignment['ids']) {
            $query = $db->getQuery(true)
                ->select('id, title, menutype')
                ->from($db->quoteName('#__menu'))
                ->whereIn('id', $assignment['ids']);

            $menuItems = array_map(fn ($m) => ['id' => (int) $m->id, 'title' => $m->title, 'menu' => $m->menutype], $db->setQuery($query)->loadObjectList());
        }

        return [
            'id'           => (int) $row->id,
            'title'        => $row->title,
            'module'       => $row->module,
            'client'       => (int) $row->client_id === 1 ? 'administrator' : 'site',
            'position'     => $row->position,
            'state'        => array_search((int) $row->published, self::STATES, true) ?: (string) $row->published,
            'ordering'     => (int) $row->ordering,
            'show_title'   => (bool) $row->showtitle,
            'language'     => $row->language,
            'access'       => ['id' => (int) $row->access, 'title' => $row->access_title],
            'note'         => $row->note ?: null,
            'publish_up'   => Resolver::localDate($ctx, $row->publish_up),
            'publish_down' => Resolver::localDate($ctx, $row->publish_down),
            'assignment'   => ['mode' => $assignment['mode'], 'menu_items' => $menuItems],
            'params'       => self::safeParams($ctx, $row),
            'content'      => $row->module === 'mod_custom' ? $row->content : null,
        ];
    }

    // ------------------------------------------------------------------ Write

    public static function createModule(array $args, ToolContext $ctx): array
    {
        $clientId = self::clientId($args);
        $module   = self::moduleType($ctx, $args['module'], $clientId);
        $state    = isset($args['state']) ? self::STATES[$args['state']] : (int) $ctx->params->get('new_article_state', 0);

        if (!$ctx->user->authorise('core.create', 'com_modules')) {
            throw new ToolException('You are not allowed to create modules.');
        }

        if ($state === 1 && !$ctx->params->get('allow_publish', 1)) {
            throw new ToolException('Publishing is disabled on this MCP server; create the module unpublished.');
        }

        // Start from the defaults of the module type, as the back-end form does
        $defaults = [];

        foreach (self::fields($ctx, $module, $clientId) as $name => $field) {
            if ($field['default'] !== null) {
                $defaults[$name] = $field['default'];
            }
        }

        $data = [
            'id'         => 0,
            'module'     => $module,
            'client_id'  => $clientId,
            'title'      => $args['title'],
            'position'   => '',
            'published'  => $state,
            'showtitle'  => 1,
            'language'   => '*',
            'access'     => 1,
            'params'     => $defaults,
            'content'    => '',
            'note'       => '',
            'assignment' => 0,
            'assigned'   => [],
        ];

        $data = self::apply($ctx, $data, $args);
        $id   = self::save($ctx, $data);

        return ['result' => 'created'] + self::getModule(['id' => $id], $ctx);
    }

    public static function updateModule(array $args, ToolContext $ctx): array
    {
        $id   = (int) $args['id'];
        $data = self::load($ctx, $id);

        // The per-asset check already inherits the component rules, so a component-level fallback
        // would only make an explicit Deny on this module unreachable
        if (!$ctx->user->authorise('core.edit', 'com_modules.module.' . $id)) {
            throw new ToolException(\sprintf('You are not allowed to edit module %d.', $id));
        }

        $data = self::apply($ctx, $data, $args);

        self::save($ctx, $data);

        return ['result' => 'updated'] + self::getModule(['id' => $id], $ctx);
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

    private static function apply(ToolContext $ctx, array $data, array $args): array
    {
        foreach (['title', 'position', 'language', 'note'] as $key) {
            if (isset($args[$key])) {
                $data[$key] = trim((string) $args[$key]);
            }
        }

        if (isset($args['content'])) {
            if ($data['module'] !== 'mod_custom') {
                throw new ToolException('"content" is only used by Custom modules (mod_custom); use "params" for other module types.');
            }

            $data['content'] = $args['content'];
        }

        if (isset($args['show_title'])) {
            $data['showtitle'] = $args['show_title'] ? 1 : 0;
        }

        if (isset($args['access'])) {
            $data['access'] = (int) $args['access'];
        }

        foreach (['publish_up', 'publish_down'] as $key) {
            if (\array_key_exists($key, $args)) {
                $data[$key] = empty($args[$key]) ? null : Resolver::sqlDate($ctx, $args[$key]);
            }
        }

        if (isset($args['params'])) {
            $fields  = self::fields($ctx, $data['module'], (int) $data['client_id']);
            $unknown = array_diff(array_keys((array) $args['params']), array_keys($fields), ['layout', 'moduleclass_sfx', 'cache', 'cache_time', 'cachemode', 'module_tag', 'bootstrap_size', 'header_tag', 'header_class', 'style']);

            if ($unknown && $fields) {
                throw new ToolException(\sprintf(
                    'Unknown settings for %s: %s. Available: %s.',
                    $data['module'],
                    implode(', ', $unknown),
                    implode(', ', array_keys($fields))
                ));
            }

            $data['params'] = array_merge((array) $data['params'], (array) $args['params']);
        }

        if (isset($args['assignment'])) {
            $mode = $args['assignment']['mode'] ?? null;

            if (!isset(self::ASSIGNMENT[$mode])) {
                throw new ToolException('assignment.mode must be one of: ' . implode(', ', array_keys(self::ASSIGNMENT)) . '.');
            }

            $items = [];

            foreach ((array) ($args['assignment']['menu_items'] ?? []) as $ref) {
                $items[] = Resolver::menuItem($ctx, $ref)->id;
            }

            if (\in_array($mode, ['only', 'except'], true) && !$items) {
                throw new ToolException(\sprintf('assignment mode "%s" needs menu_items.', $mode));
            }

            $data['assignment'] = self::ASSIGNMENT[$mode];
            $data['assigned']   = array_values(array_unique($items));
        }

        return $data;
    }

    /**
     * Load a module as model data, including its current assignment (the model rewrites it on every save).
     */
    private static function load(ToolContext $ctx, int $id): array
    {
        $db  = $ctx->db;
        $row = $db->setQuery($db->getQuery(true)->select('*')->from($db->quoteName('#__modules'))->where('id = :id')->bind(':id', $id, ParameterType::INTEGER))->loadAssoc();

        if (!$row) {
            throw new ToolException(\sprintf('Module %d not found.', $id));
        }

        $assignment = self::assignments($ctx, [$id])[$id];

        $row['params']     = json_decode((string) $row['params'], true) ?: [];
        $row['assignment'] = self::ASSIGNMENT[$assignment['mode']];
        $row['assigned']   = $assignment['ids'];

        return $row;
    }

    private static function save(ToolContext $ctx, array $data): int
    {
        $model = ModelHelper::admin($ctx, 'com_modules', 'Module', ['id' => (int) $data['id'], 'client_id' => (int) $data['client_id']]);

        if (!$model->save($data)) {
            $error = $model->getError();

            throw new ToolException('Save failed: ' . ($error instanceof \Throwable ? $error->getMessage() : (string) $error));
        }

        return (int) ($data['id'] ?: $model->getState('module.id'));
    }

    /**
     * @param  int[]  $ids
     */
    private static function publish(ToolContext $ctx, array $ids, int $state): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            throw new ToolException('No module IDs given.');
        }

        // The model refuses modules the user may not change, but it reports that through the message
        // queue and returns false without setting an error, so checking getError() would report a
        // refusal as success. Authorise up front instead, on the asset core itself checks.
        foreach ($ids as $id) {
            if (!$ctx->user->authorise('core.edit.state', 'com_modules.module.' . $id)) {
                throw new ToolException(\sprintf('You are not allowed to change the state of module %d.', $id));
            }
        }

        $model = ModelHelper::admin($ctx, 'com_modules', 'Module');

        if (!$model->publish($ids, $state)) {
            $error = (string) $model->getError();

            throw new ToolException('Could not change the state' . ($error !== '' ? ': ' . $error : '. The site refused the change.'));
        }

        $db   = $ctx->db;
        $rows = $db->setQuery($db->getQuery(true)->select('id, title, published, position')->from($db->quoteName('#__modules'))->whereIn('id', $ids))->loadObjectList();

        return ['results' => array_map(fn ($r) => [
            'id'       => (int) $r->id,
            'title'    => $r->title,
            'position' => $r->position,
            'state'    => array_search((int) $r->published, self::STATES, true) ?: (string) $r->published,
        ], $rows)];
    }

    /**
     * Module settings for output, with credentials masked.
     *
     * get_module output goes to an AI model provider, and commercial modules routinely keep API
     * keys, SMTP passwords and licence keys in their parameters. The manifest field type is used
     * where the module declares one, with a key name denylist for the rest.
     */
    private static function safeParams(ToolContext $ctx, object $row): array
    {
        $params = array_filter(json_decode((string) $row->params, true) ?: [], fn ($v) => $v !== '' && $v !== null);
        $fields = self::fields($ctx, (string) $row->module, (int) $row->client_id);

        foreach (array_keys($params) as $name) {
            if (
                \in_array($fields[$name]['type'] ?? '', ['password', 'accesstoken'], true)
                || preg_match('/(pass|secret|token|api[_-]?key|licen[cs]e)/i', (string) $name)
            ) {
                // Keep the key so the model still sees that the setting is there
                $params[$name] = '<redacted>';
            }
        }

        return $params;
    }

    /**
     * Assignment mode and menu item IDs per module.
     *
     * @param   int[]  $ids
     *
     * @return  array<int, array{mode: string, ids: int[]}>
     */
    private static function assignments(ToolContext $ctx, array $ids): array
    {
        $result = array_fill_keys($ids, ['mode' => 'none', 'ids' => []]);

        if (!$ids) {
            return $result;
        }

        $db   = $ctx->db;
        $rows = $db->setQuery($db->getQuery(true)->select('moduleid, menuid')->from($db->quoteName('#__modules_menu'))->whereIn('moduleid', $ids))->loadObjectList();

        foreach ($rows as $row) {
            $id     = (int) $row->moduleid;
            $menuId = (int) $row->menuid;

            if ($menuId === 0) {
                $result[$id]['mode'] = 'all';
            } else {
                $result[$id]['mode']  = $menuId > 0 ? 'only' : 'except';
                $result[$id]['ids'][] = abs($menuId);
            }
        }

        return $result;
    }

    /**
     * SQL condition: module m is shown on the page of the given menu item.
     */
    private static function shownOnCondition(ToolContext $ctx, int $menuItemId): string
    {
        $table = $ctx->db->quoteName('#__modules_menu');

        return '(EXISTS (SELECT 1 FROM ' . $table . ' a WHERE a.moduleid = m.id AND (a.menuid = 0 OR a.menuid = ' . $menuItemId . '))'
            . ' OR (EXISTS (SELECT 1 FROM ' . $table . ' b WHERE b.moduleid = m.id AND b.menuid < 0)'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $table . ' c WHERE c.moduleid = m.id AND c.menuid = ' . -$menuItemId . ')))';
    }

    /**
     * Settings fields of a module type, read from its XML manifest.
     *
     * @return array<string, array>
     */
    private static function fields(ToolContext $ctx, string $module, int $clientId): array
    {
        $base = $clientId ? JPATH_ADMINISTRATOR : JPATH_SITE;
        $file = $base . '/modules/' . $module . '/' . $module . '.xml';

        if (!is_file($file) || !($xml = simplexml_load_file($file))) {
            return [];
        }

        self::loadLanguage($ctx, $module, $clientId);

        $fields = [];

        foreach ($xml->xpath('//config/fields[@name="params"]/fieldset') ?: [] as $fieldset) {
            foreach ($fieldset->xpath('.//field') as $field) {
                $name = (string) $field['name'];
                $type = strtolower((string) $field['type']);

                // Skip fields nested in subforms and pure layout fields
                if ($name === '' || \in_array($type, ['spacer', 'note'], true) || \count($field->xpath('ancestor::form'))) {
                    continue;
                }

                $options = [];

                foreach ($field->option as $option) {
                    $options[] = ['value' => (string) $option['value'], 'text' => self::plain(Text::_(trim((string) $option)))];
                }

                $fields[$name] = array_filter([
                    'name'        => $name,
                    'type'        => $type,
                    'label'       => self::plain(Text::_((string) $field['label'])),
                    'description' => self::plain(Text::_((string) $field['description'])) ?: null,
                    'default'     => isset($field['default']) ? (string) $field['default'] : null,
                    'multiple'    => ((string) $field['multiple']) === 'true' ? true : null,
                    'options'     => $options ?: null,
                    'group'       => (string) $fieldset['name'],
                    'showon'      => (string) $field['showon'] ?: null,
                ], fn ($v) => $v !== null && $v !== '') + ['default' => isset($field['default']) ? (string) $field['default'] : null];
            }
        }

        return $fields;
    }

    /**
     * Positions declared by a template (and its parent template).
     *
     * @return string[]
     */
    private static function templatePositions(string $template, int $clientId): array
    {
        $base      = ($clientId ? JPATH_ADMINISTRATOR : JPATH_SITE) . '/templates/';
        $positions = [];
        $seen      = [];

        while ($template !== '' && !isset($seen[$template]) && is_file($base . $template . '/templateDetails.xml')) {
            $seen[$template] = true;
            $xml             = simplexml_load_file($base . $template . '/templateDetails.xml');

            if (!$xml) {
                break;
            }

            foreach ($xml->positions->position ?? [] as $position) {
                $positions[] = (string) $position;
            }

            $template = (string) ($xml->parent ?? '');
        }

        return array_values(array_unique($positions));
    }

    private static function moduleType(ToolContext $ctx, string $module, int $clientId): string
    {
        $module = strtolower(trim($module));
        $module = str_starts_with($module, 'mod_') ? $module : 'mod_' . $module;
        $db     = $ctx->db;

        $exists = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__extensions'))
                ->where('type = ' . $db->quote('module'))
                ->where('element = :module')
                ->where('client_id = ' . $clientId)
                ->where('enabled = 1')
                ->bind(':module', $module)
        )->loadResult();

        if (!$exists) {
            throw new ToolException(\sprintf('Module type "%s" is not installed or not enabled. Use list_module_types.', $module));
        }

        return $module;
    }

    private static function clientId(array $args): int
    {
        return ($args['client'] ?? 'site') === 'administrator' ? 1 : 0;
    }

    private static function loadLanguage(ToolContext $ctx, string $module, int $clientId): void
    {
        $lang = $ctx->app->getLanguage();
        $base = $clientId ? JPATH_ADMINISTRATOR : JPATH_SITE;

        foreach ([$module . '.sys', $module] as $file) {
            $lang->load($file, $base) || $lang->load($file, $base . '/modules/' . $module);
        }
    }

    private static function plain(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    }
}
