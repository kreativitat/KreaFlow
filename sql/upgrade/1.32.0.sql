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

ALTER TABLE llx_dolizsynch_property_stock_rule
    ADD COLUMN IF NOT EXISTS fk_bom integer NULL AFTER units_per_package,
    ADD COLUMN IF NOT EXISTS fk_bom_line integer NULL AFTER fk_bom;

ALTER TABLE llx_dolizsynch_property_stock_plan
    ADD COLUMN IF NOT EXISTS fk_bom integer NULL AFTER fk_product_input,
    ADD COLUMN IF NOT EXISTS fk_bom_line integer NULL AFTER fk_bom;

CREATE INDEX IF NOT EXISTS idx_dzs_property_stock_rule_bom
    ON llx_dolizsynch_property_stock_rule (entity, fk_bom, active);

CREATE INDEX IF NOT EXISTS idx_dzs_property_stock_rule_bom_line
    ON llx_dolizsynch_property_stock_rule (fk_bom_line);

CREATE INDEX IF NOT EXISTS idx_dzs_property_stock_plan_bom
    ON llx_dolizsynch_property_stock_plan (entity, fk_bom, fk_bom_line, status);

UPDATE llx_dolizsynch_property_stock_rule
SET active = 0
WHERE active = 1
  AND (fk_bom IS NULL OR fk_bom <= 0 OR fk_bom_line IS NULL OR fk_bom_line <= 0);

UPDATE llx_dolizsynch_property_stock_plan
SET status = 2,
    error_message = 'BOM identity missing after the 1.32.0 upgrade. Manual review is required.'
WHERE status = 0
  AND (fk_bom IS NULL OR fk_bom <= 0 OR fk_bom_line IS NULL OR fk_bom_line <= 0);
