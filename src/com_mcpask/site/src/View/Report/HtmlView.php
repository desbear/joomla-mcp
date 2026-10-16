<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Site\View\Report;

use Gss\Component\Mcpask\Administrator\Agent\Report;
use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Ask\Theme;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * A report written for a visitor, read by the key they were given. Not for search engines; gone
 * once its days are over.
 *
 * @since  0.9.0
 */
class HtmlView extends BaseHtmlView
{
    public ?object $report = null;

    public string $key = '';

    public function display($tpl = null): void
    {
        $app       = Factory::getApplication();
        $settings  = Settings::load();
        $this->key = $app->getInput()->getAlnum('key');

        $this->report = (new Report(Factory::getContainer()->get(DatabaseInterface::class), $settings, (string) $app->get('secret')))->find($this->key);

        if (!$this->report || !$this->report->content) {
            throw new \RuntimeException(Text::_('COM_MCPASK_REPORT_GONE'), 404);
        }

        $document = $this->getDocument();
        $document->setTitle($this->report->title ?: Text::_('COM_MCPASK_REPORT'));
        $document->setMetaData('robots', 'noindex, nofollow');
        $document->setMetaData('referrer', 'no-referrer');

        $wa = $document->getWebAssetManager();
        $wa->registerAndUseStyle('com_mcpask.report', 'com_mcpask/report.css', ['version' => 'auto']);

        // The site's colours, as the module shows them
        $theme = Theme::css($settings->theme());

        if ($theme !== '') {
            $wa->addInlineStyle(str_replace(['.mcp-ask,', '.mcp-ask-chat {'], ['', '.mcpask-report {'], $theme), [], [], ['com_mcpask.report']);
        }

        parent::display($tpl);
    }
}
