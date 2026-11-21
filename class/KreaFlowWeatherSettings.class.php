<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * License: GPL-3.0-or-later
 */

/** Validated weather configuration from the active native company context. */
class KreaFlowWeatherSettings
{
    /**
     * Read KREAFLOW_WEATHER_LATITUDE/LONGITUDE and optional LOCATION constants.
     * Missing or invalid coordinates disable weather without a location fallback.
     *
     * @param int $entity Requested company ID
     * @return array{query:string,location:string|null}|null
     */
    public static function load($entity)
    {
        global $conf;

        $entity = (int) $entity;
        if ($entity <= 0 || !isset($conf->entity) || $entity !== (int) $conf->entity) {
            return null;
        }

        $latitude = trim((string) getDolGlobalString('KREAFLOW_WEATHER_LATITUDE'));
        $longitude = trim((string) getDolGlobalString('KREAFLOW_WEATHER_LONGITUDE'));
        if (!self::coordinate($latitude, 90) || !self::coordinate($longitude, 180)) {
            return null;
        }

        $location = trim(strip_tags((string) getDolGlobalString('KREAFLOW_WEATHER_LOCATION')));
        return array(
            'query' => http_build_query(array('latitude' => (float) $latitude, 'longitude' => (float) $longitude), '', '&', PHP_QUERY_RFC3986),
            'location' => $location !== '' ? $location : null,
        );
    }

    /**
     * @param string $value Decimal coordinate
     * @param int $limit Absolute geographic bound
     * @return bool
     */
    private static function coordinate($value, $limit)
    {
        return preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/D', $value) === 1
            && is_finite((float) $value)
            && abs((float) $value) <= $limit;
    }
}
