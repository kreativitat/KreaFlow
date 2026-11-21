# KreaFlow for Dolibarr

![KreaFlow](branding/kreaflow/horizontal_logo.png)

KreaFlow brings restaurant and retail operations into a mobile-friendly workspace connected to Dolibarr. Teams can follow sales, manage workforce activity, review financial closes and work with product data, while administrators connect ZoneSoft systems to the ERP.

## What you can do

- **Follow performance:** sales dashboards, daily forecasts and forecast-accuracy views, with weather and calendar context.
- **Plan stock:** compare counted and available stock with expected demand through the KreaProducts integration.
- **Coordinate teams:** work schedules, time records, leave requests and vacation reporting.
- **Review daily operations:** financial closes, Uber Eats analysis and read-only kitchen observations through KreaSynch.
- **Maintain product information:** product lists, selling prices and scheduled price changes.
- **Connect ZoneSoft and Dolibarr:** invoice, product and time-record synchronization, mapping tools and synchronization-health views.

Available screens depend on the user's permissions, enabled Dolibarr modules and configured integrations. Kitchen monitoring requires a KreaSynch feed; stock forecasting requires KreaProducts and an explicitly enabled company.

## Requirements

- Dolibarr 19 or later and PHP 7.1 or later, as declared by the module.
- A Dolibarr user account with access to the intended company and KreaFlow workflows.
- HTTPS for browser installation as a progressive web app.
- Access to the relevant external services for the integrations you enable.

## Installation and setup

1. Place the module in `htdocs/custom/kreaflow`. Keep the directory name `kreaflow`.
2. Enable **KreaFlow** in Dolibarr's module administration.
3. Grant the required KreaFlow and native Dolibarr permissions to users or groups.
4. Configure mobile views, visible product categories and history limits in [Mobile setup](admin/mobile_setup.php).
5. Configure only the integrations you use: store and company mappings, ZoneSoft access, kitchen sources, forecasts and scheduled jobs.
6. Open `/custom/kreaflow/mobile.php` from your Dolibarr installation and sign in.

For an installation that previously used separate `kreaflow` and `dolizsynch` folders, follow the [single-folder upgrade guide](compatibility/dolizsynch/README.md) before changing the installed directories.

## Using the mobile app

Select the active company, then open the dashboard or operational area you need. The application uses your Dolibarr session and permissions; access to a mobile screen does not replace the underlying ERP permissions.

KreaFlow can be added to a device's home screen through a supported browser. On iPhone or iPad, use **Share → Add to Home Screen**; on desktop Safari, use **Add to Dock**. Chromium browsers offer installation when the browser's requirements are met.

## Documentation

- [Sales forecasting](docs/sales-forecast.md)
- [Stock forecasting and its inventory requirements](docs/stock-forecast.md)
- [Kitchen integration and source coverage](docs/kitchen-integration.md)
- [Vacation reporting](docs/vacation-report.md)
- [Invoice discounts](docs/invoice-discounts.md) and [credit notes](docs/invoice-credit-notes.md)
- [Security reporting](SECURITY.md)

Kitchen data represents retained observations from the configured source. It is not a guarantee of a complete live kitchen queue.

## Development

The PHP module lives in the repository root, `class/`, `api/` and `admin/`. The React and TypeScript application is in `app/`; the installed PWA assets are in `frontend/`.

With Node.js 22 or later:

```bash
npm --prefix app ci
npm --prefix app run dev
```

Use `npm --prefix app run build` to check and build the frontend. The Vite development server still needs an authenticated Dolibarr backend for ERP workflows.

## License

GPL-3.0-or-later. See [COPYING](COPYING) and [LICENSE](LICENSE). Third-party components retain their own notices.

Maintained by [Kreativität Works](https://kreativitat.com).
