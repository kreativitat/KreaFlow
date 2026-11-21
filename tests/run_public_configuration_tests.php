<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * License: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__.'/../class/KreaFlowWeatherSettings.class.php';
require_once __DIR__.'/../class/KreaFlowKitchenSettings.class.php';
define('MAIN_DB_PREFIX', 'llx_');

$values = array();
$reads = 0;
function getDolGlobalString($name, $default = '')
{
    global $values, $reads;
    $reads++;
    return $values[$name] ?? $default;
}

$checks = 0;
function checkPublicConfiguration($condition)
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException('Public configuration check '.$checks.' failed'); }
}

$conf = (object) array('entity' => 42);
checkPublicConfiguration(KreaFlowWeatherSettings::load(42) === null);
$values = array('KREAFLOW_WEATHER_LATITUDE' => '0', 'KREAFLOW_WEATHER_LONGITUDE' => '0');
checkPublicConfiguration(KreaFlowWeatherSettings::load(42) === array('query' => 'latitude=0&longitude=0', 'location' => null));
$values['KREAFLOW_WEATHER_LATITUDE'] = '-12.5';
$values['KREAFLOW_WEATHER_LONGITUDE'] = '170.25';
$values['KREAFLOW_WEATHER_LOCATION'] = '<b>Example city</b>';
checkPublicConfiguration(KreaFlowWeatherSettings::load(42) === array('query' => 'latitude=-12.5&longitude=170.25', 'location' => 'Example city'));
$before = $reads;
checkPublicConfiguration(KreaFlowWeatherSettings::load(43) === null && $reads === $before);
checkPublicConfiguration(KreaFlowWeatherSettings::load(0) === null && $reads === $before);
foreach (array('', '90.01', '-90.01', 'NaN', 'INF', '1&url=https://example.invalid', '1e5') as $invalid) {
    $values['KREAFLOW_WEATHER_LATITUDE'] = $invalid;
    checkPublicConfiguration(KreaFlowWeatherSettings::load(42) === null);
}
$values['KREAFLOW_WEATHER_LATITUDE'] = '90';
foreach (array('', '180.01', '-180.01', '1,2') as $invalid) {
    $values['KREAFLOW_WEATHER_LONGITUDE'] = $invalid;
    checkPublicConfiguration(KreaFlowWeatherSettings::load(42) === null);
}
$values['KREAFLOW_WEATHER_LONGITUDE'] = '-180';
checkPublicConfiguration(KreaFlowWeatherSettings::load(42)['query'] === 'latitude=90&longitude=-180');

class PublicConfigurationDb
{
    public $value;
    public $sql;
    public function query($sql) { $this->sql = $sql; return true; }
    public function fetch_object($result) { return $this->value === null ? false : (object) array('value' => $this->value); }
}
$db = new PublicConfigurationDb();
checkPublicConfiguration(KreaFlowKitchenSettings::load($db, 42)['ids'] === array());
$db->value = '1,3,0,-1,invalid';
checkPublicConfiguration(KreaFlowKitchenSettings::load($db, 42)['ids'] === array('1', '3'));
checkPublicConfiguration(strpos($db->sql, 'entity = 42') !== false);
$db->value = '0';
checkPublicConfiguration(KreaFlowKitchenSettings::load($db, 42)['ids'] === array());
checkPublicConfiguration(KreaFlowKitchenSettings::defaults('EXAMPLE') === array());
echo $checks." public configuration checks passed.\n";
