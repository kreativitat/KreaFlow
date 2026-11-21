-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License,
-- or (at your option) any later version.
-- Product-owned configuration inherits native product sharing and entity visibility.
CREATE TABLE IF NOT EXISTS llx_dolizsynch_store_prices (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER NOT NULL,
    fk_product INTEGER NOT NULL,
    enabled SMALLINT NOT NULL DEFAULT 0,
    prices TEXT NOT NULL,
    revision INTEGER NOT NULL DEFAULT 1,
    fk_user_modif INTEGER NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_dzs_store_prices_product (entity, fk_product)
) ENGINE=innodb;

ALTER TABLE llx_dolizsynch_zsproduct ADD COLUMN IF NOT EXISTS ementadigital INTEGER NULL;
