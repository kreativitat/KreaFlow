<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
	define('DOL_DOCUMENT_ROOT', dirname(__DIR__, 3));
}

require_once __DIR__.'/../class/KreaFlowMobileApi.class.php';

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function assertForecastCondition($condition, $message)
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

/**
 * @param object $object
 * @param string $methodName
 * @param array<int, mixed> $arguments
 * @return mixed
 */
function invokeForecastPrivate($object, $methodName, array $arguments)
{
	$method = new ReflectionMethod(get_class($object), $methodName);
	$method->setAccessible(true);
	return $method->invokeArgs($object, $arguments);
}

/**
 * Minimal read-only database stub for entity-allowlist tests.
 */
class KreaFlowForecastConfigDbStub
{
	/** @var string|null */
	private $config;

	/** @var array<int, string> */
	private $labels;

	/**
	 * @param string|null $config
	 * @param array<int, string> $labels
	 */
	public function __construct($config, array $labels)
	{
		$this->config = $config;
		$this->labels = $labels;
	}

	public function prefix()
	{
		return 'llx_';
	}

	public function query($sql)
	{
		return array('sql' => (string) $sql);
	}

	public function fetch_object($result)
	{
		$sql = isset($result['sql']) ? (string) $result['sql'] : '';
		if (strpos($sql, 'KREAFLOW_STOCK_FORECAST_ENTITIES') !== false) {
			return $this->config === null ? false : (object) array('value' => $this->config);
		}
		if (preg_match('/rowid = ([0-9]+)/', $sql, $matches)) {
			$entityId = (int) $matches[1];
			return isset($this->labels[$entityId]) ? (object) array('label' => $this->labels[$entityId]) : false;
		}
		return false;
	}

	public function free($result)
	{
		return true;
	}

	public function lasterror()
	{
		return '';
	}
}

/**
 * @param object $object
 * @param string $propertyName
 * @param mixed $value
 * @return void
 */
function setForecastPrivateProperty($object, $propertyName, $value)
{
	$property = new ReflectionProperty(get_class($object), $propertyName);
	$property->setAccessible(true);
	$property->setValue($object, $value);
}

date_default_timezone_set('Europe/Lisbon');
$reflection = new ReflectionClass(KreaFlowMobileApi::class);
$api = $reflection->newInstanceWithoutConstructor();
$apiSource = file_get_contents(__DIR__.'/../class/KreaFlowMobileApi.class.php');
assertForecastCondition($apiSource !== false, 'KreaFlow forecast API source must be readable.');
assertForecastCondition(
	strpos($apiSource, '$startDateTime = $trainingRange[\'start_date\']') !== false,
	'Forecast data loading must use the fixed training interval, not the visualization interval.'
);
assertForecastCondition(
	strpos($apiSource, '$this->buildDailySeries($dailyRows, $trainingRange[\'start_date\'], $trainingRange[\'end_date\'])') !== false,
	'Forecast model input must be built from the fixed training interval.'
);

setForecastPrivateProperty($api, 'db', new KreaFlowForecastConfigDbStub('1,2,5', array()));
assertForecastCondition(
	invokeForecastPrivate($api, 'isStockForecastEnabledForEntity', array(1)) === true,
	'Configured stock-forecast entities must be allowed.'
);
assertForecastCondition(
	invokeForecastPrivate($api, 'isStockForecastEnabledForEntity', array(3)) === false,
	'Entities outside the configured stock-forecast allowlist must be denied.'
);
setForecastPrivateProperty($api, 'db', new KreaFlowForecastConfigDbStub(null, array(1 => 'EXAMPLE_A', 3 => 'EXAMPLE_B')));
assertForecastCondition(
	invokeForecastPrivate($api, 'isStockForecastEnabledForEntity', array(1)) === false,
	'An unconfigured allowlist must deny every company regardless of label.'
);
assertForecastCondition(
	invokeForecastPrivate($api, 'isStockForecastEnabledForEntity', array(3)) === false,
	'Other companies must remain disabled before the global allowlist is initialized.'
);

$trainingRange = invokeForecastPrivate($api, 'buildForecastTrainingRange', array('2026-08-24', 365));
assertForecastCondition($trainingRange['end_date'] === '2026-08-24', 'Forecast training must end on the supplied completed day.');
assertForecastCondition($trainingRange['day_count'] === 365, 'Forecast training must retain the optimized 365-day interval.');
assertForecastCondition(
	(int) floor((strtotime($trainingRange['end_date'].' 12:00:00') - strtotime($trainingRange['start_date'].' 12:00:00')) / 86400) + 1 === 365,
	'Forecast training range must contain exactly 365 completed calendar days.'
);

$series = array();
$weather = array();
$startTimestamp = strtotime('2026-06-26 12:00:00');
for ($index = 0; $index < 60; $index++) {
	$dateValue = date('Y-m-d', strtotime('+'.$index.' days', $startTimestamp));
	$weekday = (int) date('w', strtotime($dateValue.' 12:00:00'));
	$revenueTtc = 100.0;
	if ($weekday === 0) {
		$revenueTtc = 300.0;
	} elseif ($weekday === 6) {
		$revenueTtc = 400.0;
	}
	if ($index === 43) {
		$revenueTtc = 10000.0;
	}
	$series[] = array(
		'sale_date' => $dateValue,
		'revenue_ht' => $revenueTtc / 1.23,
		'revenue_ttc' => $revenueTtc,
	);
	$weather[$dateValue] = array('temp' => 20.0, 'precip' => 0.0);
}

