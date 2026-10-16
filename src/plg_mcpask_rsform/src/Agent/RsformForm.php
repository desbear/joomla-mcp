<?php

/**
 * @package     Ask the Site
 * @subpackage  plg_mcpask_rsform
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcpask\Rsform\Agent;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * An RSForm! Pro form as a conversation sees it: pages of fields (cut at page breaks), what each
 * field accepts, checking what was filled in, and sending it in as RSForm does.
 *
 * @since  1.0.0
 */
final class RsformForm
{
    /** RSForm fields and what they become in the conversation. */
    private const KINDS = [
        'textBox'       => 'text',
        'textArea'      => 'textarea',
        'selectList'    => 'select',
        'radioGroup'    => 'radio',
        'checkboxGroup' => 'checkbox',
        'calendar'      => 'date',
        'jQueryCalendar' => 'date',
        'birthDay'      => 'date',
        'freeText'      => 'info',
        'hidden'        => 'hidden',
        'pageBreak'     => 'page',
    ];

    public string $title = '';

    /** @var string  The thank-you message, with RSForm's placeholders */
    public string $thankYou = '';

    /** @var array<int, array<int, array>>  Pages of fields: name, label, kind, required, options, help, format, default */
    public array $pages = [];

    public function __construct(private DatabaseInterface $db, public readonly int $id)
    {
    }

    public static function available(): bool
    {
        return is_file(JPATH_ADMINISTRATOR . '/components/com_rsform/helpers/rsform.php')
            && \Joomla\CMS\Component\ComponentHelper::isEnabled('com_rsform');
    }

    /**
     * @return  array<int, string>  Published forms, by ID
     */
    public static function forms(DatabaseInterface $db): array
    {
        return $db->setQuery($db->getQuery(true)->select('FormId, FormTitle')->from($db->quoteName('#__rsform_forms'))->where('Published = 1')->order('FormTitle'))->loadAssocList('FormId', 'FormTitle');
    }

    public function load(): bool
    {
        $db   = $this->db;
        $form = $db->setQuery($db->getQuery(true)->select('FormId, FormTitle, Thankyou')->from($db->quoteName('#__rsform_forms'))->where('FormId = ' . $this->id)->where('Published = 1'))->loadObject();

        if (!$form) {
            return false;
        }

        $this->title    = (string) $form->FormTitle;
        $this->thankYou = (string) $form->Thankyou;

        $components = $db->setQuery(
            $db->getQuery(true)
                ->select('c.ComponentId, t.ComponentTypeName AS type')
                ->from($db->quoteName('#__rsform_components', 'c'))
                ->join('INNER', $db->quoteName('#__rsform_component_types', 't'), 't.ComponentTypeId = c.ComponentTypeId')
                ->where('c.FormId = ' . $this->id)
                ->where('c.Published = 1')
                ->order('c.Order')
        )->loadObjectList();

        $page = [];

        foreach ($components as $component) {
            $kind = self::KINDS[$component->type] ?? null;

            if ($kind === null) {
                // Captchas, buttons and anything else a conversation does without
                continue;
            }

            if ($kind === 'page') {
                $this->pages[] = $page;
                $page          = [];
                continue;
            }

            $p = $db->setQuery(
                $db->getQuery(true)->select('PropertyName, PropertyValue')->from($db->quoteName('#__rsform_properties'))->where('ComponentId = ' . (int) $component->ComponentId)
            )->loadAssocList('PropertyName', 'PropertyValue');

            $field = [
                'name'     => (string) ($p['NAME'] ?? ''),
                'label'    => self::plain((string) ($p['CAPTION'] ?? '')),
                'kind'     => $kind,
                'required' => strtoupper((string) ($p['REQUIRED'] ?? 'NO')) === 'YES',
                'help'     => self::plain((string) ($p['DESCRIPTION'] ?? '')),
                'message'  => self::plain((string) ($p['VALIDATIONMESSAGE'] ?? '')),
                'format'   => (string) ($p['DATEFORMAT'] ?? 'Y-m-d'),
                'default'  => '',
                'options'  => [],
            ];

            if ($kind === 'info') {
                $field['label'] = self::plain((string) ($p['TEXT'] ?? ''));
                $field['name']  = $field['name'] ?: 'info' . $component->ComponentId;
            }

            if ($kind === 'text' && preg_match('/email/i', (string) ($p['VALIDATIONRULE'] ?? ''))) {
                $field['kind'] = 'email';
            }

            if ($kind === 'hidden') {
                $value            = (string) ($p['DEFAULTVALUE'] ?? '');
                // A default worked out by RSForm ({global:...} and the like) is left to RSForm
                $field['default'] = str_contains($value, '{') ? '' : $value;
            }

            if (\in_array($kind, ['select', 'radio', 'checkbox'], true)) {
                [$field['options'], $field['default']] = self::options((string) ($p['ITEMS'] ?? ''), $kind);
            }

            if ($field['name'] !== '' && ($kind !== 'info' || $field['label'] !== '')) {
                $page[] = $field;
            }
        }

        $this->pages[] = $page;
        $this->pages   = array_values(array_filter($this->pages, static fn ($fields) => array_filter($fields, static fn ($field) => !\in_array($field['kind'], ['info', 'hidden'], true))));

        return $this->pages !== [];
    }

