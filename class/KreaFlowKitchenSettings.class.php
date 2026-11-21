<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

/** Entity-owned station selection; no cross-company defaults or business writes. */
class KreaFlowKitchenSettings
{
    /** Reuse DoliZSynch's exact operational-date convention, including its cutoff second. */
    public static function workingDay($now = null)
    {
        dol_include_once('/kreaflow/lib/shiftplanner.lib.php');
        if (!function_exists('shiftplanner_build_operational_date_map_from_range')) {
            throw new RuntimeException('DoliZSynch working-day configuration unavailable');
        }
        $zone = new DateTimeZone('Europe/Lisbon');
        $now = $now ? $now->setTimezone($zone) : new DateTimeImmutable('now', $zone);
        $hour = shiftplanner_get_day_change_hour();
        $map = shiftplanner_build_operational_date_map_from_range($now->format('Y-m-d H:i:s'), $now->modify('+1 second')->format('Y-m-d H:i:s'), $hour);
        $day = key($map);
        if (!$day) { throw new RuntimeException('Working-day boundary unavailable'); }
        $start = new DateTimeImmutable($day.' '.sprintf('%02d:00:%02d', $hour, $hour > 0 ? 1 : 0), $zone);
        return array('day' => $day, 'timezone' => 'Europe/Lisbon', 'window_start' => $start->format(DateTimeInterface::ATOM),
            'window_end' => $start->modify('+1 day')->format(DateTimeInterface::ATOM));
    }

    /**
     * Keep the compatibility entry point without deployment-specific station defaults.
     *
     * @param string $label Company label (not used to select stations)
     * @return array<string, string>
     */
    public static function defaults($label)
    {
        return array();
    }

    /**
     * Read only the requested company's explicit station selection.
     *
     * @param DoliDB $db Database connection
     * @param int $entity Company ID
     * @return array{ids:array<int,string>,defaults:array<string,string>}
     */
    public static function load($db, $entity)
    {
        $entity = (int) $entity;
        if ($entity <= 0) {
            throw new RuntimeException('Kitchen company configuration unavailable');
        }
        $res = $db->query("SELECT value FROM ".MAIN_DB_PREFIX."const WHERE name = 'KREAFLOW_KITCHEN_STATIONS' AND entity = ".$entity);
        if (!$res) { throw new RuntimeException('Kitchen station configuration unavailable'); }
        $saved = $db->fetch_object($res);
        $ids = $saved ? array_values(array_filter(explode(',', (string) $saved->value), static function ($id) {
            return ctype_digit($id) && (int) $id > 0;
        })) : array();
        return array('ids' => $ids, 'defaults' => array());
    }

    public static function scope($data, $ids)
    {
        $ids = array_map('strval', $ids);
        foreach (array('items', 'products') as $key) {
            $data[$key] = array_values(array_filter($data[$key], static function ($row) use ($ids) {
                return in_array((string) ($row['centre'] ?? ''), $ids, true);
            }));
        }
        $counts = array_fill_keys(array('waiting', 'preparing', 'ready', 'completed', 'cancelled', 'unknown'), 0);
        $durations = array();
        foreach ($data['items'] as $item) {
            $status = isset($counts[$item['status'] ?? '']) ? $item['status'] : 'unknown';
            $counts[$status]++;
            if (in_array($status, array('ready', 'completed'), true) && is_numeric($item['duration_seconds'] ?? null)) {
                $value = (float) $item['duration_seconds'];
                if ($value >= 0 && $value <= 172800) { $durations[] = $value; }
            }
        }
        sort($durations, SORT_NUMERIC);
        $n = count($durations);
        $data['counts'] = $counts;
        $data['timings'] = array('samples' => $n, 'eligible_lines' => $counts['ready'] + $counts['completed'],
            'average_seconds' => $n ? (int) round(array_sum($durations) / $n) : null,
            'p90_seconds' => $n ? $durations[(int) ceil($n * .9) - 1] : null);
        $data['stations'] = array_values(array_filter($data['stations'] ?? array(), static function ($station) use ($ids) {
            return in_array((string) $station['id'], $ids, true);
        }));
        $data['configured_stations'] = $ids;
        return $data;
    }
}
