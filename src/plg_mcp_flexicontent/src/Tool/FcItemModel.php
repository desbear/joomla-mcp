<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_flexicontent
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Flexicontent\Tool;

use Joomla\Filesystem\Path;
use Joomla\CMS\Form\Form;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * FLEXIcontent's back-end item model, usable from the API application.
 *
 * Only reference this class after FcEnvironment::boot() registered FlexicontentModelItem.
 *
 * @since  0.4.0
 */
class FcItemModel extends \FlexicontentModelItem
{
    /**
     * Joomla derives the model name from the class name; FLEXIcontent builds its table names
     * (flexicontent_items) and events context from it, so keep the original model's name.
     */
    protected $name = 'item';

    /**
     * Same as FCModelAdmin::mergeAttributes() (FLEXIcontent 4.2), but reads the form XML files from
     * the administrator folder: the original builds the path from JPATH_BASE, which is /api here,
     * and throws when the file is missing - on every save.
     */
    public function mergeAttributes(&$item, &$data, $properties, $options)
    {
        $form = $this->getForm();

        $this->handlePartialForm($form, $data);
        $this->_canonicalData($form, $data);

        if (isset($options['params_fset'], $options['layout_type'])) {
            \JLoader::register('flexicontent_tmpl', JPATH_SITE . '/components/com_flexicontent/classes/helpers/tmpl.php');
            $layout_data = \flexicontent_tmpl::mergeLayoutParams($item, $data, $options);

            unset($data[$options['params_fset']]['layouts']);
        }

        $registries = [];

        foreach ($properties as $prop) {
            $registries[$prop] = new Registry();
        }

        foreach ($options['model_names'] as $extension => $modelName) {
            $file = null;

            foreach (['/models/forms/', '/forms/'] as $dir) {
                $candidate = Path::clean(JPATH_ADMINISTRATOR . '/components/' . $extension . $dir . $modelName . '.xml');

                if (is_file($candidate)) {
                    $file = $candidate;
                    break;
                }
            }

            if ($file === null || !($xml = simplexml_load_file($file))) {
                throw new \RuntimeException(\sprintf('Form XML file of %s.%s not found', $extension, $modelName), 500);
            }

            $jform = new Form($extension . '.' . $modelName, ['control' => 'jform', 'load_data' => false]);
            $jform->load($xml->asXML());

            foreach ($properties as $prop) {
                if (isset($data[$prop]) && \is_array($data[$prop])) {
                    $dbData = $jform->filter([$prop => (new Registry($item->$prop))->toArray()]);

                    if (!empty($dbData[$prop])) {
                        $registries[$prop]->loadArray($dbData[$prop]);
                    }
                }
            }
        }

        foreach ($properties as $prop) {
            if (isset($data[$prop]) && \is_array($data[$prop])) {
                $registries[$prop] = $this->maintainDbData($registries[$prop], $data[$prop]);

                if (!empty($layout_data) && $prop == $options['params_fset']) {
                    $registries[$prop] = $this->maintainDbData($registries[$prop], $layout_data);
                }

                $item->$prop = $registries[$prop]->toString();
            }
        }
    }
}
