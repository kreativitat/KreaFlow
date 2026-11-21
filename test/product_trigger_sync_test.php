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

// CLI-only regression: run the real trigger with a recording synchronization boundary.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

class DolibarrTriggers
{
    public $db;
    public $name;
    public $family;
    public $description;
    public $version;
    public $picto;
}
class User {}
class Translate {}
class Conf
{
    public $global;
}
class ZSProductSynch
{
    public static $calls = array();

    public function __construct($db) {}

    public function updateZsbmsProduct($product, $force = false, $levels = array())
    {
        self::$calls[] = array('id' => $product->id, 'levels' => $levels);
        return true;
    }
}
function isModEnabled($module) { return true; }
function GETPOST($name, $filter) { return $GLOBALS['requestAction']; }
function dol_syslog($message, $level = 0) {}

// Exclude dependency includes only; execute the complete production trigger class unchanged.
$source = file_get_contents(dirname(__DIR__) . '/core/triggers/interface_99_modDoliZSynch_DoliZSynchTriggers.class.php');
$offset = strpos($source, 'class InterfaceDoliZSynchTriggers extends DolibarrTriggers');
if ($offset === false) {
    throw new RuntimeException('Production trigger class was not found');
}
eval(substr($source, $offset));

$conf = new Conf();
$conf->global = (object) array('ZS_API_STORE' => 1);
$trigger = new InterfaceDoliZSynchTriggers(null);
$user = new User();
$langs = new Translate();
$product = (object) array('id' => 10766, 'ref' => 'fixture', 'array_options' => array('options_zs_synch' => 1));
$cases = array(
    array('manual price save', '/custom/kreaflow/sellPrice.php', 'update_price', array(1), true, array(array(1))),
    array('simulation price save', '/custom/kreaflow/sellPrice.php', 'update_sim_price', array(2), true, array(array(2))),
    array('multiple edited levels', '/custom/kreaflow/sellPrice.php', 'update_price', array(1, 3), true, array(array(1), array(3))),
    array('unchanged price', '/custom/kreaflow/sellPrice.php', 'update_price', array(), true, array()),
    array('VAT-only save', '/custom/kreaflow/sellPrice.php', 'update_vat', array(1, 2), true, array(array())),
    array('native product edit', '/product/card.php', 'update', array(), true, array(array())),
    array('module product edit', '/custom/kreaflow/zsproduct.php', 'update', array(), true, array(array())),
    array('native price save', '/product/price.php', 'update_price', array(1), false, array(array(1))),
    array('native level save', '/product/price.php', 'update_level_price', array(4), false, array(array(4))),
    array('level zero normalization', '/product/price.php', 'update_price', array(0), false, array(array(1))),
    array('inbound API', '/api/index.php', 'update_price', array(1), true, array()),
    array('background cron', '/cron/cron_run_jobs.php', 'update_price', array(1), true, array()),
);
$failures = array();
foreach ($cases as list($label, $script, $requestAction, $levels, $productUpdate, $expected)) {
    $_SERVER['SCRIPT_NAME'] = $script;
    ZSProductSynch::$calls = array();
    foreach ($levels as $level) {
        $product->level = $level;
        $trigger->runTrigger('PRODUCT_PRICE_MODIFY', $product, $user, $langs, $conf);
    }
    if ($productUpdate) {
        $trigger->runTrigger('PRODUCT_MODIFY', $product, $user, $langs, $conf);
    }
    $actual = array_column(ZSProductSynch::$calls, 'levels');
    if ($actual !== $expected) {
        $failures[] = $label;
        echo '[FAIL] ' . $label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual) . "\n";
    } else {
        echo '[PASS] ' . $label . "\n";
    }
}
exit($failures ? 1 : 0);
