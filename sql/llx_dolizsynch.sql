-- Consolidated base schema for DoliZSynch
-- This file replaces all per-table creation/key SQL scripts.
-- It is intended to bootstrap a fresh installation.

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- GPL-3.0 License:
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--


CREATE TABLE IF NOT EXISTS llx_dolizsynch_zsinvoicesynch(
	-- BEGIN MODULEBUILDER FIELDS
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL, 
	entity INTEGER DEFAULT 1 NOT NULL, 
	contribuinte_zs varchar(21), 
	countrycode_zs varchar(6), 
	doc varchar(6), 
	doc_num INTEGER, 
	doc_serie varchar(12), 
	data_zs date, 
	datahora_zs datetime, 
	liquido_zs double, 
	total_zs double,
    tipo_zs INTEGER DEFAULT NULL,
	pagamento_zs INTEGER,
	pago_zs INTEGER,
	anulado_zs INTEGER,
	stock_reversed INTEGER DEFAULT 0,
	loja_zs INTEGER, 
	zona_zs INTEGER, 
	mesa_zs INTEGER, 
	emp_zs INTEGER, 
	pessoas_zs INTEGER DEFAULT 1, 
	fk_user_creat INTEGER NOT NULL, 
	fk_user_modif INTEGER, 
	fk_soc INTEGER, 
	date_creation datetime NOT NULL, 
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, 
	last_main_doc varchar(255), 
	fk_facture INTEGER,
	invoice_ref varchar(33),
    credit_ref INTEGER DEFAULT '0',
	synch INTEGER NOT NULL,
	date_modification datetime,
	error_message text,
	sync_context text,
	INDEX idx_dolizsynch_zsinvoicesynch_entity (entity),
	INDEX idx_dolizsynch_zsinvoicesynch_fk_soc (fk_soc),
	UNIQUE INDEX idx_dolizsynch_zsinvoicesynch_invoice_ref (invoice_ref, entity)
	-- END MODULEBUILDER FIELDS
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_dolizsynch_zsfamily (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL, 
    loja_zs INTEGER DEFAULT 1 NOT NULL, 
    family_id INTEGER DEFAULT 0 NOT NULL,
    family_name VARCHAR(255) DEFAULT '-' NOT NULL,
    subfamily_id INTEGER DEFAULT 0 NOT NULL,
    subfamily_name VARCHAR(255) DEFAULT '-' NOT NULL,
    fk_categorie INTEGER DEFAULT 0 NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX unique_lojazs_family_subfamily (entity, loja_zs, family_id, subfamily_id),
    synch INTEGER DEFAULT 0 NOT NULL
);

CREATE TABLE IF NOT EXISTS llx_dolizsynch_zscategory (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL, 
    loja_zs INTEGER DEFAULT 1 NOT NULL, 
    category_id INTEGER DEFAULT 0 NOT NULL,
    category_name VARCHAR(255) DEFAULT '-' NOT NULL,
    subcategory_id INTEGER DEFAULT 0 NOT NULL,
    subcategory_name VARCHAR(255) DEFAULT '-' NOT NULL,
    fk_categorie INTEGER DEFAULT 0 NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX unique_lojazs_category_subcategory (entity, loja_zs, category_id, subcategory_id),
    synch INTEGER DEFAULT 0 NOT NULL
);

CREATE TABLE IF NOT EXISTS llx_dolizsynch_zspaymenttype (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL, 
	loja_zs INTEGER DEFAULT 1 NOT NULL, 
    paymenttype_id INTEGER NOT NULL,
    paymenttype_name VARCHAR(255) NOT NULL,
	cash INTEGER DEFAULT 0 NOT NULL,
	fk_paiement INTEGER DEFAULT NULL,
    fk_account INTEGER DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY unique_lojazs_paymenttypeid (entity, loja_zs, paymenttype_id),
    synch INTEGER DEFAULT 0 NOT NULL
);

CREATE TABLE IF NOT EXISTS llx_dolizsynch_zsunit (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL, 
	loja_zs INTEGER DEFAULT 1 NOT NULL, 
    unit_id INTEGER NOT NULL,
    unit_name VARCHAR(255) NOT NULL,
	fk_unit INTEGER DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY unique_lojazs_unitid (entity, loja_zs, unit_id),
    synch INTEGER DEFAULT 0 NOT NULL
);

CREATE TABLE IF NOT EXISTS llx_dolizsynch_zsprodcentre (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL, 
	loja_zs INTEGER DEFAULT 1 NOT NULL, 
    prodcentre_id INTEGER NOT NULL,
    prodcentre_name VARCHAR(255) NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY unique_lojazs_prodcentreid (entity, loja_zs, prodcentre_id),
    synch INTEGER DEFAULT 0 NOT NULL
);

CREATE TABLE IF NOT EXISTS llx_dolizsynch_zsproduct (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    date_creation datetime,
    tms timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    fk_product INTEGER UNIQUE,
    loja_zs INTEGER DEFAULT 1 NOT NULL,
    codigo INTEGER UNIQUE,
    codigopp VARCHAR(50) DEFAULT NULL,
    codbarras VARCHAR(20),
    descricao VARCHAR(255),
    descricaocurta VARCHAR(255),
    familia INTEGER,
    subfam INTEGER,
    categoria INTEGER,
    subcategoria INTEGER,
    unidade INTEGER,
    uncompra INTEGER,
    uninventario INTEGER,
    precovenda DECIMAL(10, 2),
    pvp2 DECIMAL(10, 2),
    pvp3 DECIMAL(10, 2),
    pvp4 DECIMAL(10, 2),
    pvp5 DECIMAL(10, 2),
    pvp6 DECIMAL(10, 2),
    pvp7 DECIMAL(10, 2),
    pvp8 DECIMAL(10, 2),
    pvp9 DECIMAL(10, 2),
    pvp10 DECIMAL(10, 2),
    pvp1siva DECIMAL(10, 2),
    pvp2siva DECIMAL(10, 2),
    pvp3siva DECIMAL(10, 2),
    pvp4siva DECIMAL(10, 2),
    pvp5siva DECIMAL(10, 2),
    pvp6siva DECIMAL(10, 2),
    pvp7siva DECIMAL(10, 2),
    pvp8siva DECIMAL(10, 2),
    pvp9siva DECIMAL(10, 2),
    pvp10siva DECIMAL(10, 2),
    iva DECIMAL(10, 2),
    iva2 DECIMAL(10, 2),
    isencao VARCHAR(30),
    retencao INTEGER,
    percentagemretencao DECIMAL(19, 4),
    precocompra DECIMAL(10, 2),
    ultprecovenda DECIMAL(10, 2),
    composto INTEGER,
    retalho_by_store VARCHAR(120),
    compra INTEGER,
    vendersemstock INTEGER,
    restricted INTEGER,
    fundo VARCHAR(12),
    letra VARCHAR(12),
    armazem INTEGER,
    autoquebra INTEGER,
    balanca INTEGER,
    consumominimo INTEGER,
    cozinha INTEGER,
    dosedesc VARCHAR(50),
    excluirdescontos INTEGER,
    ementadigital INTEGER NULL,
    grupo INTEGER,
    ivacompra DECIMAL(10, 2),
    ivarevenda DECIMAL(10, 2),
    listseparado INTEGER,
    margembruta DECIMAL(10, 2),
    maxopcoes INTEGER,
    meiadose INTEGER,
    meiadosedesc VARCHAR(50),
    ordem INTEGER,
    ordemlocal INTEGER,
    ordemtop INTEGER,
    precomeia DECIMAL(10, 2),
    precominimo DECIMAL(10, 2),
    precorevenda DECIMAL(10, 2),
    prepagamento INTEGER,
    produtoscentrosprod VARCHAR(250),
    array_preco_meia VARCHAR(500),
    qtdmeia DECIMAL(10, 2),
    referencia VARCHAR(50),
    revenda INTEGER,
    stocks INTEGER,
    tara DECIMAL(10, 2),
    tempoprep TIME,
    tiposaft VARCHAR(1),
    topo INTEGER,
    transferivel INTEGER,
    fuel INTEGER,
    product_company VARCHAR(255) DEFAULT NULL,
    datacriacao DATETIME,
    obs VARCHAR(500),
    caracteristicas MEDIUMTEXT,
	complementares MEDIUMTEXT,
	niveismenu MEDIUMTEXT,
	produto_opcoes MEDIUMTEXT,
	produtos_propriedades MEDIUMTEXT,
    produtos_codigosbarras VARCHAR(4096),
    produtos_taxas MEDIUMTEXT,
    produtos_taxas_br MEDIUMTEXT,
    fk_user_creat INTEGER,
    fk_user_modif INTEGER
);


-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- GPL-3.0 License:
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--

-- Error logging table for ZoneSoft integration error tracking
CREATE TABLE IF NOT EXISTS llx_dolizsynch_error_log (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity INTEGER DEFAULT 1 NOT NULL,
    message TEXT NOT NULL,
    severity VARCHAR(20) NOT NULL,
    category VARCHAR(50) NOT NULL,
    context TEXT,
    date_creation DATETIME NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_severity (severity),
    INDEX idx_category (category),
    INDEX idx_date_creation (date_creation)
) ENGINE=innodb;

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- GPL-3.0 License:
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--

CREATE TABLE IF NOT EXISTS llx_dolizsynch_zsinvoicesynch_extrafields
(
  rowid                     integer AUTO_INCREMENT PRIMARY KEY,
  tms                       timestamp,
  fk_object                 integer NOT NULL,
  import_key                varchar(14),                         		-- import key
  INDEX idx_zsinvoicesynch_fk_object (fk_object)
) ENGINE=innodb;


-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- GPL-3.0 License:
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--


CREATE TABLE IF NOT EXISTS llx_dolizsynch_zspunchclock(
	-- BEGIN MODULEBUILDER FIELDS
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	ref varchar(128),
	loja_zs integer,
	empid_zs integer,
	fk_user integer,
	dataval date,
	sentido_in integer,
	datahora_in datetime,
	id_zs_in integer,
	sentido_out integer,
	datahora_out datetime,
	id_zs_out integer,
	duration integer,
	fk_pairid integer,
	fk_task integer,
	import_key varchar(14),
	fk_user_creat integer NOT NULL,
	fk_user_modif integer,
	note_public text,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	status integer NOT NULL,
	synch integer,
	real_entry integer DEFAULT 1 NOT NULL,
	fk_timespent integer,
	position integer DEFAULT 0,
	INDEX idx_dolizsynch_zspunchclock_entity (entity),
	INDEX idx_dolizsynch_zspunchclock_ref (ref),
	INDEX idx_dolizsynch_zspunchclock_fk_task (fk_task),
	INDEX idx_dolizsynch_zspunchclock_status (status)
	-- END MODULEBUILDER FIELDS
) ENGINE=innodb;

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- GPL-3.0 License:
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--

CREATE TABLE IF NOT EXISTS llx_dolizsynch_zspunchclock_synch(
    -- BEGIN MODULEBUILDER FIELDS
    rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity integer DEFAULT 1 NOT NULL,
    date_creation datetime,
    tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    loja_zs integer NOT NULL, 
    sentido integer NOT NULL, 
    empid_zs integer NOT NULL, 
    datahora datetime NOT NULL, 
    dataval date, 
    id_zs integer,
    synch integer NOT NULL DEFAULT 0,
    zsbms_synch integer NOT NULL DEFAULT 0,
    pairid integer,
    fk_user_creat integer,
    fk_user_modif integer,
    INDEX idx_dolizsynch_zspunchclock_synch_entity (entity)
    -- END MODULEBUILDER FIELDS
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_dolizsynch_kreaclock_event(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer NOT NULL,
	client_event_id char(36) NOT NULL,
	station_code varchar(64) NOT NULL,
	fk_user integer NOT NULL,
	empid_zs integer NOT NULL,
	loja_zs integer NOT NULL,
	direction tinyint NOT NULL,
	captured_at datetime NOT NULL,
	client_timezone_offset_minutes smallint NOT NULL DEFAULT 0,
	received_at datetime NOT NULL,
	recognition_method varchar(32) NOT NULL,
	recognition_confidence decimal(7,6) DEFAULT NULL,
	fk_raw_punch integer DEFAULT NULL,
	fk_api_user integer NOT NULL,
	status varchar(16) NOT NULL DEFAULT 'pending',
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE INDEX uk_dolizsynch_kreaclock_event_entity_uuid (entity, client_event_id),
	INDEX idx_dolizsynch_kreaclock_event_user_time (entity, fk_user, captured_at),
	INDEX idx_dolizsynch_kreaclock_event_station_time (entity, station_code, captured_at),
	INDEX idx_dolizsynch_kreaclock_event_raw (fk_raw_punch)
) ENGINE=innodb;

-- Table for employee day off tracking
CREATE TABLE IF NOT EXISTS llx_dolizsynch_dayoff (
    rowid          INT(11) NOT NULL AUTO_INCREMENT,
    entity         INT(11) NOT NULL DEFAULT 1,
    fk_user        INT(11) NOT NULL,
    date_dayoff    DATE NOT NULL,
    is_recurring   TINYINT(1) NOT NULL DEFAULT 0,
    description    TEXT,
    date_creation  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tms            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat  INT(11) DEFAULT NULL,
    fk_user_modif  INT(11) DEFAULT NULL,

    PRIMARY KEY (rowid),
    INDEX idx_dolizsynch_dayoff_user (fk_user),
    INDEX idx_dolizsynch_dayoff_date (date_dayoff),
    INDEX idx_dolizsynch_dayoff_entity (entity)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Table for employee leave tracking (module owned)
CREATE TABLE IF NOT EXISTS llx_dolizsynch_leave (
    rowid          INT(11) NOT NULL AUTO_INCREMENT,
    entity         INT(11) NOT NULL DEFAULT 1,
    fk_user        INT(11) NOT NULL,
    fk_type        INT(11) NOT NULL,
    date_start     DATETIME NOT NULL,
    date_end       DATETIME NOT NULL,
    halfday        TINYINT(2) NOT NULL DEFAULT 0, -- 0 full, 1 half-day, 2 period hours, 3 minutes
    minutes_total  INT(11) NOT NULL DEFAULT 0,
    description    TEXT,
    day_change_hour TINYINT(2) DEFAULT NULL,
    created_by     INT(11) DEFAULT NULL,
    fk_user_modif  INT(11) DEFAULT NULL,
    date_creation  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tms            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (rowid),
    INDEX idx_dolizsynch_leave_user (fk_user),
    INDEX idx_dolizsynch_leave_type (fk_type),
    INDEX idx_dolizsynch_leave_start (date_start),
    INDEX idx_dolizsynch_leave_end (date_end),
    INDEX idx_dolizsynch_leave_entity (entity)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS llx_dolizsynch_leave_quota (
	rowid INT AUTO_INCREMENT PRIMARY KEY,
	entity INT NOT NULL DEFAULT 1,
	fk_user INT NOT NULL,
	fk_type INT NOT NULL,
	year INT NOT NULL,
	days_total DECIMAL(10,2) NOT NULL DEFAULT 0,
	note TEXT NULL,
	created_by INT NULL,
	tms TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uq_quota_user_type_entity_year (fk_user, fk_type, entity, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.

-- Table for shift planning
CREATE TABLE IF NOT EXISTS llx_dolizsynch_shift (
    rowid INT AUTO_INCREMENT PRIMARY KEY,
    fk_user INT NOT NULL,
    fk_task INT NULL,
    entity INT NOT NULL DEFAULT 1,
    label VARCHAR(128) NULL,
    date_start DATETIME NOT NULL,
    date_end DATETIME NOT NULL,
    color VARCHAR(16) NULL,
    note TEXT NULL,
    shift_status VARCHAR(32) NOT NULL DEFAULT 'scheduled',
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    INDEX idx_fk_user (fk_user),
    INDEX idx_fk_task (fk_task),
    INDEX idx_entity (entity),
    INDEX idx_shift_status (shift_status),
    INDEX idx_date_start (date_start),
    INDEX idx_date_end (date_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS llx_dolizsynch_shift_task (
    rowid INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(128) NOT NULL,
    color VARCHAR(16) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.

-- Weekly notes table
CREATE TABLE IF NOT EXISTS llx_dolizsynch_week_notes (
    rowid INT AUTO_INCREMENT PRIMARY KEY,
    entity INT NOT NULL,
    week_start DATE NOT NULL,
    note TEXT NULL,
    forced_user_ids TEXT NULL,
    hidden_user_ids TEXT NULL,
    updated_by INT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_week_notes_entity_week (entity, week_start),
    KEY idx_entity (entity),
    KEY idx_week_start (week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS llx_dolizsynch_month_notes (
    rowid INT AUTO_INCREMENT PRIMARY KEY,
    entity INT NOT NULL DEFAULT 1,
    month_key CHAR(7) NOT NULL,
    fk_user INT NOT NULL,
    note TEXT NULL,
    forced_user_ids TEXT NULL,
    hidden_user_ids TEXT NULL,
    manual_json TEXT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_month_note (entity, month_key, fk_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Annual notes table for vacations (show/hide employee overrides)
CREATE TABLE IF NOT EXISTS llx_dolizsynch_year_notes (
    rowid INT AUTO_INCREMENT PRIMARY KEY,
    entity INT NOT NULL,
    year INT NOT NULL,
    note TEXT NULL,
    forced_user_ids TEXT NULL,
    hidden_user_ids TEXT NULL,
    updated_by INT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_year_notes_entity_year (entity, year),
    KEY idx_entity (entity),
    KEY idx_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- GPL-3.0 License:
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--

-- Schedule change audit log
CREATE TABLE IF NOT EXISTS llx_dolizsynch_schedule_log (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity INTEGER NOT NULL DEFAULT 1,
    action VARCHAR(64) NOT NULL,
    object_type VARCHAR(32) NOT NULL,
    object_id INTEGER NULL,
    fk_user_actor INTEGER NOT NULL,
    fk_user_target INTEGER NULL,
    context TEXT,
    date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_action (action),
    INDEX idx_object (object_type, object_id),
    INDEX idx_user_actor (fk_user_actor),
    INDEX idx_user_target (fk_user_target),
    INDEX idx_date_creation (date_creation),
    INDEX idx_entity (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- Store to entity mapping table for inter-entity invoice conversion.

CREATE TABLE IF NOT EXISTS llx_dolizsynch_storemap (
    rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    store           varchar(10) NOT NULL,
    zsbms_store     varchar(10) NOT NULL,
    store_dolibarr  integer NOT NULL,
    store_nif       varchar(32),
    entity          integer NOT NULL DEFAULT 0,
    date_creation   datetime NULL,
    tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_dolizsynch_storemap_zsbms (zsbms_store, entity),
    INDEX idx_dolizsynch_storemap_entity (entity)
) ENGINE=innodb;

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- ===================================================================
-- Product Equivalence Mapping Table
-- Maps products from current entity (seller) to products in other entities
-- Simple 1:1 mapping - Replaces the Google Sheets equivalence table
-- Requires Dolibarr multicompany module
-- ===================================================================

CREATE TABLE IF NOT EXISTS llx_dolizsynch_product_equivalence (
    rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity          integer DEFAULT 1 NOT NULL,

    -- Source product (from current entity - seller)
    fk_product_source    integer NOT NULL,
    ref_source           varchar(128),

    -- Destination product (in another entity)
    fk_product_dest      integer NOT NULL,
    ref_dest             varchar(128),

    -- Active status
    active          integer DEFAULT 1 NOT NULL,

    -- Tracking
    date_creation   datetime NOT NULL,
    tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat   integer,
    fk_user_modif   integer,

    -- Notes
    note_private    text,
    note_public     text,
    INDEX idx_dolizsynch_product_equiv_source (fk_product_source),
    INDEX idx_dolizsynch_product_equiv_dest (fk_product_dest),
    INDEX idx_dolizsynch_product_equiv_entity (entity),
    INDEX idx_dolizsynch_product_equiv_active (active)

) ENGINE=innodb;


-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- ===================================================================
-- Inter-Entity Invoice Tracking Table
-- Tracks invoices created from the configured seller entity to other entities
-- Links source customer invoices to destination supplier invoices
-- ===================================================================

CREATE TABLE IF NOT EXISTS llx_dolizsynch_interentity_invoice (
    rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity          integer DEFAULT 1 NOT NULL,

    -- Source invoice (customer invoice in the source entity)
    source_entity          integer NOT NULL,           -- Configured seller entity
    source_invoice_ref     varchar(255) NOT NULL,      -- ZoneSoft invoice reference
    source_invoice_number  varchar(128),               -- Doc + Serie + Numero
    source_invoice_date    date,                       -- Invoice date
    fk_facture_source      integer,                    -- Link to Dolibarr customer invoice if it exists

    -- Destination invoice (supplier invoice in target entity)
    target_entity          integer NOT NULL,           -- Configured target entity
    fk_facture_fourn_dest  integer,                    -- Link to created supplier invoice
    dest_invoice_ref       varchar(255),               -- Supplier invoice ref in target entity

    -- ZoneSoft data
    zs_doc_type           varchar(50),                 -- "FT", "NC", etc.
    zs_serie              varchar(50),
    zs_numero             varchar(50),
    zs_contribuinte       varchar(50),                 -- Customer NIF from ZoneSoft
    zs_total              double(24,8),

    -- Synchronization status
    synch                 integer DEFAULT 0 NOT NULL,  -- 0=pending, 1=synced, -1=error
    synch_date            datetime,
    error_message         text,
    retry_count           integer DEFAULT 0,

    -- Processing flags
    processed             integer DEFAULT 0 NOT NULL,  -- 0=not processed, 1=processed
    process_date          datetime,

    -- Tracking
    date_creation         datetime NOT NULL,
    tms                   timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat         integer,
    fk_user_modif         integer,

    -- Notes
    note_private          text,
    note_public           text,
    UNIQUE INDEX uk_dolizsynch_interentity_inv_source (source_invoice_ref, target_entity),
    INDEX idx_dolizsynch_interentity_inv_target (target_entity),
    INDEX idx_dolizsynch_interentity_inv_synch (synch),
    INDEX idx_dolizsynch_interentity_inv_processed (processed),
    INDEX idx_dolizsynch_interentity_inv_date (source_invoice_date),
    INDEX idx_dolizsynch_interentity_inv_fk_fourn (fk_facture_fourn_dest)

) ENGINE=innodb;


-- Property-driven exact-unit stock conversion
-- Entity-specific BOM rules are owned by the destination entity. Rules for
-- BOMs shared with entity 0 are stored once with entity 0 and use each target
-- entity's configured source package-count property at conversion time.
CREATE TABLE IF NOT EXISTS llx_dolizsynch_property_stock_rule (
    rowid                   integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity                  integer DEFAULT 1 NOT NULL,
    fk_product              integer NOT NULL,
    property_id             integer NOT NULL,
    property_name           varchar(255) NOT NULL,
    units_per_package       decimal(24,8) NOT NULL,
    fk_bom                  integer NOT NULL,
    fk_bom_line             integer NOT NULL,
    fk_product_output       integer NOT NULL,
    active                  integer DEFAULT 1 NOT NULL,
    date_creation           datetime NOT NULL,
    tms                     timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat           integer,
    fk_user_modif           integer,
    UNIQUE INDEX uk_dzs_property_stock_rule_product (entity, fk_product),
    INDEX idx_dzs_property_stock_rule_property (entity, property_id, active),
    INDEX idx_dzs_property_stock_rule_bom (entity, fk_bom, active),
    INDEX idx_dzs_property_stock_rule_bom_line (fk_bom_line),
    INDEX idx_dzs_property_stock_rule_output (fk_product_output)
) ENGINE=innodb;

-- Immutable source evidence and idempotent correction state per supplier-invoice line.
CREATE TABLE IF NOT EXISTS llx_dolizsynch_property_stock_plan (
    rowid                   integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity                  integer DEFAULT 1 NOT NULL,
    fk_facture_fourn        integer NOT NULL,
    fk_facture_fourn_det    integer NOT NULL,
    property_movement_id    integer NOT NULL,
    property_id             integer NOT NULL,
    property_name           varchar(255) NOT NULL,
    package_count           decimal(24,8) NOT NULL,
    units_per_package       decimal(24,8) NOT NULL,
    target_units            decimal(24,8) NOT NULL,
    fk_product_input        integer NOT NULL,
    fk_bom                  integer NOT NULL,
    fk_bom_line             integer NOT NULL,
    fk_product_output       integer NOT NULL,
    source_purchase_qty     decimal(24,8) NOT NULL,
    source_unit_cost        decimal(24,8) NOT NULL,
    source_stock_value      decimal(24,8) NOT NULL,
    source_line_total_ht    decimal(24,8) NOT NULL,
    exact_unit_cost         decimal(24,8) NOT NULL,
    status                  smallint DEFAULT 0 NOT NULL,
    fk_stock_movement_out   integer,
    fk_stock_movement_in    integer,
    error_message           text,
    date_processed          datetime,
    date_creation           datetime NOT NULL,
    tms                     timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat           integer,
    fk_user_modif           integer,
    UNIQUE INDEX uk_dzs_property_stock_plan_line (entity, fk_facture_fourn_det),
    INDEX idx_dzs_property_stock_plan_invoice (entity, fk_facture_fourn, status),
    INDEX idx_dzs_property_stock_plan_products (entity, fk_product_input, fk_product_output),
    INDEX idx_dzs_property_stock_plan_bom (entity, fk_bom, fk_bom_line, status)
) ENGINE=innodb;


-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- GPL-3.0 License:
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--

CREATE TABLE IF NOT EXISTS llx_dolizsynch_price_schedule (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity INTEGER NOT NULL DEFAULT 1,
    fk_product INTEGER NULL,
    product_ref VARCHAR(128) NOT NULL,
    price_level INTEGER NOT NULL DEFAULT 1,
    price_base_type VARCHAR(3) NOT NULL DEFAULT 'TTC',
    price_value DOUBLE(24,8) NOT NULL DEFAULT 0,
    scheduled_at DATETIME NOT NULL,
    status SMALLINT NOT NULL DEFAULT 0, -- 0=pending, 1=done, -1=error, 9=canceled
    source VARCHAR(32) NOT NULL DEFAULT 'manual',
    error_message TEXT,
    date_processed DATETIME NULL,
    fk_user_creat INTEGER NULL,
    fk_user_modif INTEGER NULL,
    date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dzs_price_schedule_status_date (entity, status, scheduled_at),
    INDEX idx_dzs_price_schedule_fk_product (fk_product),
    INDEX idx_dzs_price_schedule_ref (product_ref),
    INDEX idx_dzs_price_schedule_creation (date_creation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- GPL-3.0 License:
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--

CREATE TABLE IF NOT EXISTS llx_dolizsynch_price_schedule_draft (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity INTEGER NOT NULL DEFAULT 1,
    fk_user INTEGER NOT NULL,
    line_order INTEGER NOT NULL DEFAULT 1,
    fk_product INTEGER NULL,
    price_base_type VARCHAR(3) NOT NULL DEFAULT 'TTC',
    scheduled_at DATETIME NOT NULL,
    scheduled_end_at DATETIME NULL,
    recurrence_type VARCHAR(16) NOT NULL DEFAULT 'once',
    prices_json TEXT,
    date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_dzs_price_schedule_draft_user_line (entity, fk_user, line_order),
    INDEX idx_dzs_price_schedule_draft_user (entity, fk_user),
    INDEX idx_dzs_price_schedule_draft_product (fk_product)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- GPL-3.0 License:
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--

CREATE TABLE IF NOT EXISTS llx_dolizsynch_price_schedule_rule (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity INTEGER NOT NULL DEFAULT 1,
    fk_product INTEGER NULL,
    product_ref VARCHAR(128) NOT NULL,
    price_level INTEGER NOT NULL DEFAULT 1,
    price_base_type VARCHAR(3) NOT NULL DEFAULT 'TTC',
    promo_price_value DOUBLE(24,8) NOT NULL DEFAULT 0,
    rule_type VARCHAR(16) NOT NULL DEFAULT 'once_window',
    start_at DATETIME NULL,
    end_at DATETIME NULL,
    weekly_day SMALLINT NULL,
    weekly_start_time TIME NULL,
    weekly_end_time TIME NULL,
    is_active SMALLINT NOT NULL DEFAULT 0,
    last_base_price DOUBLE(24,8) NULL,
    last_start_at DATETIME NULL,
    last_end_at DATETIME NULL,
    status SMALLINT NOT NULL DEFAULT 0, -- 0=enabled, 1=done, -1=error, 9=canceled
    error_message TEXT,
    date_processed DATETIME NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'manual_grid',
    fk_user_creat INTEGER NULL,
    fk_user_modif INTEGER NULL,
    date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dzs_price_schedule_rule_state (entity, status, rule_type),
    INDEX idx_dzs_price_schedule_rule_product (fk_product),
    INDEX idx_dzs_price_schedule_rule_start (start_at),
    INDEX idx_dzs_price_schedule_rule_end (end_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ZoneSoft Taxes dictionary table
CREATE TABLE IF NOT EXISTS llx_dolizsynch_zstax (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    loja_zs INTEGER DEFAULT 1 NOT NULL,
    tax_id INTEGER NOT NULL,
    tax_name VARCHAR(255) NOT NULL,
    tax_value DECIMAL(10, 2) DEFAULT 0.00 NOT NULL,
    active INTEGER DEFAULT 1 NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_lojazs_taxid (entity, loja_zs, tax_id),
    synch INTEGER DEFAULT 0 NOT NULL
);

-- ZoneSoft Groups dictionary table
CREATE TABLE IF NOT EXISTS llx_dolizsynch_zsgroup (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER DEFAULT 1 NOT NULL,
    loja_zs INTEGER DEFAULT 1 NOT NULL,
    group_id INTEGER NOT NULL,
    group_name VARCHAR(255) NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_lojazs_groupid (entity, loja_zs, group_id),
    synch INTEGER DEFAULT 0 NOT NULL
);

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- GPL-3.0 License:
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--


-- BEGIN MODULEBUILDER INDEXES

-- END MODULEBUILDER INDEXES

--ALTER TABLE llx_dolizsynch_zsinvoicesynch ADD UNIQUE INDEX uk_dolizsynch_zsinvoicesynch_fieldxy(fieldx, fieldy);

--ALTER TABLE llx_dolizsynch_zsinvoicesynch ADD CONSTRAINT llx_dolizsynch_zsinvoicesynch_fk_field FOREIGN KEY (fk_field) REFERENCES llx_dolizsynch_myotherobject(rowid);

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- GPL-3.0 License:
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--


-- BEGIN MODULEBUILDER INDEXES
-- END MODULEBUILDER INDEXES

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- GPL-3.0 License:
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--


-- BEGIN MODULEBUILDER INDEXES
-- END MODULEBUILDER INDEXES

--ALTER TABLE llx_dolizsynch_zspunchclock ADD UNIQUE INDEX uk_dolizsynch_zspunchclock_fieldxy(fieldx, fieldy);

--ALTER TABLE llx_dolizsynch_zspunchclock ADD CONSTRAINT llx_dolizsynch_zspunchclock_fk_field FOREIGN KEY (fk_field) REFERENCES llx_dolizsynch_myotherobject(rowid);


-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is licensed under GPL-3.0-or-later.
--
-- GPL-3.0 License:
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--


-- BEGIN MODULEBUILDER INDEXES
-- END MODULEBUILDER INDEXES

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
-- Foreign key constraints for product equivalence table

-- Link to source product

-- Link to destination product

-- Link to user who created

-- Link to user who modified

-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
-- Foreign key constraints for inter-entity invoice table

-- Invoice identifiers in llx_dolizsynch_interentity_invoice are intentionally soft references.
-- Cascading foreign keys are not used because synchronization audit history must survive invoice deletion.

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
