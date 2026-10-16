<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Controller;

use Gss\Component\Mcpask\Administrator\Agent\Handoffs;
use Gss\Component\Mcpask\Administrator\View\Handoffs\HtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The log of handoffs as a CSV file, with the filters of the page.
 *
 * @since  0.10.0
 */
class HandoffsController extends BaseController
{
    public function export(): void
    {
        $user = $this->app->getIdentity();

        if (!Session::checkToken('get') || (!$user->authorise('core.admin', 'com_mcpask') && !$user->authorise('core.options', 'com_mcpask'))) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        [$rows] = (new Handoffs(Factory::getContainer()->get(DatabaseInterface::class)))->list(HtmlView::filter($this->input), 1000);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="handoffs-' . Factory::getDate()->format('Ymd') . '.csv"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');

        // A byte order mark, for spreadsheets to read UTF-8
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['id', 'created', 'agent', 'source', 'within_hours', 'availability', 'outcome', 'question', 'page', 'report', 'language'], ',', '"', '\\');

        foreach ($rows as $row) {
            // A cell that a spreadsheet would read as a formula is written as text
            $cells = array_map(static fn ($cell) => preg_match('/^[=+\-@\t\r]/', (string) $cell) ? "'" . $cell : (string) $cell, [
                $row->id, $row->created, $row->agent, $row->source, $row->hours, $row->availability, $row->outcome, $row->question, $row->page, $row->report, $row->language,
            ]);
            fputcsv($out, $cells, ',', '"', '\\');
        }

        fclose($out);
        $this->app->close();
    }
}
