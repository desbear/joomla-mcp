<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Field;

use Gss\Component\Mcpask\Administrator\Ask\Settings;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * This site's export key: shown in full, because it has to be copied to the other sites, with a
 * button that makes a random one and a button that copies it.
 *
 * @since  0.2.0
 */
class McpaskkeyField extends FormField
{
    protected $type = 'Mcpaskkey';

    protected function getInput()
    {
        $e = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        // The key is made in the browser, from its own source of randomness: 24 bytes, written as 48 characters
        Factory::getApplication()->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
            document.addEventListener('click', (event) => {
              const button = event.target.closest('[data-mcpask-key]');

              if (!button) {
                return;
              }

              const input = button.closest('.input-group').querySelector('input');

              if (button.dataset.mcpaskKey === 'copy') {
                navigator.clipboard.writeText(input.value).then(() => {
                  const label = button.textContent;

                  button.textContent = button.dataset.done;
                  window.setTimeout(() => { button.textContent = label; }, 1500);
                });

                return;
              }

              if (input.value.trim() && !window.confirm(button.dataset.confirm)) {
                return;
              }

              const bytes = window.crypto.getRandomValues(new Uint8Array(24));

              input.value = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
              input.dispatchEvent(new Event('change', { bubbles: true }));
            });
            JS);

        return '<div class="input-group">'
            . '<input type="text" class="form-control font-monospace" name="' . $e($this->name) . '" id="' . $e($this->id) . '"'
            . ' value="' . $e($this->value) . '" maxlength="999" autocomplete="off" spellcheck="false"'
            . ' minlength="' . Settings::MIN_EXPORT_KEY . '">'
            . '<button type="button" class="btn btn-primary" data-mcpask-key="generate" data-confirm="' . $e(Text::_('COM_MCPASK_KEY_REPLACE')) . '">'
            . '<span class="icon-refresh" aria-hidden="true"></span> ' . Text::_('COM_MCPASK_KEY_GENERATE') . '</button>'
            . '<button type="button" class="btn btn-secondary" data-mcpask-key="copy" data-done="' . $e(Text::_('COM_MCPASK_KEY_COPIED')) . '">'
            . Text::_('COM_MCPASK_KEY_COPY') . '</button>'
            . '</div>';
    }
}
