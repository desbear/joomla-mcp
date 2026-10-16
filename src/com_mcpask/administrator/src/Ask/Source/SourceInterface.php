<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask\Source;

use Gss\Component\Mcpask\Administrator\Ask\Document;

\defined('_JEXEC') or die;

/**
 * A kind of content that can be asked about. A source only ever offers what a visitor who is
 * not signed in may read.
 *
 * @since  0.1.0
 */
interface SourceInterface
{
    public function name(): string;

    /**
     * IDs of the pages a guest may read, ascending.
     *
     * @return  int[]
     */
    public function ids(int $afterId = 0, int $limit = 0): array;

    /**
     * Of these IDs, the ones a guest may still read.
     *
     * @param   int[]  $ids
     *
     * @return  int[]
     */
    public function visible(array $ids): array;

    /**
     * When these pages were last changed (or created, when never changed), as the database
     * stores dates, so the scheduled task can pass over what has not changed since it looked.
     *
     * @param   int[]  $ids
     *
     * @return  array<int, string>  By ID
     */
    public function modified(array $ids): array;

    /**
     * The page, or null when a guest may not read it.
     */
    public function load(int $id): ?Document;

    /**
     * The custom fields that can be read along with the body, for the administrator to choose from.
     *
     * @return  array<string, string>  Label by field name
     */
    public function textFields(): array;

    /**
     * The address of a page, from the link stored when it was indexed.
     */
    public function url(string $link): string;
}