    /**
     * The fields of a page a visitor fills in.
     *
     * @return  array[]
     */
    public function inputs(int $page): array
    {
        return array_values(array_filter($this->pages[$page] ?? [], static fn ($field) => !\in_array($field['kind'], ['info', 'hidden'], true)));
    }

    /**
     * A value as a field takes it, or null when it does not: an option's value (its label is taken
     * too), several for checkboxes, a date as Y-m-d.
     */
    public function accept(array $field, mixed $value): mixed
    {
        if (\in_array($field['kind'], ['select', 'radio', 'checkbox'], true)) {
            $values = array_map(static fn ($item) => trim((string) $item), (array) $value);
            $kept   = [];

            foreach ($values as $item) {
                foreach ($field['options'] as $option) {
                    if ($item !== '' && (strcasecmp($item, $option['value']) === 0 || strcasecmp($item, $option['label']) === 0)) {
                        $kept[] = $option['value'];
                    }
                }
            }

            $kept = array_values(array_unique($kept));

            return $field['kind'] === 'checkbox' ? $kept : ($kept[0] ?? null);
        }

        $value = trim(\is_array($value) ? implode(' ', $value) : (string) $value);

        if ($value === '') {
            return '';
        }

        return match ($field['kind']) {
            'email'  => filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null,
            'date'   => ($time = strtotime($value)) ? date('Y-m-d', $time) : null,
            default  => mb_substr($value, 0, $field['kind'] === 'textarea' ? 5000 : 500),
        };
    }

    /**
     * What is wrong with a page as filled in, by field name.
     *
     * @param   array<string, mixed>  $values
     *
     * @return  array<string, string>
     */
    public function errors(int $page, array $values, bool $chinese): array
    {
        $errors = [];

        foreach ($this->inputs($page) as $field) {
            $value = $values[$field['name']] ?? '';
            $empty = $value === '' || $value === [] || $value === null;

            if ($empty && $field['required']) {
                $errors[$field['name']] = $field['message'] !== '' ? $field['message'] : ($chinese ? '請填寫這一欄。' : 'Please fill this in.');
            } elseif (!$empty && $this->accept($field, $value) === null) {
                $errors[$field['name']] = $field['message'] !== '' ? $field['message'] : ($chinese ? '這一欄的格式不正確。' : 'This is not in the right form.');
            }
        }

        return $errors;
    }

    /**
     * A value as people read it: option labels rather than values.
     */
    public function display(array $field, mixed $value): string
    {
        if (\in_array($field['kind'], ['select', 'radio', 'checkbox'], true)) {
            $labels = [];

            foreach ((array) $value as $item) {
                foreach ($field['options'] as $option) {
                    if ($option['value'] === $item) {
                        $labels[] = $option['label'];
                    }
                }
            }

            return implode('、', $labels);
        }

        return (string) $value;
    }

