<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Site\Controller;

use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Gss\Component\Mcpask\Administrator\Ask\Source\Sources;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Offers this site's index to other sites that hold its export key, so they can answer from its
 * public content too (see PeerSync on their side).
 *
 * Only this site's own pages are given out, never those it has read from elsewhere, and only
 * what a visitor who is not signed in may read right now.
 *
 * @since  0.2.0
 */
class FeedController extends BaseController
{
    /** Pages given out in one request. */
    private const MAX_DOCUMENTS = 20;

    private const MAX_BODY_BYTES = 8192;

    /**
     * The embedding settings and the hash of every page: {model, dimensions, pages: {id: hash}}
     */
    public function pages(): void
    {
        [$db, $settings, $source] = $this->guard();

        $name  = $source->name();
        $query = $db->getQuery(true)
            ->select('item_id, hash')
            ->from($db->quoteName('#__mcpask_documents'))
            ->where('source = :source')
            ->where('status = ' . $db->quote('ok'))
            ->bind(':source', $name);
        $pages = $db->setQuery($query)->loadAssocList('item_id', 'hash');

        // The index may be behind the site
        $pages = array_intersect_key($pages, array_flip($source->ids()));

        $this->send(200, [
            'model'      => $settings->embeddingModel(),
            'dimensions' => $this->dimensions($db, $name),
            'pages'      => (object) $pages,
        ]);
    }

    /**
     * Pages by ID, with their passages: {documents: [{id, title, url, language, chunks: [{heading, text, embedding}]}]}
     */
    public function documents(): void
    {
        [$db, , $source] = $this->guard();

        $body = json_decode((string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES), true);
        $ids  = \array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($body['ids'] ?? []))))), 0, self::MAX_DOCUMENTS);
        $ids  = $source->visible($ids);

        if (!$ids) {
            $this->send(200, ['documents' => []]);
        }

        $name  = $source->name();
        $query = $db->getQuery(true)
            ->select('id, item_id, title, link, language')
            ->from($db->quoteName('#__mcpask_documents'))
            ->where('source = :source')
            ->where('status = ' . $db->quote('ok'))
            ->whereIn('item_id', $ids)
            ->bind(':source', $name);
        $rows  = $db->setQuery($query)->loadObjectList('id');

        $documents = [];

        foreach ($rows as $row) {
            $documents[(int) $row->id] = [
                'id'       => (int) $row->item_id,
                'title'    => $row->title,
                'url'      => $source->url($row->link),
                'language' => $row->language,
                'chunks'   => [],
            ];
        }

        if ($documents) {
            $query = $db->getQuery(true)
                ->select('document_id, heading, text, embedding')
                ->from($db->quoteName('#__mcpask_chunks'))
                ->whereIn('document_id', array_keys($documents))
                ->order('document_id, position');

            foreach ($db->setQuery($query)->loadObjectList() as $chunk) {
                $documents[(int) $chunk->document_id]['chunks'][] = ['heading' => $chunk->heading, 'text' => $chunk->text, 'embedding' => $chunk->embedding];
            }
        }

        $this->send(200, ['documents' => array_values($documents)]);
    }

    /**
     * Only a POST that carries this site's export key gets an answer.
     *
     * @return  array{0: DatabaseInterface, 1: Settings, 2: \Gss\Component\Mcpask\Administrator\Ask\Source\SourceInterface}
     */
    private function guard(): array
    {
        $settings = Settings::load();
        $server   = $this->app->getInput()->server;
        $key      = $settings->exportKey();

        // Without a key of its own, the site has nothing to offer: it looks as if this did not exist
        if ($key === '' || !$settings->enabled() || $this->app->get('offline')) {
            $this->send(404, ['error' => 'Not found.']);
        }

        if (strtoupper($this->app->getInput()->getMethod()) !== 'POST' || !hash_equals($key, $server->getString('HTTP_X_MCPASK_KEY', ''))) {
            $this->send(403, ['error' => 'The export key is missing or wrong.']);
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return [$db, $settings, Sources::current($db, $settings)];
    }

    /**
     * The length of the stored embeddings; the option may leave it to the model.
     */
    private function dimensions(DatabaseInterface $db, string $source): int
    {
        $query = $db->getQuery(true)
            ->select('c.embedding')
            ->from($db->quoteName('#__mcpask_chunks', 'c'))
            ->join('INNER', $db->quoteName('#__mcpask_documents', 'd'), 'd.id = c.document_id')
            ->where('d.source = :source')
            ->setLimit(1)
            ->bind(':source', $source, ParameterType::STRING);

        return \strlen((string) base64_decode((string) $db->setQuery($query)->loadResult(), true)) / 4;
    }

    private function send(int $status, array $payload): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('X-Robots-Tag: noindex');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $this->app->close();
    }
}
