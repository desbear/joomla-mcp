<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_content_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Content\Mcpask\Extension;

use Gss\Component\Mcpask\Administrator\Ask\Indexer;
use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Ask\Source\Sources;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Keeps the index of "Ask the Site" in step with the content: a saved page is indexed again, and a
 * page that is unpublished, trashed, deleted or no longer public leaves the index at once.
 *
 * @since  0.1.0
 */
final class Mcpask extends CMSPlugin implements SubscriberInterface
{
    /** Articles and FLEXIcontent items; FLEXIcontent reports its items under either name. */
    private const CONTEXTS = ['com_content.article', 'com_content.form', 'com_flexicontent.item'];

    /** Publishing more pages than this at once leaves the indexing to the scheduled task. */
    private const MAX_AT_ONCE = 3;

    public static function getSubscribedEvents(): array
    {
        return [
            'onContentAfterSave'   => 'onContentAfterSave',
            'onContentChangeState' => 'onContentChangeState',
            'onContentAfterDelete' => 'onContentAfterDelete',
        ];
    }

    public function onContentAfterSave(Event $event): void
    {
        [$context, $item] = $this->arguments($event, ['context', 'subject']);

        if (\in_array($context, self::CONTEXTS, true) && !empty($item->id)) {
            $this->update([(int) $item->id]);
        }
    }

    public function onContentChangeState(Event $event): void
    {
        [$context, $ids] = $this->arguments($event, ['context', 'subject']);

        if (\in_array($context, self::CONTEXTS, true) && \is_array($ids)) {
            $this->update(array_map('intval', $ids));
        }
    }

    public function onContentAfterDelete(Event $event): void
    {
        [$context, $item] = $this->arguments($event, ['context', 'subject']);

        if (\in_array($context, self::CONTEXTS, true) && !empty($item->id)) {
            $this->update([(int) $item->id]);
        }
    }

    /**
     * Joomla 5 names the arguments of these events; Joomla 4 passes them by position.
     */
    private function arguments(Event $event, array $names): array
    {
        $arguments = $event->getArguments();

        return array_map(static fn ($name, $position) => $arguments[$name] ?? $arguments[$position] ?? null, $names, array_keys($names));
    }

    /**
     * @param  int[]  $ids
     */
    private function update(array $ids): void
    {
        // Saving content must never fail or wait long because of the index
        try {
            if (!ComponentHelper::isEnabled('com_mcpask')) {
                return;
            }

            $db       = Factory::getContainer()->get(DatabaseInterface::class);
            $settings = Settings::load();
            $source   = Sources::current($db, $settings);
            $indexer  = new Indexer($db, $settings);
            $now      = $settings->indexOnSave() && $settings->apiKey() !== '' && \count($ids) <= self::MAX_AT_ONCE;

            foreach ($ids as $id) {
                $document = $source->load($id);

                if ($document === null) {
                    $indexer->remove($source->name(), $id);
                } elseif ($now) {
                    $indexer->index($document);
                } else {
                    $indexer->defer($document);
                }
            }
        } catch (\Throwable) {
            // A page whose embeddings could not be made is marked by the indexer and picked up by
            // the scheduled task; anything else is not worth interrupting the editor for
        }
    }
}
