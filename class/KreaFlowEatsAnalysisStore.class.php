<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

/** Persistent, entity-scoped generation ledger; no financial document side effects. */
class KreaFlowEatsAnalysisStore
{
    private $db;
    private $entity;
    private $table;

    public function __construct($db, $entity)
    {
        if ((int) $entity <= 0) { throw new InvalidArgumentException('Invalid entity'); }
        $this->db = $db;
        $this->entity = (int) $entity;
        $this->table = MAIN_DB_PREFIX.'kreaflow_eats_analysis';
    }

    private function query($sql)
    {
        $result = $this->db->query($sql);
        if (!$result) { throw new RuntimeException('Analysis database unavailable', 503); }
        return $result;
    }

    private function periodWhere($start, $end)
    {
        return 'entity = '.$this->entity." AND date_start = '".$this->db->escape($start)
            ."' AND date_end = '".$this->db->escape($end)."'";
    }

    /** Only public weather context is reusable across financial permission variants. */
    public function previousWeather($start, $end)
    {
        $result = $this->query('SELECT weather_json FROM '.$this->table.' WHERE '.$this->periodWhere($start, $end).' AND status = 1 ORDER BY rowid DESC LIMIT 1');
        $row = $this->db->fetch_object($result);
        $this->db->free($result);
        return $row ? (json_decode($row->weather_json, true) ?: array()) : array();
    }

    /** Reference changes, not cache age or model selection, control generation. */
    public static function fingerprint(array $facts)
    {
        foreach (array('current','previous') as $key) {
            if (isset($facts[$key]['period'])) {
                // Navigation availability changes with time but is not reference evidence.
                unset($facts[$key]['period']['previous'], $facts[$key]['period']['next']);
            }
        }
        return hash('sha256', json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function readResult($row)
    {
        if ((int) $row->status === 0) { throw new RuntimeException('Analysis already started; do not replay', 409); }
        if ((int) $row->status !== 1) { throw new RuntimeException('Analysis attempt failed; do not replay', 502); }
        $result = json_decode($row->result_json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($result) || !isset($result['sections'])) { throw new RuntimeException('Invalid saved analysis', 503); }
        return $result;
    }

    /**
     * At most one provider attempt per immutable reference fingerprint.
     * Commit the claim before remote I/O; crashes/timeouts must never silently replay it.
     */
    public function remember($start, $end, array $facts, $userId, callable $generate)
    {
        $hash = self::fingerprint($facts);
        $where = $this->periodWhere($start, $end)." AND data_hash = '".$hash."'";
        $lockName = 'kf_eats_'.substr(hash('sha256', $this->table.':'.$this->entity.':'.$start.':'.$end.':'.$hash), 0, 48);
        $result = $this->query("SELECT GET_LOCK('".$lockName."', 0) AS acquired");
        $lock = $this->db->fetch_object($result);
        $this->db->free($result);
        if (!$lock || (int) $lock->acquired !== 1) { throw new RuntimeException('Analysis in progress', 409); }
        $transaction = false;
        try {
            $result = $this->query('SELECT status, result_json FROM '.$this->table.' WHERE '.$where.' LIMIT 1');
            $row = $this->db->fetch_object($result);
            $this->db->free($result);
            if ($row) { return $this->readResult($row); }
            if ($this->db->begin() <= 0) { throw new RuntimeException('Cannot start analysis claim', 503); }
            $transaction = true;
            $this->query('INSERT INTO '.$this->table.' (entity,date_start,date_end,data_hash,status,reference_json,weather_json,fk_user_creat,datec) VALUES ('
                .$this->entity.", '".$this->db->escape($start)."', '".$this->db->escape($end)."', '".$hash."', 0, '"
                .$this->db->escape(json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))."', '"
                .$this->db->escape(json_encode($facts['weather'], JSON_THROW_ON_ERROR))."', ".(int) $userId.", '".$this->db->idate(dol_now())."')");
            if ($this->db->commit() <= 0) { throw new RuntimeException('Cannot persist analysis claim', 503); }
            $transaction = false;
            try {
                $analysis = $generate();
                $encoded = json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                if ($this->db->begin() > 0) {
                    $transaction = true;
                    $this->query('UPDATE '.$this->table.' SET status = 2 WHERE '.$where.' AND status = 0');
                    if ($this->db->commit() <= 0) { throw new RuntimeException('Cannot persist failed analysis', 503); }
                    $transaction = false;
                }
                throw new RuntimeException('Analysis provider attempt failed', 502);
            }
            if ($this->db->begin() <= 0) { throw new RuntimeException('Cannot start analysis save', 503); }
            $transaction = true;
            $this->query("UPDATE ".$this->table." SET status = 1, result_json = '".$this->db->escape($encoded)."' WHERE ".$where.' AND status = 0');
            if ($this->db->commit() <= 0) { throw new RuntimeException('Cannot save analysis', 503); }
            $transaction = false;
            return $analysis;
        } finally {
            if ($transaction) { $this->db->rollback(); }
            $this->db->query("SELECT RELEASE_LOCK('".$lockName."')");
        }
    }
}
