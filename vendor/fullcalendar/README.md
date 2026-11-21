# FullCalendar Scheduler Assets

## Required Files

Place the following FullCalendar Scheduler v6 files in this directory:

1. **index.global.min.js** - FullCalendar Scheduler bundle (includes Core, Timeline, Resource plugins)
2. **index.global.min.css** - FullCalendar styles

## Download Instructions

### Option 1: NPM (Recommended)

```bash
npm install @fullcalendar/core @fullcalendar/resource-timeline @fullcalendar/interaction
```

Then copy the built files from:

- `node_modules/@fullcalendar/core/index.global.min.js`
- `node_modules/@fullcalendar/core/index.global.min.css`

### Option 2: Manual Download

Visit: https://fullcalendar.io/docs/premium

Purchase and download FullCalendar Scheduler Premium license.

### Option 3: CDN (Development Only)

If local files are not available, the page will attempt to load from CDN.
Uncomment the CDN lines in `index.php` (lines ~85-90).

**Note:** CDN usage requires a valid FullCalendar Scheduler license for production.

## License Key

After obtaining your FullCalendar Scheduler license:

1. Go to Dolibarr: **Home → Setup → Other setup**
2. Add constant: `FULLCALENDAR_SCHEDULER_KEY`
3. Value: Your license key (e.g., `GPL-My-Project-Is-Open-Source`)

## File Structure

```
vendor/
└── fullcalendar/
    ├── index.global.min.js    ← Place here
    ├── index.global.min.css   ← Place here
    └── README.md              ← This file
```

## Verification

After placing the files, verify by:

1. Navigate to HRM → Shift Planner
2. Check browser console for errors
3. Calendar should render with weekly timeline view

If files are missing, you'll see 404 errors in the browser console.