    /**
     * Send the form in as RSForm does on its own page: a submission with its values, RSForm's
     * events (so its plugins and integrations run) and its e-mails. What a form checks on its own
     * page (its captcha, its own scripts) is not run: the conversation has its own checks.
     *
     * @param   array<string, mixed>  $values  By field name, as accept() gives them
     *
     * @return  array{id: int, thanks: string}  The submission, and the thank-you message filled in
     */
    public function submit(array $values, string $ip, int $userId, string $username, string $language): array
    {
        require_once JPATH_ADMINISTRATOR . '/components/com_rsform/helpers/rsform.php';

        $db   = $this->db;
        $app  = Factory::getApplication();
        $post = [];

        foreach ($this->pages as $page) {
            foreach ($page as $field) {
                if ($field['kind'] === 'info') {
                    continue;
                }

                $value = $field['kind'] === 'hidden' ? $field['default'] : ($values[$field['name']] ?? '');

                // Dates as the form writes them
                if ($field['kind'] === 'date' && \is_string($value) && $value !== '' && ($time = strtotime($value))) {
                    $value = date(preg_replace('/[^a-zA-Z0-9.\-\/ :,]/', '', $field['format']) ?: 'Y-m-d', $time);
                }

                $post[$field['name']] = $value;
            }
        }

        $submission = (object) [
            'FormId'         => $this->id,
            'DateSubmitted'  => Factory::getDate()->toSql(),
            'UserIp'         => $ip,
            'Username'       => $username,
            'UserId'         => $userId,
            'Lang'           => $language,
            'confirmed'      => 1,
            'ConfirmedIP'    => '',
            'ConfirmedDate'  => null,
            'SubmissionHash' => md5(random_bytes(32)),
        ];

        $db->insertObject('#__rsform_submissions', $submission, 'SubmissionId');
        $id = (int) $submission->SubmissionId;

        $this->trigger('onRsformFrontendBeforeStoreSubmissions', [['formId' => $this->id, 'post' => &$post, 'SubmissionId' => $id]]);

        foreach ($post as $name => $value) {
            $row = (object) [
                'SubmissionId' => $id,
                'FormId'       => $this->id,
                'FieldName'    => $name,
                'FieldValue'   => \RSFormProHelper::stripJava(\is_array($value) ? implode("\n", $value) : (string) $value),
            ];
            $db->insertObject('#__rsform_submission_values', $row);
        }

        $this->trigger('onRsformFrontendAfterStoreSubmissions', [['SubmissionId' => $id, 'formId' => $this->id]]);

        [$replace, $with] = \RSFormProHelper::sendSubmissionEmails($id);

        $this->trigger('onRsformFrontendAfterFormProcess', [['SubmissionId' => $id, 'formId' => $this->id]]);

        return ['id' => $id, 'thanks' => self::plain(str_replace((array) $replace, (array) $with, $this->thankYou))];
    }

    /**
     * An RSForm event, as RSForm raises it (its plugins take their arguments by reference).
     */
    private function trigger(string $name, array $arguments): void
    {
        try {
            Factory::getApplication()->triggerEvent($name, $arguments);
        } catch (\Throwable) {
            // A plugin of RSForm failing must not lose the submission
        }
    }

    /**
     * RSForm's items: one per line, "value|label" or "label", "[c]" marking what is chosen at first.
     * An item without a value ("|Please select") is a prompt, not a choice.
     *
     * @return  array{0: array<int, array{value: string, label: string}>, 1: string|array}
     */
    private static function options(string $items, string $kind): array
    {
        $options = [];
        $chosen  = [];

        foreach (preg_split('/\R/u', $items) ?: [] as $line) {
            $checked = str_contains($line, '[c]');
            $line    = trim(str_replace(['[c]', '[d]'], '', $line));

            if ($line === '' || preg_match('/^\[g\]|^\[\/g\]/', $line)) {
                continue;
            }

            [$value, $label] = str_contains($line, '|') ? array_map('trim', explode('|', $line, 2)) : [$line, $line];

            if ($value === '') {
                continue;
            }

            $options[] = ['value' => $value, 'label' => $label !== '' ? $label : $value];

            if ($checked) {
                $chosen[] = $value;
            }
        }

        return [$options, $kind === 'checkbox' ? $chosen : ($chosen[0] ?? '')];
    }

    private static function plain(string $html): string
    {
        $text = preg_replace(['#<br\s*/?>#i', '#</p>\s*<p[^>]*>#i'], ["\n", "\n\n"], $html);

        return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
