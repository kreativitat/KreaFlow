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

ALTER TABLE llx_dolizsynch_property_stock_plan
    ADD COLUMN IF NOT EXISTS source_purchase_qty decimal(24,8) NULL AFTER fk_product_output,
    ADD COLUMN IF NOT EXISTS source_unit_cost decimal(24,8) NULL AFTER source_purchase_qty,
    ADD COLUMN IF NOT EXISTS source_stock_value decimal(24,8) NULL AFTER source_unit_cost,
    ADD COLUMN IF NOT EXISTS source_line_total_ht decimal(24,8) NULL AFTER source_stock_value,
    ADD COLUMN IF NOT EXISTS exact_unit_cost decimal(24,8) NULL AFTER source_line_total_ht;
