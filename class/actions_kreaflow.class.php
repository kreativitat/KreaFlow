<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */


require_once __DIR__.'/actions_dolizsynch.class.php';

/** Native hook entry point; synchronization still requires its original activation flag. */
class ActionsKreaFlow extends ActionsDoliZSynch
{
    /** Apply the shared identity only to pages executed from this module. */
    public function llxHeader($parameters, &$object, &$action, $hookmanager)
    {
        $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
        $root = realpath(dirname(__DIR__));
        if (!$script || !$root || strpos($script, $root.DIRECTORY_SEPARATOR) !== 0) {
            return 0;
        }
        if (!isset($parameters['arrayofcss']) || !is_array($parameters['arrayofcss'])) {
            return 0;
        }
        // llxHeader passes these values by reference; native rendering stays in control.
        if (strpos(' '.$parameters['morecssonbody'].' ', ' kf-native ') === false) {
            $parameters['morecssonbody'] .= ' kf-app kf-native';
        }
        foreach (array('/kreaflow/css/design-tokens.css', '/kreaflow/css/design-system-native.css') as $css) {
            if (!in_array($css, $parameters['arrayofcss'], true)) {
                $parameters['arrayofcss'][] = $css;
            }
        }
        return 0;
    }

    public function getNomUrl($parameters, &$object, &$action)
    {
        if (!isModEnabled('dolizsynch')) { return 0; }
        return parent::getNomUrl($parameters, $object, $action);
    }

    public function completeTabsHead(&$parameters, &$object, &$action, $hookmanager)
    {
        if (!isModEnabled('dolizsynch')) { return 0; }
        return parent::completeTabsHead($parameters, $object, $action, $hookmanager);
    }

    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        if (!isModEnabled('dolizsynch')) { return 0; }
        return parent::doActions($parameters, $object, $action, $hookmanager);
    }

    public function formConfirm($parameters, &$object, &$action, $hookmanager)
    {
        if (!isModEnabled('dolizsynch')) { return 0; }
        return parent::formConfirm($parameters, $object, $action, $hookmanager);
    }

    public function objectLineView_BeforeProductExtrafield($parameters, &$object, &$action, $hookmanager)
    {
        if (!isModEnabled('dolizsynch')) { return 0; }
        return parent::objectLineView_BeforeProductExtrafield($parameters, $object, $action, $hookmanager);
    }

    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        if (!isModEnabled('dolizsynch')) { return 0; }
        return parent::formObjectOptions($parameters, $object, $action, $hookmanager);
    }

    public function printFieldListTitle($parameters, &$object, &$action, $hookmanager)
    {
        if (!isModEnabled('dolizsynch')) { return 0; }
        return parent::printFieldListTitle($parameters, $object, $action, $hookmanager);
    }

    public function printFieldListValue($parameters, &$object, &$action, $hookmanager)
    {
        if (!isModEnabled('dolizsynch')) { return 0; }
        return parent::printFieldListValue($parameters, $object, $action, $hookmanager);
    }

    /** Preserve old REST callers with a same-origin, method-preserving redirect. */
    public function beforeApiCall($parameters, &$object, &$action, $hookmanager)
    {
        if (!isModEnabled('dolizsynch') || ($parameters['moduleobject'] ?? '') !== 'dolizsynch') { return 0; }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $prefix = DOL_URL_ROOT.'/api/index.php/dolizsynch';
        if (strpos($uri, $prefix) !== 0 || !in_array(substr($uri, strlen($prefix), 1), array('', '/', '?'), true)) { return 0; }
        $suffix = substr($uri, strlen($prefix));
        if (strpos($suffix, "\r") !== false || strpos($suffix, "\n") !== false) { return 0; }
        header('Location: '.DOL_URL_ROOT.'/api/index.php/kreaflow'.$suffix, true, 307);
        exit;
    }
}
