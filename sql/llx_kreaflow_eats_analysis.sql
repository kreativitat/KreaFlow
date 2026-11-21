-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
-- SPDX-License-Identifier: GPL-3.0-or-later
-- Idempotent installation/upgrade: immutable reference versions, active-entity ownership.
CREATE TABLE IF NOT EXISTS llx_kreaflow_eats_analysis (
    rowid integer AUTO_INCREMENT PRIMARY KEY,
    entity integer NOT NULL,
    date_start date NOT NULL,
    date_end date NOT NULL,
    data_hash varchar(64) NOT NULL,
    status smallint NOT NULL DEFAULT 0,
    reference_json longtext NOT NULL,
    weather_json text NOT NULL,
    result_json longtext,
    fk_user_creat integer NOT NULL,
    datec datetime NOT NULL,
    tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_kreaflow_eats_analysis (entity, date_start, date_end, data_hash)
) ENGINE=innodb;
