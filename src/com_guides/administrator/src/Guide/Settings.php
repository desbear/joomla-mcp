<?php

/**
 * @package     Guides
 * @subpackage  com_guides
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Guides\Administrator\Guide;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * The component's options, with their defaults.
 *
 * @since  0.1.0
 */
final class Settings
{
    public function __construct(private Registry $params)
    {
    }

    public static function load(): self
    {
        return new self(ComponentHelper::getParams('com_guides'));
    }

    /**
     * "type_tags": guides go to one category (a site whose categories are types of article) and
     * are found by their product and feature tags. "topic": a site whose categories are subjects,
     * where each guide goes to the category its sources share.
     */
    public function placementMode(): string
    {
        return $this->params->get('placement_mode', 'type_tags') === 'topic' ? 'topic' : 'type_tags';
    }

    public function guideCategory(): int
    {
        return (int) $this->params->get('guide_category', 0);
    }

    /**
     * "content" (Joomla articles) or "flexicontent", when FLEXIcontent is there.
     */
    public function targetType(): string
    {
        return $this->params->get('target_type', 'content') === 'flexicontent' && Flexicontent::isAvailable() ? 'flexicontent' : 'content';
    }

    public function fcType(): int
    {
        return (int) $this->params->get('fc_type', 0);
    }

    public function publishState(): int
    {
        return (int) $this->params->get('publish_state', 1) ? 1 : 0;
    }

    public function publishAccess(): int
    {
        return max(1, (int) $this->params->get('publish_access', 1));
    }

    /**
     * Where AI drafting is sent: Ask the Site's own connection when that is chosen and installed,
     * otherwise this component's.
     *
     * @return  array{base: string, key: string, style: string, model: string, shared: bool}
     */
    public function ai(): array
    {
        $ask = 'Gss\\Component\\Mcpask\\Administrator\\Ask\\Settings';

        if ($this->params->get('ai_mode', 'mcpask') === 'mcpask' && ComponentHelper::isEnabled('com_mcpask') && class_exists($ask)) {
            $settings = $ask::load();

            return ['base' => $settings->apiBase(), 'key' => $settings->apiKey(), 'style' => $settings->apiStyle(), 'model' => $settings->chatModel(), 'shared' => true];
        }

        return [
            'base'   => rtrim(trim((string) $this->params->get('api_base', '')) ?: 'https://api.openai.com/v1', '/'),
            'key'    => trim((string) $this->params->get('api_key', '')),
            'style'  => $this->params->get('api_style', 'chat') === 'responses' ? 'responses' : 'chat',
            'model'  => trim((string) $this->params->get('chat_model', '')) ?: 'gpt-4.1-mini',
            'shared' => false,
        ];
    }
}
