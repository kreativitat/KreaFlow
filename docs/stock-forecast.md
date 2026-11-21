# PATTIES Stock Forecast API

## Endpoint

`GET /custom/kreaflow/mobile.php?kf_action=stock_forecast&date_start=YYYY-MM-DD&date_end=YYYY-MM-DD`

The endpoint uses the authenticated Dolibarr session. Access requires:

- the active entity to be included in the site-wide `KREAFLOW_STOCK_FORECAST_ENTITIES` setup allowlist, which is disabled until entities are explicitly selected;
- KreaFlow mobile read and Dashboard read permissions;
- KreaProducts mobile inventory read permission;
- KreaProducts expected-stock analysis permission;
- Dolibarr stock read permission.

All inventory, product, warehouse, stock, movement, and sales reads are scoped to the active entity through the native Dolibarr and KreaProducts access rules.

`date_start` and `date_end` control only the visible historical sales interval returned with the underlying sales forecast. They never change sales-model training or stock-demand predictions. Training remains fixed to `KREAFLOW_SF_TRAIN_DAYS`, capped at 365 completed days and ending yesterday.

## Inventory source

The report selects the latest recorded inventory whose KreaProducts template label is exactly `PATTIES`. The category is resolved through the configured KreaProducts inventory tree; product references or descriptions are not used as a fallback.

For each product, the response exposes:

- the quantity physically counted in the latest inventory;
- the inventory stock snapshot reconstructed by KreaProducts;
- current physical stock in the same inventory warehouse;
- forecast requirement, projected stock after that requirement, and any shortage.

## Requirement calculation

KreaProducts provides a 31-calendar-day operational intake and consumption window for the inventory products. KreaFlow excludes the incomplete current day, leaving up to 30 completed days for demand rates. Inventory adjustments, corrections, and reversals remain excluded by the KreaProducts ledger rules.

For each product:

1. Daily consumption is divided by the active entity's daily TTC sales.
2. The overall completed-day consumption-per-euro rate is calculated.
3. When at least two matching weekdays have sales, the forecast rate blends 70% of the matching-weekday rate with 30% of the overall rate.
4. Each native KreaFlow daily TTC sales forecast is multiplied by that rate.
5. The API retains the product's complete-horizon requirement as the sum of the daily quantities for diagnostic compatibility.

The mobile report presents one selectable future forecast day at a time. The current calendar date is excluded and the report defaults to tomorrow, or to the first later date available if tomorrow is absent. `required_quantity` is the requirement for that day only and is never combined with another day in the primary table. The projected end-of-day balance subtracts all displayed future daily requirements through the selected date, because consumption from earlier forecast days still affects the available stock.

Delivery-cycle and complete-horizon values remain in the API for diagnostic compatibility, but they are not used in the primary mobile report.

`projected_stock = current_stock - forecast_required`

`shortage = max(0, -projected_stock)`

For the selected daily view:

`selected_day_projected_stock = current_stock - sum(required_quantity through selected date)`

`selected_day_shortage = max(0, -selected_day_projected_stock)`

The report intentionally does not add a safety-stock buffer and does not subtract planned supplier receipts. Those inputs are reported as excluded in `meta` so the result cannot be mistaken for a replenishment order.

## Response contract

The response contains:

- `category`: exact KreaProducts inventory category;
- `inventory`: latest recorded inventory, warehouse, date, and snapshot time;
- `period`: consumption history and forecast horizons;
- `summary`: product count, delivery/horizon diagnostic risk counts, and complete-horizon forecast TTC sales;
- `products[]`: counted stock, current stock, complete-horizon diagnostics, next-delivery requirement and shortage, daily demand rows, and delivery-cycle rows;
- `meta`: calculation model and excluded planning inputs.

The endpoint is read-only. It does not create or update inventories, stock movements, forecasts, or replenishment documents.
