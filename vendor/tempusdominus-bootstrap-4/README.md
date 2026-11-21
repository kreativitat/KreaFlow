# Tempus Dominus Bootstrap 4 - Local Installation

## Installed Files

- `tempusdominus-bootstrap-4.min.css` - CSS styles
- `tempusdominus-bootstrap-4.min.js` - JavaScript library

## Dependencies Required

Tempus Dominus Bootstrap 4 requires the following libraries to be loaded **before** it:

1. **jQuery** (already included in Dolibarr)
2. **Bootstrap 4 CSS & JS** (installed in `../bootstrap4/`)
3. **Moment.js** (installed in `../moment/moment.min.js`)
4. **Font Awesome** (for icons - optional but recommended)

## Usage in Your PHP Files

Add these includes to your HTML `<head>` section:

```html
<!-- Bootstrap 4 CSS -->
<link rel="stylesheet" href="<?php echo dol_buildpath('/kreaflow/vendor/bootstrap4/bootstrap.min.css', 1); ?>">

<!-- Tempus Dominus CSS -->
<link rel="stylesheet" href="<?php echo dol_buildpath('/kreaflow/vendor/tempusdominus-bootstrap-4/tempusdominus-bootstrap-4.min.css', 1); ?>">

<!-- Moment.js (required) -->
<script src="<?php echo dol_buildpath('/kreaflow/vendor/moment/moment.min.js', 1); ?>"></script>

<!-- Bootstrap 4 JS Bundle (includes Popper.js) -->
<script src="<?php echo dol_buildpath('/kreaflow/vendor/bootstrap4/bootstrap.bundle.min.js', 1); ?>"></script>

<!-- Tempus Dominus JS (load after moment.js and Bootstrap) -->
<script src="<?php echo dol_buildpath('/kreaflow/vendor/tempusdominus-bootstrap-4/tempusdominus-bootstrap-4.min.js', 1); ?>"></script>
```

## Basic Example

```html
<div class="form-group">
    <label>Date & Time:</label>
    <div class="input-group date" id="datetimepicker1" data-target-input="nearest">
        <input type="text" class="form-control datetimepicker-input" data-target="#datetimepicker1"/>
        <div class="input-group-append" data-target="#datetimepicker1" data-toggle="datetimepicker">
            <div class="input-group-text"><i class="fa fa-calendar"></i></div>
        </div>
    </div>
</div>

<script type="text/javascript">
    $(function () {
        $('#datetimepicker1').datetimepicker({
            format: 'YYYY-MM-DD HH:mm',
            locale: 'pt',
            sideBySide: true
        });
    });
</script>
```

## Date Picker Only

```javascript
$('#datepicker').datetimepicker({
    format: 'YYYY-MM-DD',
    locale: 'pt'
});
```

## Time Picker Only

```javascript
$('#timepicker').datetimepicker({
    format: 'HH:mm',
    locale: 'pt'
});
```

## Date Range Example

```javascript
// Start date
$('#startDate').datetimepicker({
    format: 'YYYY-MM-DD'
});

// End date (restrict to after start date)
$('#endDate').datetimepicker({
    format: 'YYYY-MM-DD',
    useCurrent: false
});

// Link them together
$("#startDate").on("change.datetimepicker", function (e) {
    $('#endDate').datetimepicker('minDate', e.date);
});

$("#endDate").on("change.datetimepicker", function (e) {
    $('#startDate').datetimepicker('maxDate', e.date);
});
```

## Common Options

- `format` - Date/time format (e.g., 'YYYY-MM-DD', 'HH:mm', 'YYYY-MM-DD HH:mm')
- `locale` - Language locale (e.g., 'pt', 'en', 'fr')
- `sideBySide` - Show calendar and time picker side by side
- `stepping` - Time stepping in minutes (default: 1)
- `minDate` - Minimum selectable date
- `maxDate` - Maximum selectable date
- `defaultDate` - Default date to display
- `useCurrent` - Use current date/time as default
- `showClear` - Show clear button
- `showClose` - Show close button
- `showTodayButton` - Show today button
- `icons` - Customize icons

## Documentation

Full documentation: https://tempusdominus.github.io/bootstrap-4/

## Version

Tempus Dominus Bootstrap 4 v5.39.0
Moment.js v2.29.4
