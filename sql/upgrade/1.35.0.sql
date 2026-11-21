-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License,
-- or (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.

CREATE TABLE IF NOT EXISTS llx_dolizsynch_product_price_sync_run (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER NOT NULL,
    active_key INTEGER DEFAULT NULL,
    store_id INTEGER NOT NULL,
    status SMALLINT NOT NULL DEFAULT 0,
    scheduled_for DATETIME NOT NULL,
    date_creation DATETIME NOT NULL,
    started_at DATETIME DEFAULT NULL,
    heartbeat_at DATETIME DEFAULT NULL,
    worker_token VARCHAR(64) DEFAULT NULL,
    worker_lease_until DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    total_count INTEGER NOT NULL DEFAULT 0,
    synchronized_count INTEGER NOT NULL DEFAULT 0,
    skipped_count INTEGER NOT NULL DEFAULT 0,
    error_count INTEGER NOT NULL DEFAULT 0,
    tms TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_dolizsynch_price_sync_active (active_key),
    INDEX idx_dolizsynch_price_sync_run_entity_status (entity, status),
    INDEX idx_dolizsynch_price_sync_run_completed (entity, completed_at)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_dolizsynch_product_price_sync_item (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER NOT NULL,
    fk_run INTEGER NOT NULL,
    fk_product INTEGER NOT NULL,
    status SMALLINT NOT NULL DEFAULT 0,
    attempts INTEGER NOT NULL DEFAULT 0,
    date_creation DATETIME NOT NULL,
    last_attempt_at DATETIME DEFAULT NULL,
    next_attempt_at DATETIME DEFAULT NULL,
    worker_token VARCHAR(64) DEFAULT NULL,
    lease_until DATETIME DEFAULT NULL,
    synchronized_at DATETIME DEFAULT NULL,
    error_message TEXT,
    tms TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_dolizsynch_price_sync_item (fk_run, fk_product),
    INDEX idx_dolizsynch_price_sync_item_claim (entity, fk_run, status, next_attempt_at),
    INDEX idx_dolizsynch_price_sync_item_lease (entity, fk_run, lease_until)
) ENGINE=innodb;
