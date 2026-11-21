# Sales Forecast API

## Endpoint

`POST /custom/kreaflow/mobile.php?action=sales_forecast`

The request uses the authenticated Dolibarr session and requires the KreaFlow Dashboard read permission. The supplied `date_start` and `date_end` control only the historical KPIs and chart returned to the user. They never change model training. Training always uses the configured `KREAFLOW_SF_TRAIN_DAYS`, bounded to 60–365 completed days and ending yesterday, independent of the user's visualization range or history-view permission. Sales remain scoped to the active Dolibarr entity.

## Calculation

HT and TTC are predicted independently. Each model combines:

- a ridge regression using normalized trend, mean temperature, log precipitation, Portuguese public holidays, and weekday seasonality;
- a 90-day recency half-life;
- 2.5th/97.5th-percentile winsorization and a robust upper bound;
- an 80/20 blend with the median of the latest eight matching weekdays.

Forecast history is read through a revenue-only aggregate query. It does not execute the invoice-line quantity aggregation used by the Dashboard, so extending training to one year does not add invoice-line work.

Missing forecast temperature is replaced by the historical mean. If regression training is unavailable, the matching-weekday median is used; the historical average is the final fallback. Predictions are never negative.

## Response compatibility

`forecast_rows[].precip_probability` retains its qualitative legacy value. `forecast_rows[].precip_probability_pct` adds Open-Meteo's numeric forecast percentage (`0` to `100`, or `null` when unavailable). The weather timezone is `Europe/Lisbon`.

`meta.model` is `seasonal_weather_ridge` when historical and forecast weather are available, otherwise `seasonal_ridge`.
