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

CREATE TABLE IF NOT EXISTS llx_dolizsynch_property_stock_rule (
    rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity integer DEFAULT 1 NOT NULL,
    fk_product integer NOT NULL,
    property_id integer NOT NULL,
    property_name varchar(255) NOT NULL,
    units_per_package decimal(24,8) NOT NULL,
    fk_product_output integer NOT NULL,
    active integer DEFAULT 1 NOT NULL,
    date_creation datetime NOT NULL,
    tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat integer,
    fk_user_modif integer,
    UNIQUE INDEX uk_dzs_property_stock_rule_product (entity, fk_product),
    INDEX idx_dzs_property_stock_rule_property (entity, property_id, active),
    INDEX idx_dzs_property_stock_rule_output (fk_product_output)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_dolizsynch_property_stock_plan (
    rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity integer DEFAULT 1 NOT NULL,
    fk_facture_fourn integer NOT NULL,
    fk_facture_fourn_det integer NOT NULL,
    property_movement_id integer NOT NULL,
    property_id integer NOT NULL,
    property_name varchar(255) NOT NULL,
    package_count decimal(24,8) NOT NULL,
    units_per_package decimal(24,8) NOT NULL,
    target_units decimal(24,8) NOT NULL,
    fk_product_input integer NOT NULL,
    fk_product_output integer NOT NULL,
    status smallint DEFAULT 0 NOT NULL,
    fk_stock_movement_out integer,
    fk_stock_movement_in integer,
    error_message text,
    date_processed datetime,
    date_creation datetime NOT NULL,
    tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat integer,
    fk_user_modif integer,
    UNIQUE INDEX uk_dzs_property_stock_plan_line (entity, fk_facture_fourn_det),
    INDEX idx_dzs_property_stock_plan_invoice (entity, fk_facture_fourn, status),
    INDEX idx_dzs_property_stock_plan_products (entity, fk_product_input, fk_product_output)
) ENGINE=innodb;
