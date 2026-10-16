<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\View\Handoffs;

use Gss\Component\Mcpask\Administrator\Agent\Agents;
use Gss\Component\Mcpask\Administrator\Agent\Handoffs;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The log of visitors passed on to people: when, by which agent, from where, whether someone was
 * there, and what came of it; filtered, a page at a time, and exported as CSV.
 *
 * @since  0.10.0
 */
class HtmlView extends BaseHtmlView
{
    public const PER_PAGE = 50;

    /** @var object[] */
    public array $rows = [];

    public int $total = 0;

    /** @var array{agent: int, outcome: string, availability: string, days: int} */
    public array $filter = [];

    /** @var array<int, string>  Agents that pass visitors on, by ID */
    public array $agents = [];

    /** @var array{total: int, outcome: array<string, int>, availability: array<string, int>, hours: int} */
    public array $counts = [];

    public ?Pagination $pagination = null;

    public function display($tpl = null): void
    {
        $app  = Factory::getApplication();
        $user = $app->getIdentity();

        if (!$user->authorise('core.admin', 'com_mcpask') && !$user->authorise('core.options', 'com_mcpask')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $db           = Factory::getContainer()->get(DatabaseInterface::class);
        $input        = $app->getInput();
        $this->filter = self::filter($input);
        $start        = max(0, $input->getInt('limitstart', 0));
        $handoffs     = new Handoffs($db);

        [$this->rows, $this->total] = $handoffs->list($this->filter, self::PER_PAGE, $start);
        $this->counts               = $handoffs->counts($this->filter['days'] ?: 30);
        $this->pagination           = new Pagination($this->total, $start, self::PER_PAGE);

        foreach ((new Agents($db))->all() as $agent) {
            if ($agent->type === 'livechat') {
                $this->agents[(int) $agent->id] = (string) $agent->title;
            }
        }

        ToolbarHelper::title(Text::_('COM_MCPASK_HANDOFFS'), 'comments-2');
        ToolbarHelper::link(
            'index.php?option=com_mcpask&task=handoffs.export&' . Session::getFormToken() . '=1&' . http_build_query(array_filter($this->filter)),
            Text::_('COM_MCPASK_HANDOFFS_EXPORT'),
            'download'
        );
        ToolbarHelper::link('index.php?option=com_mcpask&view=agents', Text::_('COM_MCPASK_AGENTS'), 'users');

        parent::display($tpl);
    }

    /**
     * @return  array{agent: int, outcome: string, availability: string, days: int}
     */
    public static function filter(\Joomla\Input\Input $input): array
    {
        return [
            'agent'        => max(0, $input->getInt('agent', 0)),
            'outcome'      => \in_array($input->getCmd('outcome'), Handoffs::OUTCOMES, true) ? $input->getCmd('outcome') : '',
            'availability' => \in_array($input->getCmd('availability'), ['online', 'offline'], true) ? $input->getCmd('availability') : '',
            'days'         => \in_array($input->getInt('days', 30), [1, 7, 30, 90, 365], true) ? $input->getInt('days', 30) : 30,
        ];
    }
}
