# Workforce calendar and daylight-saving changes

KreaFlow 3.4.10 copies timetable positions as calendar wall-clock values. A
Sunday 12:00–16:00 shift stays 12:00–16:00 when copied into or out of a week
containing a clock change. Overnight end dates and non-recurring days off follow
the same calendar mapping. Host timezone, browser timezone and the offset at the
moment of copying must not alter the stored hours. Existing merge/replace,
employee, company and leave-conflict rules still apply.

Actual ZoneSoft punches retain their source local timestamps. Import lookback
starts at Lisbon midnight on the configured earlier calendar day, covering the
whole 23-hour or 25-hour day instead of subtracting fixed 24-hour periods.
Punch pairing and elapsed attendance duration use Europe/Lisbon. Planned hours
and elapsed attendance are deliberately separate: a 22:00–02:00 shift at the
autumn transition has four planned hours and five elapsed hours.

The POS `dataval` can remain on yesterday until a till closes. Preserve that
source field in the import queue, but select punches by their actual local
timestamp within the configured working-day window. Derive integrated attendance
dates from the same cutoff. A Monday morning punch labelled Sunday by the POS
must stay open on Monday. OUT-first recovery keeps the IN row as the pair ID and
never searches for an IN before that working day.

The existing pairing job runs every 15 minutes with priority 3, after the
priority-2 source importer. It also refreshes source punches itself and stops on
an import failure before pairing or creating synthetic counterparts. Only
completed operational days are paired; the current operational day stays open.
Pairing, attendance integration and task selection remain company-scoped.
Pairing database failures propagate to the cron result.

Automatic pairing uses the configured calendar overlap, and carries those same
operational dates through attendance and task integration. An explicit recovery
refreshes its requested source window and uses its exact closed dates at every
stage. Older pending entries stay outside that recovery. This prevents a more
frequent job from repeatedly attempting unrelated historical records.

For an existing installation, deploy the runtime files first, back up the native
cron rows, then update only enabled `performPairTimeRegisters` jobs to frequency
1, unitfrequency 900 and priority 3. Keep disabled jobs disabled. Do not
reactivate modules. This release needs no schema change and sends no additional
notifications.

Recovery must compare original source punches with the import queue before
pairing. Back up raw punches, integrated attendance, timetables and affected
task/time-spent records. Repair existing timetable rows only where copy history,
an unchanged source and unchanged target establish the intended hours. Replay
copy chains in chronological order so an earlier correction is carried through
later copies. Preserve later manual edits and retain an audit record of repairs.

`test/copy_week_modes_test.php` covers spring/autumn, forward/reverse copies,
overnight shifts and days off under Lisbon, Zurich and UTC, alongside existing
merge/replace and conflict checks. `test/punchclock_import_test.php` covers
calendar lookback, both occurrences of the repeated autumn hour, source-failure
gating, completed-day guards, company ownership, exact recovery date bounds,
empty responses, replay, rollback and native cron results.
