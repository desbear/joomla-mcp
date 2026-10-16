<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask\Source;

use Gss\Component\Mcpask\Administrator\Ask\PeerSync;
use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Ask\WebSync;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Chooses the content source of this site.
 *
 * @since  0.1.0
 */
final class Sources
{
    /**
     * FLEXIcontent keeps its items in the articles table, so when it is installed its items are
     * the site's content and are read as such, with their fields and addresses.
     */
    public static function current(DatabaseInterface $db, Settings $settings): SourceInterface
    {
        return FlexicontentSource::isAvailable() ? new FlexicontentSource($db, $settings) : new ContentSource($db, $settings);
    }

    /**
     * Every kind of this site's own content that is answered from, by name: the articles (or
     * FLEXIcontent items) first, then EasyBlog posts when EasyBlog is installed and included.
     *
     * @return  array<string, SourceInterface>
     */
    public static function own(DatabaseInterface $db, Settings $settings): array
    {
        $current = self::current($db, $settings);
        $sources = [$current->name() => $current];

        if ($settings->includeEasyblog() && EasyblogSource::isAvailable()) {
            $sources['easyblog'] = new EasyblogSource($db);
        }

        return $sources;
    }

    /**
     * Whether stored pages came from elsewhere: another site with this package, or a website the
     * administrator listed. Those were public where they came from and keep their own addresses.
     */
    public static function isElsewhere(string $source): bool
    {
        return PeerSync::isPeer($source) || WebSync::isWeb($source);
    }

    /**
     * The sources searched besides this site's own.
     *
     * @return  string[]
     */
    public static function elsewhere(Settings $settings): array
    {
        return array_merge(
            array_map(static fn ($id) => PeerSync::PREFIX . $id, array_keys($settings->peers())),
            array_map(static fn ($id) => WebSync::PREFIX . $id, array_keys($settings->websites()))
        );
    }

    /**
     * The name of the site a stored page came from; empty for this site's own.
     */
    public static function siteName(Settings $settings, string $source): string
    {
        if (PeerSync::isPeer($source)) {
            return $settings->peers()[substr($source, \strlen(PeerSync::PREFIX))]['name'] ?? '';
        }

        if (WebSync::isWeb($source)) {
            return $settings->websites()[substr($source, \strlen(WebSync::PREFIX))]['name'] ?? '';
        }

        return '';
    }
}
