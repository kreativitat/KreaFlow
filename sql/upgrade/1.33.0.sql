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

UPDATE llx_dolizsynch_property_stock_rule AS stock_rule
LEFT JOIN (
    SELECT fk_bom, COUNT(*) AS line_count
    FROM llx_bom_bomline
    GROUP BY fk_bom
) AS bom_lines ON bom_lines.fk_bom = stock_rule.fk_bom
SET stock_rule.active = 0
WHERE stock_rule.active = 1
  AND (stock_rule.units_per_package <> 1 OR COALESCE(bom_lines.line_count, 0) <> 1);

UPDATE llx_dolizsynch_property_stock_plan AS stock_plan
LEFT JOIN (
    SELECT fk_bom, COUNT(*) AS line_count
    FROM llx_bom_bomline
    GROUP BY fk_bom
) AS bom_lines ON bom_lines.fk_bom = stock_plan.fk_bom
SET stock_plan.status = 2,
    stock_plan.error_message = 'Exact-stock plan uses an obsolete multiplier or an ambiguous dismantling BOM. Manual review is required.'
WHERE stock_plan.status = 0
  AND (stock_plan.units_per_package <> 1 OR COALESCE(bom_lines.line_count, 0) <> 1);
