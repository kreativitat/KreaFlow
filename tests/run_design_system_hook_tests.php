<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
/** Header isolation checks: no database bootstrap or business writes. */
define('DOL_DOCUMENT_ROOT', dirname(__DIR__, 3));
require_once __DIR__.'/../class/actions_kreaflow.class.php';
$hooks = new ActionsKreaFlow(null);
$object = null;
$action = '';
$manager = null;
$count = 0;
foreach (array(
    __DIR__.'/../admin/setup.php' => true,
    __DIR__.'/../admin/kitchen.php' => true,
    DOL_DOCUMENT_ROOT.'/product/card.php' => false,
    DOL_DOCUMENT_ROOT.'/custom/kreaeats/kreaeatsindex.php' => false,
    __DIR__.'/../../kreaflow-foreign/admin/setup.php' => false,
    '' => false,
) as $script => $expected) {
    $_SERVER['SCRIPT_FILENAME'] = $script;
    $css = array('/existing.css');
    $body = 'existing-body';
    $parameters = array('arrayofcss' => &$css, 'morecssonbody' => &$body);
    $result = $hooks->llxHeader($parameters, $object, $action, $manager);
    if ($result !== 0 || (count($css) === 3) !== $expected || (strpos($body, 'kf-native') !== false) !== $expected || $css[0] !== '/existing.css') {
        throw new RuntimeException('Header scope failed: '.$script);
    }
    ++$count;
}
echo $count." header isolation checks passed\n";