$model = invokeForecastPrivate($api, 'buildSalesForecastModel', array($series, $weather, array(), 'revenue_ttc'));
$mondayPrediction = invokeForecastPrivate(
	$api,
	'predictSalesForecastValue',
	array($model, $series, '2026-08-31', 66, null, 0.0, 0.0)
);
$saturdayPrediction = invokeForecastPrivate(
	$api,
	'predictSalesForecastValue',
	array($model, $series, '2026-08-29', 64, 20.0, 0.0, 0.0)
);

assertForecastCondition(is_finite($mondayPrediction), 'Missing forecast temperature must be imputed to a finite value.');
assertForecastCondition($saturdayPrediction > $mondayPrediction * 2.0, 'Saturday seasonality must remain materially above Monday seasonality.');
assertForecastCondition($saturdayPrediction < 1500.0, 'A single extreme historical value must not destabilize the forecast.');

$rainLabels = invokeForecastPrivate($api, 'classifyPrecipitationLabels', array(2.5));
assertForecastCondition($rainLabels['intensity'] === 'Chuva leve', 'Rain intensity classification is incorrect.');
assertForecastCondition($rainLabels['probability'] === 'Média', 'Legacy rain probability label must remain backward compatible.');
$rainProbability = invokeForecastPrivate($api, 'normalizePrecipitationProbability', array(173.2));
assertForecastCondition($rainProbability === 100.0, 'Forecast rain probability must be clamped to 100 percent.');

$percentile = invokeForecastPrivate($api, 'calculatePercentile', array(array(10, 20, 30, 40), 0.5));
assertForecastCondition(abs($percentile - 25.0) < 0.00001, 'Percentile interpolation is incorrect.');

$stockConsumption = array();
$stockRevenue = array();
$stockStartTimestamp = strtotime('2026-07-27 12:00:00');
for ($index = 0; $index < 28; $index++) {
	$dateValue = date('Y-m-d', strtotime('+'.$index.' days', $stockStartTimestamp));
	$isMonday = (int) date('w', strtotime($dateValue.' 12:00:00')) === 1;
	$stockConsumption[] = array(
		'date' => $dateValue,
		'consumption' => $isMonday ? 20.0 : 10.0,
	);
	$stockRevenue[$dateValue] = 1000.0;
}
$stockDemand = invokeForecastPrivate(
	$api,
	'calculateStockForecastDemand',
	array(
		$stockConsumption,
		$stockRevenue,
		array(
			array('date' => '2026-08-24', 'forecast_ttc' => 2000.0),
			array('date' => '2026-08-25', 'forecast_ttc' => 2000.0),
		)
	)
);
assertForecastCondition(
	$stockDemand['daily'][0]['required_quantity'] > $stockDemand['daily'][1]['required_quantity'],
	'PATTIES demand must retain matching-weekday consumption seasonality.'
);
assertForecastCondition(
	abs($stockDemand['forecast_required'] - array_sum(array_column($stockDemand['daily'], 'required_quantity'))) < 0.00001,
	'PATTIES forecast total must equal the sum of its daily requirements.'
);

$stockDemandWithoutSalesHistory = invokeForecastPrivate(
	$api,
	'calculateStockForecastDemand',
	array($stockConsumption, array(), array(array('date' => '2026-08-24', 'forecast_ttc' => 2000.0)))
);
assertForecastCondition(
	abs($stockDemandWithoutSalesHistory['forecast_required']) < 0.00001,
	'Stock demand must remain zero when no historical consumption-to-sales rate can be established.'
);

$deliveryRows = array(
	array('date' => '2026-08-24', 'required_quantity' => 10.0), // Monday
	array('date' => '2026-08-25', 'required_quantity' => 20.0), // Tuesday
	array('date' => '2026-08-26', 'required_quantity' => 30.0), // Wednesday
	array('date' => '2026-08-27', 'required_quantity' => 40.0), // Thursday
	array('date' => '2026-08-28', 'required_quantity' => 50.0), // Friday
	array('date' => '2026-08-29', 'required_quantity' => 60.0), // Saturday
	array('date' => '2026-08-30', 'required_quantity' => 70.0), // Sunday
);
$deliveryRequirements = invokeForecastPrivate($api, 'buildStockDeliveryRequirements', array($deliveryRows));
assertForecastCondition(count($deliveryRequirements['deliveries']) === 5, 'Only five delivery days must be emitted per full week.');
assertForecastCondition(
	abs($deliveryRequirements['deliveries'][1]['required_quantity'] - 50.0) < 0.00001,
	'Tuesday delivery must include Wednesday demand.'
);
assertForecastCondition(
	$deliveryRequirements['deliveries'][1]['covered_dates'] === array('2026-08-25', '2026-08-26'),
	'Tuesday delivery must identify Tuesday and Wednesday as covered dates.'
);
assertForecastCondition(
	abs($deliveryRequirements['deliveries'][4]['required_quantity'] - 130.0) < 0.00001,
	'Saturday delivery must include Sunday demand.'
);
assertForecastCondition(
	$deliveryRequirements['deliveries'][4]['covered_dates'] === array('2026-08-29', '2026-08-30'),
	'Saturday delivery must identify Saturday and Sunday as covered dates.'
);

echo "Forecast calculation tests passed.\n";
