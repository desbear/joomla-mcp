<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask;

\defined('_JEXEC') or die;

/**
 * One page of the site as a source hands it to the indexer.
 *
 * @since  0.1.0
 */
final class Document
{
    /**
     * @param  string  $source    Name of the source the page came from
     * @param  string  $link      Unrouted link (index.php?option=...), routed when it is shown
     * @param  string  $language  Language tag, or * for all languages
     * @param  string  $html      Body of the page
     */
    public function __construct(
        public readonly string $source,
        public readonly int $itemId,
        public readonly string $title,
        public readonly string $link,
        public readonly string $language,
        public readonly int $access,
        public readonly string $html
    ) {
    }
}
