<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\View\Quality;

use Gss\Component\Mcpask\Administrator\Ask\Hints;
use Gss\Component\Mcpask\Administrator\Ask\Retriever;
use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Ask\Source\Sources;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Answer quality: how visitors rated the answers, the questions worth a look, and the source
 * rules that point questions to the pages that answer them.
 *
 * @since  0.6.0
 */
class HtmlView extends BaseHtmlView
{
    /** Days the ratings and the questions to look at go back. */
    public const DAYS = 30;

    /** @var array{good: int, bad: int, answered: int} */
    public array $ratings = ['good' => 0, 'bad' => 0, 'answered' => 0];

    /** @var object[] */
    public array $pending = [];

    /** @var object[]  Rules, each with "pages": the pages it points to as they are now */
    public array $hints = [];

    /** @var array<string, string>  Language tag => title, for rules that apply to one language */
    public array $languages = [];

    public bool $canManage = false;

    public bool $canDelete = false;

    public function display($tpl = null): void
    {
        $db       = Factory::getContainer()->get(DatabaseInterface::class);
        $settings = Settings::load();
        $hints    = new Hints($db, $settings);
        $user     = Factory::getApplication()->getIdentity();

        $this->ratings   = $hints->ratings(self::DAYS);
        $this->pending   = $hints->pending(self::DAYS);
        $this->hints     = $this->withPages($db, $settings, $hints->all());
        // Rules change what visitors are told: editing them is "Edit", deleting them "Delete"
        $this->canManage = $user->authorise('core.edit', 'com_mcpask');
        $this->canDelete = $user->authorise('core.delete', 'com_mcpask');

        foreach (LanguageHelper::getContentLanguages([0, 1]) as $language) {
            $this->languages[$language->lang_code] = $language->title;
        }

        ToolbarHelper::title(Text::_('COM_MCPASK_QUALITY'), 'thumbs-up');
        ToolbarHelper::link('index.php?option=com_mcpask', Text::_('COM_MCPASK_SUBMENU_DASHBOARD'), 'home');

        if ($user->authorise('core.admin', 'com_mcpask') || $user->authorise('core.options', 'com_mcpask')) {
            ToolbarHelper::link('index.php?option=com_mcpask&view=appearance', Text::_('COM_MCPASK_APPEARANCE'), 'palette');
            ToolbarHelper::preferences('com_mcpask');
        }

        parent::display($tpl);
    }

    /**
     * The pages each rule points to, with their titles and addresses as indexed now; a page that
     * is no longer in the index is shown as such.
     *
     * @param   object[]  $rules
     *
     * @return  object[]
     */
    private function withPages(DatabaseInterface $db, Settings $settings, array $rules): array
    {
        $retriever = new Retriever($db, $settings, Sources::current($db, $settings));

        foreach ($rules as $rule) {
            $rule->pages = [];

            foreach ($rule->documents as $document) {
                $source = (string) $document['source'];
                $itemId = (int) $document['item_id'];
                $query  = $db->getQuery(true)
                    ->select('item_id, source, title, link')
                    ->from($db->quoteName('#__mcpask_documents'))
                    ->where('source = :source')
                    ->where('item_id = ' . $itemId)
                    ->bind(':source', $source);
                $row    = $db->setQuery($query)->loadObject();

                $rule->pages[] = [
                    'source'  => $source,
                    'item_id' => $itemId,
                    'title'   => $row ? $row->title : '',
                    'url'     => $row ? $retriever->url($row) : '',
                    'site'    => Sources::siteName($settings, $source),
                    'missing' => !$row || !$retriever->document($source, $itemId),
                ];
            }
        }

        return $rules;
    }
}
