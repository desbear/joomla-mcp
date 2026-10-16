<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_easyblog_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Easyblog\Mcpask\Extension;

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
 * Keeps the EasyBlog posts in the index of "Ask the Site" in step: a saved post is indexed again,
 * and a post that is unpublished, trashed, deleted or no longer public leaves the index at once.
 *
 * EasyBlog only loads the plugins of its own group (and Smart Search) when it saves or deletes a
 * post, so this listens there: to its own events, and to the Smart Search event it sends when
 * only the state of posts changes (publishing from the list of posts).
 *
 * @since  0.5.3
 */
final class Mcpask extends CMSPlugin implements SubscriberInterface
{
    /** Changing more posts than this at once leaves the indexing to the scheduled task. */
    private const MAX_AT_ONCE = 3;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterEasyBlogSave'   => 'onAfterEasyBlogSave',
            'onAfterEasyBlogDelete' => 'onAfterEasyBlogDelete',
            'onFinderChangeState'   => 'onFinderChangeState',
        ];
    }

    public function onAfterEasyBlogSave(Event $event): void
    {
        $post = $event->getArguments()[0] ?? null;

        if (\is_object($post) && !empty($post->id)) {
            $this->update([(int) $post->id]);
        }
    }

    public function onAfterEasyBlogDelete(Event $event): void
    {
        $post = $event->getArguments()[0] ?? null;

        if (\is_object($post) && !empty($post->id)) {
            $this->update([(int) $post->id]);
        }
    }

    public function onFinderChangeState(Event $event): void
    {
        $arguments = $event->getArguments();
        $context   = $arguments['context'] ?? $arguments[0] ?? '';
        $ids       = $arguments['pks'] ?? $arguments['subject'] ?? $arguments[1] ?? [];

        // Joomla 5 passes the IDs as a list, Joomla 4 sometimes as one ID
        if ($context === 'easyblog.blog') {
            $this->update(array_map('intval', (array) $ids));
        }
    }

    /**
     * @param  int[]  $ids
     */
    private function update(array $ids): void
    {
        // Saving a post must never fail or wait long because of the index
        try {
            if (!ComponentHelper::isEnabled('com_mcpask') || !class_exists(Settings::class)) {
                return;
            }

            $db       = Factory::getContainer()->get(DatabaseInterface::class);
            $settings = Settings::load();
            $source   = Sources::own($db, $settings)['easyblog'] ?? null;
            $indexer  = new Indexer($db, $settings);
            $now      = $settings->indexOnSave() && $settings->apiKey() !== '' && \count($ids) <= self::MAX_AT_ONCE;

            foreach (array_filter($ids) as $id) {
                $document = $source?->load($id);

                // Not answered from (EasyBlog left out, or the post no longer public): out of the index
                if ($document === null) {
                    $indexer->remove('easyblog', $id);
                } elseif ($now) {
                    $indexer->index($document);
                } else {
                    $indexer->defer($document);
                }
            }
        } catch (\Throwable) {
            // A post whose embeddings could not be made is marked by the indexer and picked up by
            // the scheduled task; anything else is not worth interrupting the writer for
        }
    }
}
