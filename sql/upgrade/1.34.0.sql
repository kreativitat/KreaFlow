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
INNER JOIN llx_bom_bom AS bom ON bom.rowid = stock_rule.fk_bom
INNER JOIN llx_bom_bomline AS bom_line
    ON bom_line.rowid = stock_rule.fk_bom_line
   AND bom_line.fk_bom = stock_rule.fk_bom
   AND bom_line.fk_product = stock_rule.fk_product_output
INNER JOIN (
    SELECT fk_bom, COUNT(*) AS line_count
    FROM llx_bom_bomline
    GROUP BY fk_bom
) AS bom_lines ON bom_lines.fk_bom = stock_rule.fk_bom
SET stock_rule.units_per_package = bom_line.qty
WHERE bom.bomtype = 1
  AND bom.status = 1
  AND bom_lines.line_count = 1
  AND bom_line.qty > 0
  AND MOD(bom_line.qty, 1) = 0;

UPDATE llx_dolizsynch_property_stock_rule AS stock_rule
LEFT JOIN llx_bom_bom AS bom ON bom.rowid = stock_rule.fk_bom
LEFT JOIN llx_bom_bomline AS bom_line
    ON bom_line.rowid = stock_rule.fk_bom_line
   AND bom_line.fk_bom = stock_rule.fk_bom
   AND bom_line.fk_product = stock_rule.fk_product_output
LEFT JOIN (
    SELECT fk_bom, COUNT(*) AS line_count
    FROM llx_bom_bomline
    GROUP BY fk_bom
) AS bom_lines ON bom_lines.fk_bom = stock_rule.fk_bom
SET stock_rule.active = 0
WHERE stock_rule.active = 1
  AND (
      bom.rowid IS NULL
      OR bom.bomtype <> 1
      OR bom.status <> 1
      OR COALESCE(bom_lines.line_count, 0) <> 1
      OR bom_line.rowid IS NULL
      OR bom_line.qty <= 0
      OR MOD(bom_line.qty, 1) <> 0
  );

UPDATE llx_dolizsynch_property_stock_plan AS stock_plan
LEFT JOIN llx_bom_bom AS bom ON bom.rowid = stock_plan.fk_bom
LEFT JOIN llx_bom_bomline AS bom_line
    ON bom_line.rowid = stock_plan.fk_bom_line
   AND bom_line.fk_bom = stock_plan.fk_bom
   AND bom_line.fk_product = stock_plan.fk_product_output
LEFT JOIN (
    SELECT fk_bom, COUNT(*) AS line_count
    FROM llx_bom_bomline
    GROUP BY fk_bom
) AS bom_lines ON bom_lines.fk_bom = stock_plan.fk_bom
SET stock_plan.status = 2,
    stock_plan.error_message = 'Exact-stock plan does not match the selected BOM output quantity per package. Manual review is required.'
WHERE stock_plan.status = 0
  AND (
      bom.rowid IS NULL
      OR bom.bomtype <> 1
      OR COALESCE(bom_lines.line_count, 0) <> 1
      OR bom_line.rowid IS NULL
      OR bom_line.qty <= 0
      OR MOD(bom_line.qty, 1) <> 0
      OR stock_plan.units_per_package <> bom_line.qty
      OR stock_plan.target_units <> stock_plan.package_count * stock_plan.units_per_package
  );
