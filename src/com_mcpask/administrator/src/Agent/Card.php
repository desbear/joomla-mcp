<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Agent;

\defined('_JEXEC') or die;

/**
 * The cards an agent may show. The browser draws them from this data, so no agent (and no AI
 * service behind one) can put markup on the page; every text here is plain text, or light
 * Markdown where it says so.
 *
 * @since  0.8.0
 */
final class Card
{
    /** Kinds of fields a fields card may hold. */
    public const KINDS = ['text', 'email', 'tel', 'number', 'textarea', 'select', 'radio', 'checkbox', 'date', 'info'];

    /**
     * Text from the agent, in light Markdown (as answers are written).
     */
    public static function text(string $markdown): array
    {
        return ['type' => 'text', 'text' => self::clip($markdown, 20000)];
    }

    /**
     * A set of fields to fill in.
     *
     * @param  array<int, array{name: string, label: string, kind: string, required?: bool, options?: array<int, array{value: string, label: string}>, value?: mixed, error?: string, help?: string}>  $fields
     * @param  array{index: int, total: int}|null  $page
     */
    public static function fields(string $id, array $fields, string $submit, string $title = '', ?array $page = null): array
    {
        $clean = [];

        foreach ($fields as $field) {
            $kind = \in_array($field['kind'] ?? '', self::KINDS, true) ? $field['kind'] : 'text';

            $clean[] = array_filter([
                'name'     => (string) $field['name'],
                'label'    => self::clip((string) ($field['label'] ?? ''), 500),
                'kind'     => $kind,
                'required' => (bool) ($field['required'] ?? false),
                'options'  => array_map(static fn ($option) => ['value' => (string) $option['value'], 'label' => self::clip((string) $option['label'], 300)], $field['options'] ?? []),
                'value'    => $field['value'] ?? null,
                'error'    => self::clip((string) ($field['error'] ?? ''), 300),
                'help'     => self::clip((string) ($field['help'] ?? ''), 2000),
            ], static fn ($value) => $value !== null && $value !== '' && $value !== []) + ['kind' => $kind, 'name' => (string) $field['name']];
        }

        return array_filter([
            'type'   => 'fields',
            'id'     => $id,
            'title'  => self::clip($title, 300),
            'fields' => $clean,
            'submit' => self::clip($submit, 100),
            'page'   => $page,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Questions the visitor may send as they are, or change first.
     *
     * @param  string[]  $items
     */
    public static function suggestions(array $items): ?array
    {
        $items = array_values(array_unique(array_filter(array_map(static fn ($item) => self::clip(trim((string) $item), 200), $items))));

        return $items ? ['type' => 'suggestions', 'items' => \array_slice($items, 0, 4)] : null;
    }

    /**
     * What will be sent, to confirm before it is.
     *
     * @param  array<int, array{label: string, value: string}>  $rows
     * @param  array<int, array{label: string, page: int}>      $edit  Buttons to go back to a page
     */
    public static function summary(string $title, array $rows, string $confirm, array $edit = []): array
    {
        return [
            'type'    => 'summary',
            'title'   => self::clip($title, 300),
            'rows'    => array_map(static fn ($row) => ['label' => self::clip((string) $row['label'], 300), 'value' => self::clip((string) $row['value'], 2000)], $rows),
            'confirm' => self::clip($confirm, 100),
            'edit'    => array_map(static fn ($button) => ['label' => self::clip((string) $button['label'], 100), 'page' => (int) $button['page']], $edit),
        ];
    }

    /**
     * Buttons for the next step, each sending an action.
     *
     * @param  array<int, array{label: string, action: string, value?: string, primary?: bool}>  $buttons
     */
    public static function actions(array $buttons): array
    {
        return [
            'type'    => 'actions',
            'buttons' => array_map(static fn ($button) => [
                'label'   => self::clip((string) $button['label'], 100),
                'action'  => preg_replace('/[^a-z_]/', '', (string) $button['action']),
                'value'   => self::clip((string) ($button['value'] ?? ''), 100),
                'primary' => (bool) ($button['primary'] ?? false),
            ], $buttons),
        ];
    }

    /**
     * A report being written for the visitor, read by its key (see Report).
     */
    public static function report(string $key, string $url, string $title = ''): array
    {
        return ['type' => 'report', 'key' => $key, 'url' => $url, 'title' => self::clip($title, 200)];
    }

    /**
     * Passing the visitor on to a person in a live chat window: the service's account (LiveChat
     * licence and group), whether it is within office hours, the words for each turn the window
     * takes, what the people answering see (plain values), and the visitor's name and address
     * when known.
     *
     * @param  array{waiting: string, online: string, offline: string, failed: string, button: string}  $texts
     * @param  array<string, string>  $variables
     * @param  array{name?: string, email?: string}  $customer
     *
     * @since  0.10.0
     */
    public static function handoff(string $key, int $license, int $group, bool $hours, array $texts, array $variables, array $customer = []): array
    {
        $clean = [];

        foreach (\array_slice($variables, 0, 16, true) as $name => $value) {
            $clean[self::clip((string) $name, 50)] = self::clip((string) $value, 1000);
        }

        return [
            'type'      => 'handoff',
            'key'       => $key,
            'service'   => 'livechat',
            'license'   => $license,
            'group'     => max(0, $group),
            'hours'     => $hours,
            'texts'     => array_map(static fn ($text) => self::clip((string) $text, 500), array_intersect_key($texts, array_flip(['waiting', 'online', 'offline', 'failed', 'button']))),
            'variables' => (object) array_filter($clean, static fn ($value) => $value !== ''),
            'customer'  => (object) array_filter(['name' => self::clip((string) ($customer['name'] ?? ''), 100), 'email' => filter_var($customer['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '']),
        ];
    }

    public static function done(string $markdown): array
    {
        return ['type' => 'done', 'text' => self::clip($markdown, 5000)];
    }

    public static function error(string $text): array
    {
        return ['type' => 'error', 'text' => self::clip($text, 1000)];
    }

    private static function clip(string $text, int $length): string
    {
        return mb_substr(trim($text), 0, $length);
    }
}
