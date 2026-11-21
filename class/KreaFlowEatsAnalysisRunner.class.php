<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

require_once __DIR__.'/KreaFlowEatsAnalysis.class.php';
require_once __DIR__.'/KreaFlowWeatherSettings.class.php';

/** Shared persistence orchestration; callers supply freshly permission-filtered weekly facts. */
class KreaFlowEatsAnalysisRunner
{
    public static function run($db, $entity, $userId, $label, array $week, $previous)
    {
        if (!KreaFlowEatsAnalysis::available()) { throw new RuntimeException('Análise de IA desativada.', 403); }
        require_once __DIR__.'/KreaFlowEatsAnalysisStore.class.php';
        $store = new KreaFlowEatsAnalysisStore($db, (int) $entity);
        $facts = array('current'=>KreaFlowEatsAnalysis::facts($week), 'previous'=>$previous);
        // Reuse a valid pre-upgrade session result without another paid generation.
        $legacyKey = (int) $userId.':'.(int) $entity.':'.$week['period']['start'];
        $legacy = $_SESSION['kreaflow_eats_analysis'][$legacyKey] ?? null;
        $legacyHash = hash('sha256', json_encode($facts).getDolGlobalString('KREAPRODUCTS_LLM_PROVIDER').getDolGlobalString('KREAPRODUCTS_LLM_MODEL'));
        $weatherSettings = KreaFlowWeatherSettings::load($entity);
        $coords = $weatherSettings !== null ? $weatherSettings['query'] : '';
        $weather = $coords ? self::fetchJsonUrl('https://archive-api.open-meteo.com/v1/archive?'.$coords
            .'&start_date='.$week['period']['start'].'&end_date='.$week['period']['end']
            .'&daily=temperature_2m_mean,precipitation_sum&timezone=Europe%2FLisbon') : null;
        $facts['weather'] = KreaFlowEatsAnalysis::weather($weather ?? array(), $week['period']['start'], $week['period']['end']);
        $facts['weather']['location'] = $weatherSettings !== null ? $weatherSettings['location'] : null;
        try {
            $facts['weather'] = KreaFlowEatsAnalysis::preserveWeather($facts['weather'], $store->previousWeather($week['period']['start'], $week['period']['end']), (int) round((strtotime($week['period']['end']) - strtotime($week['period']['start'])) / 86400) + 1);
            $result = $store->remember($week['period']['start'], $week['period']['end'], $facts, (int) $userId, function () use ($facts, $week, $legacy, $legacyHash) {
                if ($legacy && $legacy['hash'] === $legacyHash && ($legacy['result']['weather'] ?? null) === $facts['weather']) {
                    return $legacy['result'];
                }
                $analysis = (new KreaFlowEatsAnalysis())->generate($facts);
                $analysis['period'] = $week['period'];
                return $analysis;
            });
        } catch (Exception $e) {
            $code = in_array($e->getCode(), array(409,502,503), true) ? $e->getCode() : 503;
            $message = $code === 409 ? 'Esta análise já está em processamento. Atualize para consultar o resultado guardado.'
                : ($code === 502 ? 'A tentativa de análise falhou. Não será repetida automaticamente para estes dados.'
                : 'Não foi possível consultar ou guardar a análise. Contacte o administrador.');
            throw new RuntimeException($message, $code);
        }
        unset($_SESSION['kreaflow_eats_analysis'][$legacyKey]);
        return $result;
    }
	private static function fetchJsonUrl($url, $timeoutSeconds = 8)
	{
		$url = trim((string) $url);
		if ($url === '') {
			return null;
		}

		$timeoutSeconds = (int) $timeoutSeconds;
		if ($timeoutSeconds <= 0) {
			$timeoutSeconds = 8;
		}

		$rawBody = false;
		if (function_exists('curl_init')) {
			$ch = curl_init();
			if ($ch !== false) {
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
				curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
				curl_setopt($ch, CURLOPT_USERAGENT, 'KreaFlowMobile/1.0');
				$rawBody = curl_exec($ch);
				$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
				curl_close($ch);
				if ($rawBody === false || $httpCode < 200 || $httpCode >= 300) {
					$rawBody = false;
				}
			}
		}

		if ($rawBody === false) {
			$httpContext = stream_context_create(array(
				'http' => array(
					'method' => 'GET',
					'timeout' => $timeoutSeconds,
					'ignore_errors' => true,
					'header' => "Accept: application/json\r\nUser-Agent: KreaFlowMobile/1.0\r\n",
				),
			));
			$rawBody = @file_get_contents($url, false, $httpContext);
			if ($rawBody === false) {
				return null;
			}
		}

		$decoded = json_decode($rawBody, true);
		if (!is_array($decoded)) {
			return null;
		}

		return $decoded;
	}

}
