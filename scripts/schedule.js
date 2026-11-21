/**
 * Unified scheduler bundle - auto generated.
 *
 * DEPENDENCIES (loaded via PHP files):
 * - jQuery (from Dolibarr core)
 * - Bootstrap 4 (from Dolibarr core)
 * - Moment.js (local: vendor/moment/moment.min.js)
 * - Tempus Dominus Bootstrap 4 (local: vendor/tempusdominus-bootstrap-4/)
 *
 * These dependencies are included in schedule_bars.php and schedule_employees.php
 */
(function () {
	const isBars = document.getElementById("timegrid-table");
	const isEmployees = document.getElementById("shift-table");
	const INCLUDE_FOREIGN_SHIFTS = Boolean(window.SHIFT_INCLUDE_FOREIGN);
	const MINUTES_PER_DAY = 24 * 60;
	const GLOBAL_CAN_EDIT_EMPLOYEE_HOURS = Boolean(
		window.SHIFT_CAN_EDIT_EMPLOYEE_HOURS
	);
	const GLOBAL_CAN_ASSIGN_MAIN_TASK = Boolean(
		window.SHIFT_CAN_ASSIGN_MAIN_TASK
	);
	const GLOBAL_QUOTA_SAVE_BTN = document.getElementById("quota-save");
	const EDIT_MODE = Boolean(
		window.SHIFT_EDIT_MODE ||
			(typeof window.location !== "undefined" &&
				window.location.search.includes("edit_mode=1"))
	);
	const canWrite = Boolean(window.SHIFT_CAN_WRITE || EDIT_MODE);
	const canDelete = Boolean(window.SHIFT_CAN_DELETE || EDIT_MODE);

	const CURRENT_ENTITY = shiftplannerToNumber(window.SHIFT_ENTITY, null);
	const weekNotesBox = document.getElementById("week-notes");
	const weekNotesTitle = document.getElementById("week-notes-title");
	const weekNotesTextarea = document.getElementById("week-notes-text");
	const weekNotesSaveBtn = document.getElementById("week-notes-save");
	const WEEK_NOTES_KEY = "dolizsynch_week_notes";
	// Default no-op; employees view replaces this with real implementation.
	let updateWeekNotesUI = () => {};
	if (!canWrite) {
		if (weekNotesSaveBtn) {
			weekNotesSaveBtn.style.display = "none";
		}
		if (weekNotesTextarea) {
			weekNotesTextarea.readOnly = true;
		}
	}

	const GLOBAL_SHIFT_LABELS = window.SHIFT_LABELS || {};
	function translateApiError(message, fallback) {
		if (!message) {
			return fallback || "";
		}
		if (message === "wrong_entity") {
			return (
				GLOBAL_SHIFT_LABELS.wrongEntity ||
				fallback ||
				"Este registo pertence a outra entidade."
			);
		}
		return message;
	}
	function getShiftLabel(key, fallback = "") {
		if (
			GLOBAL_SHIFT_LABELS &&
			typeof GLOBAL_SHIFT_LABELS[key] === "string" &&
			GLOBAL_SHIFT_LABELS[key].length
		) {
			return GLOBAL_SHIFT_LABELS[key];
		}
		return fallback;
	}

	function shiftplannerToNumber(value, fallback) {
		const parsed = Number(value);
		return Number.isFinite(parsed) ? parsed : fallback;
	}

	const DEFAULT_DAY_START_MINUTES = 7 * 60;
	const DEFAULT_DAY_END_MINUTES = DEFAULT_DAY_START_MINUTES + 18 * 60; // 07:00 to 01:00 (covers midnight by default)
	const DEFAULT_MINUTE_STEP = 30;

	const SHIFT_DAY_START_MINUTES = shiftplannerToNumber(
		window.SHIFT_DAY_START_MINUTES,
		DEFAULT_DAY_START_MINUTES
	);

	let shiftDayEndMinutes = shiftplannerToNumber(
		window.SHIFT_DAY_END_MINUTES,
		DEFAULT_DAY_END_MINUTES
	);
	let shiftDayDuration = shiftDayEndMinutes - SHIFT_DAY_START_MINUTES;
	if (shiftDayDuration < 8 * 60) {
		shiftDayEndMinutes = SHIFT_DAY_START_MINUTES + 8 * 60;
		shiftDayDuration = 8 * 60;
	} else if (shiftDayDuration > MINUTES_PER_DAY) {
		shiftDayEndMinutes = SHIFT_DAY_START_MINUTES + MINUTES_PER_DAY;
		shiftDayDuration = MINUTES_PER_DAY;
	}

	const SHIFT_DAY_END_MINUTES = shiftDayEndMinutes;
	const SHIFT_DAY_DURATION_MINUTES = shiftDayDuration;
	const SHIFT_DAY_START_HOUR = Math.floor(SHIFT_DAY_START_MINUTES / 60) % 24;
	const SHIFT_DAY_LAST_HOUR = Math.floor(
		((SHIFT_DAY_END_MINUTES - 1) % MINUTES_PER_DAY) / 60
	);
	const SHIFT_DAY_CROSSES_MIDNIGHT = SHIFT_DAY_END_MINUTES > MINUTES_PER_DAY;
	const SHIFT_DAY_WRAP_MINUTES = SHIFT_DAY_CROSSES_MIDNIGHT
		? (SHIFT_DAY_LAST_HOUR + 1) * 60
		: 0;

	const rawMinuteStep = Math.trunc(
		shiftplannerToNumber(window.SHIFT_MINUTE_STEP, DEFAULT_MINUTE_STEP)
	);
	const SHIFT_MINUTE_STEP =
		rawMinuteStep >= 1 && rawMinuteStep <= 60 && 60 % rawMinuteStep === 0
			? rawMinuteStep
			: DEFAULT_MINUTE_STEP;
	const SHIFT_MINUTE_STEP_SECONDS = SHIFT_MINUTE_STEP * 60;
	const PUBLIC_HOLIDAYS = Array.isArray(window.SHIFT_PUBLIC_HOLIDAYS)
		? window.SHIFT_PUBLIC_HOLIDAYS.filter(
				(entry) => entry && entry.date && typeof entry.date === "string"
		  )
		: [];
	const HOLIDAY_MAP = new Map();

	function syncPublicHolidayMap(entries) {
		HOLIDAY_MAP.clear();
		if (!Array.isArray(entries)) {
			return;
		}
		entries.forEach((entry) => {
			if (!entry || !entry.date) return;
			const dateKey = String(entry.date).slice(0, 10);
			if (!dateKey) return;
			HOLIDAY_MAP.set(dateKey, {
				date: dateKey,
				name: entry.name || entry.label || "",
			});
		});
	}

	syncPublicHolidayMap(PUBLIC_HOLIDAYS);

	function getHolidayInfoByDate(dateISO) {
		if (!dateISO) return null;
		return HOLIDAY_MAP.get(dateISO) || null;
	}

	async function shiftplannerRefreshPublicHolidays(apiUrl, startIso, endIso) {
		if (!apiUrl || !startIso || !endIso) {
			syncPublicHolidayMap([]);
			return;
		}
		try {
			const resp = await fetch(
				apiUrl +
					"?" +
					new URLSearchParams({
						action: "public_holidays",
						start: startIso,
						end: endIso,
					})
			);
			if (!resp.ok) {
				throw new Error("HTTP " + resp.status);
			}
			const payload = await resp.json();
			const entries = Array.isArray(payload.public_holidays)
				? payload.public_holidays
				: [];
			syncPublicHolidayMap(entries);
		} catch (error) {
			console.warn("Failed to refresh public holidays:", error);
			syncPublicHolidayMap([]);
		}
	}

	const SHIFT_MAX_SHIFT_HOURS = shiftplannerToNumber(
		window.SHIFT_MAX_SHIFT_HOURS,
		8
	);
	const SHIFT_MAX_SHIFT_MINUTES =
		SHIFT_MAX_SHIFT_HOURS && SHIFT_MAX_SHIFT_HOURS > 0
			? SHIFT_MAX_SHIFT_HOURS * 60
			: 0;
	const DEFAULT_TASK_TEXT_COLOR = "#ffffff";

	function shiftplannerNormalizeTaskRecord(task) {
		if (!task || typeof task !== "object") {
			return {
				id: "",
				label: "",
				color: "#3788d8",
				textColor: DEFAULT_TASK_TEXT_COLOR,
			};
		}
		const normalized = { ...task };
		normalized.color = normalized.color || normalized.bgcolor || "#3788d8";
		normalized.textColor =
			normalized.textColor ||
			normalized.text_color ||
			normalized.color_text ||
			DEFAULT_TASK_TEXT_COLOR;
		return normalized;
	}

	function getMonday(date) {
		if (!date) return null;
		const d = new Date(date);
		if (Number.isNaN(d.getTime())) return null;
		d.setHours(0, 0, 0, 0);
		const day = d.getDay();
		const offset = day === 0 ? -6 : 1 - day;
		d.setDate(d.getDate() + offset);
		return d;
	}

	function getIsoWeek(date) {
		const tmp = new Date(date);
		if (Number.isNaN(tmp.getTime())) return { year: NaN, week: NaN };
		tmp.setHours(0, 0, 0, 0);
		tmp.setDate(tmp.getDate() + 3 - ((tmp.getDay() + 6) % 7));
		const week1 = new Date(tmp.getFullYear(), 0, 4);
		const weekNumber =
			1 +
			Math.round(
				((tmp.getTime() - week1.getTime()) / 86400000 -
					3 +
					((week1.getDay() + 6) % 7)) /
					7
			);
		return { year: tmp.getFullYear(), week: weekNumber };
	}

	function getMondayOfIsoWeek(year, week) {
		if (!Number.isFinite(year) || !Number.isFinite(week)) return null;
		const simple = new Date(year, 0, 1 + (week - 1) * 7);
		const dow = simple.getDay();
		const ISOweekStart = simple;
		if (dow <= 4 && dow !== 0) {
			ISOweekStart.setDate(simple.getDate() - simple.getDay() + 1);
		} else {
			ISOweekStart.setDate(simple.getDate() + 8 - simple.getDay());
		}
		return getMonday(ISOweekStart);
	}

	function formatDate(date) {
		const d = new Date(date);
		if (Number.isNaN(d.getTime())) return "";
		const day = String(d.getDate()).padStart(2, "0");
		const month = String(d.getMonth() + 1).padStart(2, "0");
		return `${day}/${month}`;
	}

	function formatDateISO(date) {
		const d = new Date(date);
		if (Number.isNaN(d.getTime())) return "";
		const year = d.getFullYear();
		const month = String(d.getMonth() + 1).padStart(2, "0");
		const day = String(d.getDate()).padStart(2, "0");
		return `${year}-${month}-${day}`;
	}

	function formatDetailedWeekRange(date) {
		if (!date) return "";
		const isoWeek = getIsoWeek(date);
		const startLabel = formatDate(date);
		const endDate = new Date(date);
		endDate.setDate(endDate.getDate() + 6);
		const endLabel = formatDate(endDate);
		return `Semana ${isoWeek.week} de ${isoWeek.year} (${startLabel} a ${endLabel})`;
	}

	function formatWeekInput(date) {
		if (!date) return "";
		const iso = getIsoWeek(date);
		const week = String(iso.week || "").padStart(2, "0");
		return `${iso.year}-W${week}`;
	}

	function formatHoursValue(hours, forceSingleDecimal = false) {
		if (!Number.isFinite(hours) || hours < 0) {
			return forceSingleDecimal ? "0.0h" : "0h";
		}
		const rounded = Math.round(hours * 10) / 10;
		let display;
		if (forceSingleDecimal) {
			display = rounded.toFixed(1);
		} else {
			display = Number.isInteger(rounded)
				? `${rounded}`
				: rounded.toFixed(1).replace(/\.0$/, "");
		}
		return `${display}h`;
	}

	function formatHoursLabelNoSuffix(hours) {
		const labeled = formatHoursValue(hours, true);
		return labeled.replace(/h$/, "");
	}

	function parseWeekInput(value) {
		if (!value || !/^\d{4}-W\d{2}$/.test(value)) return null;
		const [yearPart, weekPart] = value.split("-W");
		const year = parseInt(yearPart, 10);
		const week = parseInt(weekPart, 10);
		if (!Number.isFinite(year) || !Number.isFinite(week)) return null;
		return getMondayOfIsoWeek(year, week);
	}

	function shiftplannerFormatISODateLocal(dateObj) {
		const year = dateObj.getFullYear();
		const month = String(dateObj.getMonth() + 1).padStart(2, "0");
		const day = String(dateObj.getDate()).padStart(2, "0");
		return `${year}-${month}-${day}`;
	}

	function shiftplannerExtractMinutes(isoString) {
		const match = isoString && isoString.match(/T(\d{2}):(\d{2})/);
		if (!match) return null;
		const hours = parseInt(match[1], 10);
		const minutes = parseInt(match[2], 10);
		if (Number.isNaN(hours) || Number.isNaN(minutes)) {
			return null;
		}
		return hours * 60 + minutes;
	}

	function shiftplannerShouldWrapPreviousDay(minutes) {
		if (!SHIFT_DAY_CROSSES_MIDNIGHT) return false;
		return typeof minutes === "number" && minutes < SHIFT_DAY_WRAP_MINUTES;
	}

	function shiftplannerNormalizeMinutes(minutes) {
		if (typeof minutes !== "number") return null;
		return shiftplannerShouldWrapPreviousDay(minutes)
			? minutes + MINUTES_PER_DAY
			: minutes;
	}

	function shiftplannerMinutesToIso(baseDateISO, normalizedMinutes) {
		if (!baseDateISO || typeof normalizedMinutes !== "number") {
			return `${baseDateISO || ""}T00:00:00`;
		}
		const baseDate = new Date(`${baseDateISO}T00:00:00`);
		if (Number.isNaN(baseDate.getTime())) {
			return `${baseDateISO}T00:00:00`;
		}
		let minutes = normalizedMinutes;
		let dayOffset = 0;
		if (minutes >= MINUTES_PER_DAY) {
			dayOffset = Math.floor(minutes / MINUTES_PER_DAY);
			minutes = minutes % MINUTES_PER_DAY;
		}
		if (minutes < 0) {
			const neededDays = Math.ceil(Math.abs(minutes) / MINUTES_PER_DAY);
			dayOffset -= neededDays;
			minutes += neededDays * MINUTES_PER_DAY;
		}
		baseDate.setDate(baseDate.getDate() + dayOffset);
		const hours = Math.floor(minutes / 60);
		const mins = minutes % 60;
		return `${shiftplannerFormatISODateLocal(baseDate)}T${String(
			hours
		).padStart(2, "0")}:${String(mins).padStart(2, "0")}:00`;
	}

	function shiftplannerMatchesMinuteStep(isoString) {
		if (!isoString) return false;
		const match = isoString.match(/T(\d{2}):(\d{2})/);
		if (!match) return false;
		const hours = parseInt(match[1], 10);
		const minutes = parseInt(match[2], 10);
		if (Number.isNaN(hours) || Number.isNaN(minutes)) {
			return false;
		}
		const totalMinutes = hours * 60 + minutes;
		return totalMinutes % SHIFT_MINUTE_STEP === 0;
	}

	function shiftBelongsToCurrentEntity(shift) {
		if (INCLUDE_FOREIGN_SHIFTS) {
			return true;
		}
		if (CURRENT_ENTITY === null || CURRENT_ENTITY === undefined) {
			return true;
		}
		if (!shift || typeof shift !== "object") return true;
		const foreignFlag =
			shift.foreign_entity ??
			shift.foreignEntity ??
			(shift.extendedProps && shift.extendedProps.foreign_entity);
		if (foreignFlag === true || foreignFlag === 1 || foreignFlag === "1") {
			return false;
		}
		const raw =
			shift.entity ??
			shift.fk_entity ??
			(shift.extendedProps && shift.extendedProps.entity);
		if (raw === undefined || raw === null || raw === "") return true;
		const num = Number(raw);
		return Number.isFinite(num) && num === CURRENT_ENTITY;
	}

	function isShiftRecord(entry) {
		if (!entry || typeof entry !== "object") return false;
		const entryType = (entry.entry_type || entry.type || "")
			.toString()
			.toLowerCase();
		if (entryType && entryType !== "shift") return false;
		if (entry.is_leave === 1 || entry.is_dayoff === 1 || entry.dayoff === 1)
			return false;
		if (!entry.start || !entry.end) return false;
		return true;
	}

	function normalizeShiftArray(payload) {
		if (payload && payload.error) {
			throw new Error(translateApiError(payload.error, payload.error));
		}
		if (Array.isArray(payload)) {
			return payload;
		}
		if (payload && Array.isArray(payload.shifts)) {
			return payload.shifts;
		}
		if (payload && Array.isArray(payload.data)) {
			return payload.data;
		}
		return [];
	}

	function normalizeTimeString(value) {
		if (!value) return "";
		const trimmed = value.trim();
		if (!trimmed) return "";
		const timeMatch = trimmed.match(/^(\d{1,2})(?::(\d{0,2}))?$/);
		if (timeMatch) {
			let hours = parseInt(timeMatch[1], 10);
			let minutes = timeMatch[2] !== undefined ? parseInt(timeMatch[2], 10) : 0;
			if (Number.isNaN(hours) || Number.isNaN(minutes)) {
				return "";
			}
			hours = Math.max(0, Math.min(23, hours));
			minutes = Math.max(0, Math.min(59, minutes));
			return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(
				2,
				"0"
			)}`;
		}
		const parsed = new Date(trimmed);
		if (!Number.isNaN(parsed.getTime())) {
			return `${String(parsed.getHours()).padStart(2, "0")}:${String(
				parsed.getMinutes()
			).padStart(2, "0")}`;
		}
		return "";
	}

	function initializeTimePicker(pickerId, timeValue, parentNode) {
		if (
			typeof window.jQuery === "undefined" ||
			!window.jQuery.fn ||
			!window.jQuery.fn.datetimepicker
		) {
			return;
		}
		const $ = window.jQuery;
		const pickerElement = document.getElementById(pickerId);
		if (!pickerElement) return;
		const $picker = $(pickerElement);

		// Build/locate a dedicated, positioned anchor to avoid placement errors
		let anchor = pickerElement.closest(".timepicker-anchor");
		if (!anchor) {
			anchor = document.createElement("div");
			anchor.className = "timepicker-anchor";
			pickerElement.parentNode.insertBefore(anchor, pickerElement);
			anchor.appendChild(pickerElement);
		}
		const widgetParent = $(anchor);
		if (!widgetParent.length) return;
		const currentPos = widgetParent.css("position");
		if (!currentPos || currentPos === "static") {
			widgetParent.css("position", "relative");
		}
		widgetParent.css("overflow", "visible");
		const options = {
			format: "HH:mm",
			stepping: SHIFT_MINUTE_STEP,
			icons: {
				time: "far fa-clock",
				date: "far fa-calendar-alt",
				up: "fas fa-chevron-up",
				down: "fas fa-chevron-down",
				previous: "fas fa-chevron-left",
				next: "fas fa-chevron-right",
				today: "far fa-calendar-check",
				clear: "far fa-trash-alt",
				close: "far fa-times-circle",
			},
			allowInputToggle: true,
			widgetParent,
			widgetPositioning: {
				horizontal: "auto",
				vertical: "bottom",
			},
			useCurrent: false,
		};
		$picker.datetimepicker(options);
		setTimeout(() => $picker.datetimepicker("hide"), 0);
		const inputEl = $picker.find(".datetimepicker-input");
		if (inputEl.length) {
			const pickerInstance = $picker.data("datetimepicker");
			if (pickerInstance) {
				inputEl.each((_, el) => {
					window.jQuery(el).data("datetimepicker", pickerInstance);
				});
			}
			inputEl.attr("data-toggle", null);
			inputEl.attr("data-target", null);
			inputEl.on("focus click", (e) => {
				e.preventDefault();
				$picker.datetimepicker("show");
			});
			const enforceManualTime = () => {
				const normalized = normalizeTimeString(inputEl.val());
				if (normalized) {
					inputEl.val(normalized);
				}
			};
			inputEl.on("blur change", enforceManualTime);
			$picker.on("change.datetimepicker", (e) => {
				if (e && e.date && typeof e.date.format === "function") {
					inputEl.val(e.date.format("HH:mm"));
				}
			});
		}
		const value = (timeValue || "").trim();
		if (!value) return;
		if (typeof window.moment === "function") {
			const m = window.moment(value, "HH:mm");
			if (m.isValid()) {
				$picker.datetimepicker("date", m);
				$picker.datetimepicker("hide");
				return;
			}
		}
		$picker.find("input").val(value);
		setTimeout(() => $picker.datetimepicker("hide"), 0);
	}

	function createWeekPicker(inputEl, options = {}) {
		if (
			!inputEl ||
			typeof window.jQuery === "undefined" ||
			!window.jQuery.fn ||
			!window.jQuery.fn.datepicker
		) {
			return null;
		}
		const $ = window.jQuery;
		const $input = $(inputEl);
		$input.attr({
			readonly: "readonly",
			autocomplete: "off",
			inputmode: "none",
			type: "text",
		});
		const langToken = (
			options.language ||
			window.SHIFT_WEEK_PICKER_LANG ||
			"en"
		)
			.toString()
			.toLowerCase()
			.split("-")[0];
		const thisWeekLabel = options.thisWeekLabel || "This week";
		const externalOnSelect =
			typeof options.onSelect === "function" ? options.onSelect : null;
		const normalizeMonday = (dateObj) => {
			if (!dateObj) return null;
			const d = new Date(dateObj);
			if (Number.isNaN(d.getTime())) return null;
			d.setHours(0, 0, 0, 0);
			const offset = (d.getDay() + 6) % 7;
			d.setDate(d.getDate() - offset);
			return d;
		};
		const highlightWeekRow = () => {
			window.requestAnimationFrame(() => {
				const widget = $(".datepicker");
				if (!widget.length) return;
				widget.find("table tr").removeClass("week-selected");
				const activeRow = widget.find(".day.active").closest("tr");
				if (activeRow.length) {
					activeRow.addClass("week-selected");
				}
				widget.find(".today").text(thisWeekLabel);
			});
		};
		const showPicker = () => {
			$input.datepicker("show");
		};
		$input
			.datepicker({
				format: "dd/mm/yyyy",
				language: langToken,
				weekStart: 1,
				calendarWeeks: true,
				autoclose: true,
				todayBtn: true,
				todayHighlight: true,
				zIndexOffset: 12000,
			})
			.on("show", highlightWeekRow)
			.on("changeDate", (e) => {
				highlightWeekRow();
				if (e.date) {
					const monday = normalizeMonday(e.date);
					if (monday) {
						window.setTimeout(() => {
							setWeekInputDisplay(inputEl, monday);
						}, 0);
						if (externalOnSelect) {
							externalOnSelect(monday);
						}
					}
				}
			});
		$input.on("click focus", (e) => {
			e.preventDefault();
			showPicker();
		});
		return {
			update(date) {
				const monday = normalizeMonday(date);
				if (monday) {
					$input.datepicker("update", monday);
					setWeekInputDisplay(inputEl, monday);
					highlightWeekRow();
				}
			},
			show: showPicker,
		};
	}

	function setWeekInputDisplay(inputEl, date) {
		if (!inputEl || !date) return;
		const isoValue = formatWeekInput(date);
		inputEl.dataset.isoValue = isoValue;
		inputEl.value = formatDetailedWeekRange(date);
	}

	function getWeekInputDateValue(inputEl) {
		if (!inputEl) return null;
		const isoValue =
			(inputEl.dataset && inputEl.dataset.isoValue) || inputEl.value || "";
		return parseWeekInput(isoValue);
	}

	function getShiftDurationLimitMessage() {
		const hours = SHIFT_MAX_SHIFT_HOURS || 8;
		return tLabel(
			"maxShiftHoursExceeded",
			"Shift duration cannot exceed %s hours."
		).replace("%s", hours);
	}

	function isShiftDurationAllowed(startIso, endIso) {
		if (!SHIFT_MAX_SHIFT_MINUTES) return true;
		const minutes = getShiftDurationMinutes(startIso, endIso);
		if (!minutes || minutes <= 0) return false;
		return minutes <= SHIFT_MAX_SHIFT_MINUTES;
	}

	function shiftplannerGetDisplayDate(isoString) {
		if (!isoString) return "";
		const baseDate = isoString.slice(0, 10);
		const minutes = shiftplannerExtractMinutes(isoString);
		if (minutes === null || !baseDate) {
			return baseDate;
		}
		if (!shiftplannerShouldWrapPreviousDay(minutes)) {
			return baseDate;
		}
		const dateObj = new Date(`${baseDate}T00:00:00`);
		if (Number.isNaN(dateObj.getTime())) {
			return baseDate;
		}
		dateObj.setDate(dateObj.getDate() - 1);
		return shiftplannerFormatISODateLocal(dateObj);
	}

	function normalizeEmployeeKey(value) {
		if (value === undefined || value === null) return null;
		return String(value);
	}

	function normalizeTaskKey(value) {
		if (value === undefined || value === null) return null;
		return String(value);
	}

	function getShiftEmployeeKey(shift) {
		return normalizeEmployeeKey(shift.resourceId ?? shift.fk_user);
	}

	function getShiftTaskKey(shift) {
		const raw = shift.fk_task ?? shift.taskId;
		return raw === undefined || raw === null ? null : normalizeTaskKey(raw);
	}

	function compareEmployeeTitles(a, b) {
		const titleA = (a && a.title ? a.title : "").trim().toLowerCase();
		const titleB = (b && b.title ? b.title : "").trim().toLowerCase();
		if (titleA < titleB) return -1;
		if (titleA > titleB) return 1;
		const idA = a && a.id ? String(a.id) : "";
		const idB = b && b.id ? String(b.id) : "";
		if (idA < idB) return -1;
		if (idA > idB) return 1;
		return 0;
	}

	function normalizeMainTaskId(value) {
		if (value === undefined || value === null) return "";
		const str = String(value).trim();
		if (!str || str === "0") return "";
		return str;
	}

	// Shared conflict resolution helpers (used by both bars and employees views)
	if (typeof window.shiftplannerDeleteShiftsForConflict !== "function") {
		window.shiftplannerDeleteShiftsForConflict = async function (shiftIds) {
			const apiUrl = window.SHIFT_API_URL;
			for (const id of shiftIds || []) {
				const resp = await fetch(apiUrl, {
					method: "POST",
					headers: { "Content-Type": "application/x-www-form-urlencoded" },
					body: new URLSearchParams({
						action: "delete",
						id: id,
					}),
				});
				const result = await resp.json();
				if (!result.success) {
					throw new Error(
						translateApiError(result.error, "Erro ao eliminar turno")
					);
				}
			}
		};
	}
	if (typeof window.shiftplannerDeleteDayOffsForConflict !== "function") {
		window.shiftplannerDeleteDayOffsForConflict = async function (dayoffIds) {
			const apiUrl = window.SHIFT_API_URL;
			for (const id of dayoffIds || []) {
				const resp = await fetch(apiUrl, {
					method: "POST",
					headers: { "Content-Type": "application/x-www-form-urlencoded" },
					body: new URLSearchParams({
						action: "delete_dayoff",
						dayoff_id: id,
					}),
				});
				const result = await resp.json();
				if (!resp.ok || !result.success) {
					throw new Error(
						translateApiError(result.error, "Erro ao remover férias")
					);
				}
			}
		};
	}
	if (typeof window.shiftplannerDeleteLeavesForConflict !== "function") {
		window.shiftplannerDeleteLeavesForConflict = async function (leaveIds) {
			const apiUrl = window.SHIFT_API_URL;
			for (const id of leaveIds || []) {
				const resp = await fetch(apiUrl, {
					method: "POST",
					headers: { "Content-Type": "application/x-www-form-urlencoded" },
					body: new URLSearchParams({
						action: "delete_leave",
						leave_id: id,
					}),
				});
				const result = await resp.json();
				if (!resp.ok || !result.success) {
					throw new Error(
						translateApiError(result.error, "Erro ao remover ausência")
					);
				}
			}
		};
	}
	if (typeof window.resolveConflictRecords !== "function") {
		window.resolveConflictRecords = async function ({
			shiftIds,
			dayoffId,
			partialLeaveIds,
		}) {
			if (shiftIds && shiftIds.length) {
				await window.shiftplannerDeleteShiftsForConflict(shiftIds);
			}
			if (dayoffId) {
				await window.shiftplannerDeleteDayOffsForConflict([dayoffId]);
			}
			if (partialLeaveIds && partialLeaveIds.length) {
				await window.shiftplannerDeleteLeavesForConflict(partialLeaveIds);
			}
		};
	}

	if (isBars) {
		("use strict");

		const apiUrl = window.SHIFT_API_URL;
		const canWrite = Boolean(window.SHIFT_CAN_WRITE);
		const EMP_DRAG_TYPE = "text/x-employee-id";
		const SHIFT_DRAG_TYPE = "text/x-shift";
		const WEEK_STORAGE_KEY = "dolizsynch_shift_week_start";
		const EMPLOYEE_FORCE_STORAGE_KEY = "dolizsynch_week_employee_override";
		const initWeekIso = (window.SHIFT_INIT_WEEK || "").trim();
		const LANE_WIDTH_PX = 24;
		const LANE_OFFSET_PX = 2;
		const SUMMARY_STACK_WIDTH = 24;
		const SUMMARY_STACK_GAP = 24;
		const SUMMARY_STACK_OFFSET = SUMMARY_STACK_WIDTH + SUMMARY_STACK_GAP;
		const SHIFT_BAR_LABEL_THRESHOLDS = {
			initials: 32, // px needed to show initials (~45 min)
			initialsAndHours: 68, // px needed to show initials + total hours (~100 min)
			fullName: 120, // px needed to show full name + hours (~3 h)
		};

			let currentWeekStart = getMonday(new Date());
			const explicitWeekStart = parseIsoWeek(initWeekIso);
			const storedWeekStart = loadStoredWeekStart();
			if (explicitWeekStart) {
				currentWeekStart = explicitWeekStart;
		} else if (storedWeekStart) {
			currentWeekStart = storedWeekStart;
		}
			let employees = [];
			let employeeMap = {};
			let tasks = [];
			let taskMap = {};
			let taskOrderRank = {};
			let shifts = [];
			let shiftMap = new Map();
			let weeklyMinutesByEmployee = {};
			let hourlyTaskSummary = {};
			let summaryTaskIds = [];
			let summaryTaskLabels = [];
			let hourRowHeightPx = 40;

			const getTaskRank = (taskId) => {
				if (taskId === undefined || taskId === null) return Number.MAX_SAFE_INTEGER;
				const key = normalizeTaskKey(taskId);
				if (key === null || key === "") return Number.MAX_SAFE_INTEGER;
				const num = Number(key);
				if (Number.isFinite(num)) return num;
				return Object.prototype.hasOwnProperty.call(taskOrderRank, key)
					? taskOrderRank[key]
					: Number.MAX_SAFE_INTEGER;
			};

		function populateTaskSelect(selectEl, selectedTaskId, fallbackEmployeeId) {
			if (!selectEl) return;
			let options = '<option value="">Sem tarefa</option>';
			tasks.forEach((task) => {
				const selected =
					selectedTaskId && String(task.id) === String(selectedTaskId)
						? " selected"
						: "";
				options += `<option value="${task.id}"${selected}>${escapeHtml(
					task.label
				)}</option>`;
			});
			selectEl.innerHTML = options;
			let effectiveTaskId = selectedTaskId;
			if (!effectiveTaskId && fallbackEmployeeId) {
				const emp = employeeMap[fallbackEmployeeId];
				if (emp) {
					const raw =
						emp.mainTaskId !== undefined && emp.mainTaskId !== null
							? emp.mainTaskId
							: emp.main_task_id;
					if (raw !== undefined && raw !== null) {
						const normalized = String(raw).trim();
						if (normalized && normalized !== "0") {
							effectiveTaskId = normalized;
						}
					}
				}
			}
			if (effectiveTaskId) {
				selectEl.value = String(effectiveTaskId);
			}
		}

		// Resize state
		let isResizing = false;
		let resizingBar = null;
		let resizeHandle = null; // 'start' or 'end'
		let resizeStartY = 0;
		let resizeOriginalTop = 0;
		let resizeOriginalHeight = 0;
		let suppressNextBarClick = false;
		let draggedShiftId = null;
		let isCopyingWeek = false;

		// Initialize
		document
			.getElementById("btn-prev-week")
			.addEventListener("click", () => changeWeek(-1));
		document
			.getElementById("btn-next-week")
			.addEventListener("click", () => changeWeek(1));
		const btnToday = document.getElementById("btn-today");
		if (btnToday) {
			btnToday.addEventListener("click", () => goToToday());
		}
		const weekPicker = document.getElementById("week-picker");
		const weekPickerTrigger = document.getElementById("week-picker-trigger");
		let weekPickerWidget = null;
		const weekRangeLabel = document.getElementById("week-range");
		if (weekNotesSaveBtn && weekNotesTextarea) {
			weekNotesSaveBtn.addEventListener("click", (e) => {
				e.preventDefault();
				const weekIso = formatDateISO(currentWeekStart);
				weekNotesSaveBtn.disabled = true;
				saveWeekNotesToServer(weekIso, weekNotesTextarea.value).finally(() => {
					weekNotesSaveBtn.disabled = false;
				});
			});
		}
		if (weekPicker) {
			weekPickerWidget = createWeekPicker(weekPicker, {
				language: window.SHIFT_WEEK_PICKER_LANG,
				thisWeekLabel: window.SHIFT_WEEK_PICKER_THIS_WEEK || "This week",
				onSelect: (selectedDate) => {
					currentWeekStart = getMonday(selectedDate);
					syncWeekState();
					loadData();
				},
			});
			if (!weekPickerWidget) {
				weekPicker.addEventListener("change", handleWeekPickerChange);
			}
			weekPicker.classList.add("with-range-text");
			if (weekPickerTrigger) {
				weekPickerTrigger.addEventListener("click", (e) => {
					e.preventDefault();
					if (weekPickerWidget) {
						window.jQuery(weekPicker).datepicker("show");
					} else if (typeof weekPicker.showPicker === "function") {
						weekPicker.showPicker();
					} else {
						weekPicker.focus();
					}
				});
			}
		}
		const weekCopyBtn = document.getElementById("btn-copy-week");
		const weekPrintBtn = document.getElementById("btn-print-week");
		const weekCopyModal = document.getElementById("week-copy-modal");
		const weekCopySourceInput = document.getElementById("week-copy-source");
		const weekCopyTargetInput = document.getElementById("week-copy-target");
		const weekCopySourceTrigger = document.getElementById(
			"week-copy-source-trigger"
		);
		const weekCopyTargetTrigger = document.getElementById(
			"week-copy-target-trigger"
		);
		const weekCopyConfirmBtn = document.getElementById("week-copy-confirm");
		const weekCopyCancelBtn = document.getElementById("week-copy-cancel");
		const weekCopyBackdrop = document.querySelector("[data-week-copy-close]");
		const weekCopyModeInputs = Array.from(
			document.querySelectorAll('input[name="week-copy-mode"]')
		);
		const weekCopyReplaceWarning = document.getElementById(
			"week-copy-replace-warning"
		);
		const replaceModeWarningText =
			"Replace mode deletes existing shifts/dayoffs in target week before copying.";
		const getSelectedWeekCopyMode = () => {
			if (!weekCopyModeInputs.length) return "merge";
			const selected = weekCopyModeInputs.find((input) => input && input.checked);
			const value =
				selected && selected.value
					? String(selected.value).toLowerCase()
					: "merge";
			return value === "replace" ? "replace" : "merge";
		};
		const updateWeekCopyModeUI = () => {
			if (!weekCopyReplaceWarning) return;
			weekCopyReplaceWarning.style.display =
				getSelectedWeekCopyMode() === "replace" ? "block" : "none";
		};
		weekCopyModeInputs.forEach((input) => {
			input.addEventListener("change", updateWeekCopyModeUI);
		});
		let weekCopySourceWidget = null;
		let weekCopyTargetWidget = null;
		const printUrl =
			window.SCHEDULE_PRINT_URL || "/custom/kreaflow/schedule/schedule_print.php";
		const switchLink = document.querySelector(".btn-switch");
		if (switchLink && !switchLink.dataset.baseHref) {
			switchLink.dataset.baseHref = switchLink.href;
		}
		if (
			GLOBAL_QUOTA_SAVE_BTN &&
			!GLOBAL_CAN_EDIT_EMPLOYEE_HOURS &&
			!GLOBAL_CAN_ASSIGN_MAIN_TASK
		) {
			GLOBAL_QUOTA_SAVE_BTN.style.display = "none";
		}

		const handleConflictCellClick = (cell) => {
			if (!canWrite) return;
			const empId = cell.dataset.emp;
			const dateISO = cell.dataset.date;
			const conflictType = cell.dataset.conflictType || "";
			const entryType = cell.dataset.entryType || "";
			let shiftIds = (cell.dataset.conflictShifts || "")
				.split(",")
				.map((id) => id.trim())
				.filter(Boolean);
			let dayoffId = cell.dataset.conflictDayoffId || "";
			const partialLeaveIds = (cell.dataset.conflictPartialLeaves || "")
				.split(",")
				.map((id) => id.trim())
				.filter(Boolean);
			let resolveMode = "shifts";

			if (conflictType === "dayoff") {
				const isDayoffCell = entryType === "dayoff" || entryType === "lockday";
				if (!shiftIds.length) {
					// No shifts to remove; default to removing the day-off itself.
					resolveMode = "dayoff";
				} else if (isDayoffCell) {
					// Keep day-off, remove shifts.
					dayoffId = "";
				} else {
					// Shift cell with day-off conflict: remove day-off, keep shifts.
					resolveMode = "dayoff";
					shiftIds = [];
				}
			}

			// Check if there's anything to remove (shifts, day-off, or partial leaves)
			if (
				!empId ||
				!dateISO ||
				(!shiftIds.length && !dayoffId && !partialLeaveIds.length)
			) {
				alert(
					tLabel(
						"conflictNoRemovableData",
						"Não existem turnos para remover neste conflito."
					)
				);
				return;
			}
			openConflictResolutionDialog({
				empId,
				dateISO,
				shiftIds,
				dayoffId,
				partialLeaveIds,
				resolveMode,
			});
		};

		const legendManageBtn = document.getElementById("legend-manage-tasks");
		if (legendManageBtn && !legendManageBtn.disabled) {
			legendManageBtn.addEventListener("click", () => {
				openTaskManager(() => {
					renderLegend();
				});
			});
		}

		if (weekCopySourceInput) {
			const handleSourceSelect = (date) => {
				const monday = getMonday(date);
				if (monday) {
					setWeekInputDisplay(weekCopySourceInput, monday);
				}
			};
			weekCopySourceWidget = createWeekPicker(weekCopySourceInput, {
				language: window.SHIFT_WEEK_PICKER_LANG,
				thisWeekLabel: window.SHIFT_WEEK_PICKER_THIS_WEEK || "This week",
				onSelect: handleSourceSelect,
			});
			weekCopySourceInput.addEventListener("change", () => {
				const parsed = getWeekInputDateValue(weekCopySourceInput);
				if (parsed) {
					setWeekInputDisplay(weekCopySourceInput, parsed);
				}
			});
			const openSourcePicker = (e) => {
				e.preventDefault();
				if (weekCopySourceWidget && weekCopySourceWidget.show) {
					weekCopySourceWidget.show();
				} else if (
					weekCopySourceInput.showPicker &&
					typeof weekCopySourceInput.showPicker === "function"
				) {
					weekCopySourceInput.showPicker();
				} else {
					weekCopySourceInput.focus();
				}
			};
			if (weekCopySourceTrigger) {
				weekCopySourceTrigger.addEventListener("click", openSourcePicker);
			}
		}

		if (weekCopyTargetInput) {
			const handleTargetSelect = (date) => {
				const monday = getMonday(date);
				if (monday) {
					setWeekInputDisplay(weekCopyTargetInput, monday);
				}
			};
			weekCopyTargetWidget = createWeekPicker(weekCopyTargetInput, {
				language: window.SHIFT_WEEK_PICKER_LANG,
				thisWeekLabel: window.SHIFT_WEEK_PICKER_THIS_WEEK || "This week",
				onSelect: handleTargetSelect,
			});
			weekCopyTargetInput.addEventListener("change", () => {
				const parsed = getWeekInputDateValue(weekCopyTargetInput);
				if (parsed) {
					setWeekInputDisplay(weekCopyTargetInput, parsed);
				}
			});
			const openTargetPicker = (e) => {
				e.preventDefault();
				if (weekCopyTargetWidget && weekCopyTargetWidget.show) {
					weekCopyTargetWidget.show();
				} else if (
					weekCopyTargetInput.showPicker &&
					typeof weekCopyTargetInput.showPicker === "function"
				) {
					weekCopyTargetInput.showPicker();
				} else {
					weekCopyTargetInput.focus();
				}
			};
			if (weekCopyTargetTrigger) {
				weekCopyTargetTrigger.addEventListener("click", openTargetPicker);
			}
		}
		if (weekPrintBtn) {
			weekPrintBtn.addEventListener("click", () => {
				openPrintWindow("bars");
			});
		}

		// Click on cells to create new shifts
		document.addEventListener("click", handleCellClick);
		document.addEventListener("click", handleLeavePillClick);
		document.addEventListener("dragover", handleGridDragOver);
		document.addEventListener("drop", handleGridDrop);
		document.addEventListener("mousemove", handleMouseMove);
		document.addEventListener("mouseup", handleMouseUp);

		syncWeekState();
		loadData();

		function getDayName(dayIndex) {
			const days = ["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb"];
			return days[dayIndex];
		}

		function getInitials(name) {
			const parts = name.trim().split(/\s+/);
			if (parts.length >= 2) {
				return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
			}
			return name.substring(0, 2).toUpperCase();
		}

		function getShortName(name) {
			const parts = name.trim().split(/\s+/).filter(Boolean);
			if (parts.length === 0) return "";
			if (parts.length === 1) return parts[0];
			return `${parts[0]} ${parts[parts.length - 1]}`;
		}

		function changeWeek(direction) {
			currentWeekStart.setDate(currentWeekStart.getDate() + direction * 7);
			syncWeekState();
			loadData();
		}

		function goToToday() {
			currentWeekStart = getMonday(new Date());
			syncWeekState();
			loadData();
		}

		function updateWeekDisplay() {
			const endDate = new Date(currentWeekStart);
			endDate.setDate(endDate.getDate() + 6);

			const rangeText = formatDetailedWeekRange(currentWeekStart);
			if (weekRangeLabel) {
				weekRangeLabel.textContent = rangeText;
			}
			if (weekPicker) {
				weekPicker.setAttribute("title", rangeText);
				weekPicker.setAttribute(
					"aria-label",
					`Selecionar semana (${rangeText})`
				);
			}
			updateWeekPicker();
			updateWeekNotesUI();
		}

		async function loadData() {
			try {
				updateWeekDisplay();
				updateSwitchLink();

				await fetchTasks();
				const weekIso = formatDateISO(currentWeekStart);
				const overrides = await fetchWeekEmployeeOverrides(weekIso);
				const forcedIds = overrides.forced;
				const hiddenIds = overrides.hidden;

				// Fetch employees
				const resourceStart = formatDateISO(currentWeekStart);
				const resourceEndDate = new Date(currentWeekStart);
				resourceEndDate.setDate(resourceEndDate.getDate() + 6);
				const resourceEnd = formatDateISO(resourceEndDate);
				const resourceParams = new URLSearchParams({
					action: "resources",
					start: resourceStart,
					end: resourceEnd,
				});
				if (forcedIds.length) {
					resourceParams.set("force_user_ids", forcedIds.join(","));
				}
				if (hiddenIds.length) {
					resourceParams.set("hide_user_ids", hiddenIds.join(","));
				}
				const employeesResp = await fetch(apiUrl + "?" + resourceParams);
				const employeesPayload = await employeesResp.json();
				employees = Array.isArray(employeesPayload) ? employeesPayload : [];
				if (!Array.isArray(employeesPayload)) {
					console.warn(
						"Employee payload is not an array; defaulting to empty list",
						employeesPayload
					);
				}
				const hiddenSet = new Set(hiddenIds.map(String));
				if (hiddenSet.size) {
					employees = employees.filter((emp) => {
						const empId = emp ? emp.id ?? emp.rowid ?? emp.fk_user : null;
						const empKey = normalizeEmployeeKey(empId);
						if (empKey === null) return true;
						return !hiddenSet.has(empKey);
					});
				}

				employeeMap = {};
				employees.forEach((emp) => {
					const normalizedExpected = parseExpectedHours(emp.expectedHours);
					if (normalizedExpected !== null) {
						emp.expectedHours = normalizedExpected;
					} else {
						delete emp.expectedHours;
					}
					if (emp.mainTaskId === undefined && emp.main_task_id !== undefined) {
						emp.mainTaskId = normalizeMainTaskId(emp.main_task_id);
					} else {
						emp.mainTaskId = normalizeMainTaskId(emp.mainTaskId);
					}
					const employmentIso = emp.dateEmployment || emp.employmentDate || "";
					if (employmentIso) {
						emp.employmentDate = String(employmentIso).slice(0, 10);
					} else {
						delete emp.employmentDate;
					}
					const employmentEndIso =
						emp.dateEmploymentEnd || emp.employmentEndDate || "";
					if (employmentEndIso) {
						emp.employmentEndDate = String(employmentEndIso).slice(0, 10);
					} else {
						delete emp.employmentEndDate;
					}
					const empKey = normalizeEmployeeKey(emp.id);
					if (empKey !== null) {
						employeeMap[empKey] = emp;
						const numericKey = Number(empKey);
						if (!Number.isNaN(numericKey)) {
							employeeMap[numericKey] = emp;
						}
					}
				});

				// Fetch shifts for the week
				const weekEnd = new Date(currentWeekStart);
				weekEnd.setDate(weekEnd.getDate() + 7);
				const rangeStartIso = weekIso;
				const rangeEndIso = formatDateISO(weekEnd);

				let serverHourlySummary = {};
				try {
					const summaryResp = await fetch(
						apiUrl +
							"?" +
							new URLSearchParams({
								action: "hourly_summary",
								start: rangeStartIso,
								end: rangeEndIso,
							})
					);
					if (summaryResp.ok) {
						const summaryPayload = await summaryResp.json();
						if (
							summaryPayload &&
							summaryPayload.summary &&
							typeof summaryPayload.summary === "object"
						) {
							serverHourlySummary = summaryPayload.summary;
						}
					} else {
						console.warn(
							"Failed to load hourly summary:",
							summaryResp.status,
							summaryResp.statusText
						);
					}
				} catch (summaryError) {
					console.warn("Hourly summary fetch error:", summaryError);
				}

				await shiftplannerRefreshPublicHolidays(
					apiUrl,
					rangeStartIso,
					rangeEndIso
				);

				const listParams = new URLSearchParams({
					action: "list",
					start: rangeStartIso,
					end: rangeEndIso,
				});
				if (INCLUDE_FOREIGN_SHIFTS) {
					listParams.set("include_foreign", "1");
				}
				if (forcedIds.length) {
					listParams.set("force_user_ids", forcedIds.join(","));
				}
				if (hiddenIds.length) {
					listParams.set("hide_user_ids", hiddenIds.join(","));
				}
				const shiftsResp = await fetch(apiUrl + "?" + listParams);
				const shiftPayload = await shiftsResp.json();
				let normalizedShifts = normalizeShiftArray(shiftPayload);
				if (!Array.isArray(normalizedShifts)) {
					console.warn(
						"Shift payload not an array, coercing to empty list:",
						shiftPayload
					);
					normalizedShifts = [];
				}
				shifts = normalizedShifts
					.filter(isShiftRecord)
					.filter((shift) => shiftBelongsToCurrentEntity(shift));
				shiftMap = new Map();
			shifts = shifts.filter((shift) => {
				const empId = shift.resourceId || shift.fk_user;
				return empId && employeeMap[empId];
			});
			shifts.forEach((shift) => {
				const stypeVal =
					shift.shift_type !== undefined && shift.shift_type !== null
						? Number(shift.shift_type)
						: shift.shiftType !== undefined && shift.shiftType !== null
						? Number(shift.shiftType)
						: 1;
				shift.shift_type = Number.isFinite(stypeVal) ? stypeVal : 1;
				shift.shiftType = shift.shift_type;
				const taskId = getShiftTaskKey(shift);
				const taskInfo = taskId && taskMap[taskId] ? taskMap[taskId] : null;
				const resolvedColor =
					(taskInfo && taskInfo.color) ||
					shift.color ||
					shift.taskColor ||
					null;
				if (resolvedColor) {
					shift.taskColor = resolvedColor;
				}
				const resolvedTextColor =
					(taskInfo && taskInfo.textColor) ||
					shift.textColor ||
					shift.color_text ||
					shift.taskTextColor ||
					null;
				if (resolvedTextColor) {
					shift.taskTextColor = resolvedTextColor;
				}
				shiftMap.set(String(shift.id), shift);
			});
				recalculateWeeklyMinutes();
				if (
					serverHourlySummary &&
					Object.keys(serverHourlySummary).length > 0
				) {
					hourlyTaskSummary = serverHourlySummary;
				} else {
					hourlyTaskSummary = buildHourlyTaskSummary();
				}

				renderLegend();
				renderTable();
			} catch (error) {
				console.error("Failed to load data:", error);
				alert("Erro ao carregar dados");
			}
		}

		function persistWeekStart(date) {
			try {
				localStorage.setItem(WEEK_STORAGE_KEY, formatDateISO(date));
			} catch (err) {
				// ignore storage failures
			}
		}

		function loadStoredWeekStart() {
			try {
				const raw = localStorage.getItem(WEEK_STORAGE_KEY);
				if (!raw) return null;
				const parsed = new Date(`${raw}T00:00:00`);
				if (Number.isNaN(parsed.getTime())) return null;
				return getMonday(parsed);
			} catch (err) {
				return null;
			}
		}

		function normalizeEmployeeIdList(ids) {
			if (ids === null || ids === undefined) return [];
			let values = [];
			if (Array.isArray(ids)) {
				values = ids;
			} else if (ids instanceof Set) {
				values = Array.from(ids);
			} else if (typeof ids === "string") {
				const raw = ids.trim();
				if (!raw) return [];
				values = raw.split(/[\s,]+/);
			} else if (typeof ids === "number") {
				values = [ids];
			} else if (typeof ids === "object") {
				values = Object.values(ids);
			}
			const seen = new Set();
			const result = [];
			values.forEach((id) => {
				const num = Number(id);
				if (!Number.isFinite(num) || num <= 0) return;
				const key = String(Math.trunc(num));
				if (seen.has(key)) return;
				seen.add(key);
				result.push(key);
			});
			return result;
		}

		async function fetchWeekEmployeeOverrides(weekIso) {
			if (!weekIso) {
				return { forced: [], hidden: [] };
			}
			try {
				const resp = await fetch(
					`${apiUrl}?action=week_employee_overrides_get&week_start=${encodeURIComponent(
						weekIso
					)}`
				);
				if (!resp.ok) {
					throw new Error(`HTTP ${resp.status}`);
				}
				const data = await resp.json();
				const forced = normalizeEmployeeIdList(
					data && data.forced_user_ids ? data.forced_user_ids : []
				);
				const hidden = normalizeEmployeeIdList(
					data && data.hidden_user_ids ? data.hidden_user_ids : []
				);
				return { forced, hidden };
			} catch (err) {
				return { forced: [], hidden: [] };
			}
		}

		async function saveWeekEmployeeOverrides(weekIso, forcedIds, hiddenIds) {
			if (!weekIso) return false;
			const forced = normalizeEmployeeIdList(forcedIds);
			const hidden = normalizeEmployeeIdList(hiddenIds);
			try {
				const resp = await fetch(`${apiUrl}?action=week_employee_overrides_save`, {
					method: "POST",
					headers: {
						"Content-Type": "application/json",
					},
					credentials: "same-origin",
					body: JSON.stringify({
						week_start: weekIso,
						forced_user_ids: forced,
						hidden_user_ids: hidden,
					}),
				});
				const data = await resp.json().catch(() => null);
				if (!resp.ok || !data || !data.success) {
					throw new Error((data && data.error) || `HTTP ${resp.status}`);
				}
				return true;
			} catch (err) {
				return false;
			}
		}

		function parseIsoWeek(value) {
			if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
			const parsed = new Date(`${value}T00:00:00`);
			if (Number.isNaN(parsed.getTime())) return null;
			return getMonday(parsed);
		}

		function updateSwitchLink() {
			if (!switchLink) return;
			const base = switchLink.dataset.baseHref || switchLink.href;
			try {
				const url = new URL(base, window.location.origin);
				url.searchParams.set("week", formatDateISO(currentWeekStart));
				switchLink.href = url.pathname + url.search + url.hash;
			} catch (err) {
				switchLink.href = `${base.split("?")[0]}?week=${formatDateISO(
					currentWeekStart
				)}`;
			}
		}

		function syncWeekState() {
			persistWeekStart(currentWeekStart);
			updateSwitchLink();
			updateWeekPicker();
		}

		function renderLegend() {
			const tasksContainer = document.getElementById("legend-tasks");
			const employeesContainer = document.getElementById("legend-employees");
			if (!tasksContainer) return;

			const taskItems =
				tasks
					.map((task) => {
						const color = task.color || "#3788d8";
						return `<span class="legend-task"><span class="legend-task-color" style="background:${color}"></span>${escapeHtml(
							task.label
						)}</span>`;
					})
					.join(" ") || "Sem tarefas";

			const taskSection = `<span class="legend-group legend-group-tasks">${taskItems}</span>`;

			const sortedLegendEmployees = employees
				.slice()
				.sort(compareEmployeeTitles);
			const employeeItems = sortedLegendEmployees
				.map((emp) => {
					const initials = getInitials(emp.title);
					const shortName = escapeHtml(getShortName(emp.title));
					const dragValue = canWrite ? "true" : "false";
					return `
				<span class="employee-pill" draggable="${dragValue}" data-emp-id="${emp.id}">
					<span class="employee-initials">${initials}</span>
    					<span class="employee-name">${shortName}</span>
    				</span>
    			`;
				})
				.join("");

			tasksContainer.innerHTML = taskSection;
			if (employeesContainer) {
				employeesContainer.innerHTML = employeeItems || "Sem funcionários";
				attachEmployeeDragHandlers();
			}
		}

		function attachEmployeeDragHandlers() {
			document.querySelectorAll(".employee-pill").forEach((pill) => {
				pill.addEventListener("dragstart", handleEmployeeDragStart);
			});
		}

		function renderTable() {
			const header = document.getElementById("table-header");
			const body = document.getElementById("table-body");

			const laneAssignments = buildLaneAssignments();
			const laneOccupancyByDate = laneAssignments.perDate;
			const defaultTrackWidth = Math.max(
				60,
				laneAssignments.maxLaneCount * LANE_WIDTH_PX + LANE_OFFSET_PX * 2
			);
			// Use a uniform width for all day columns (except the first).
			const getLaneTrackWidth = () => defaultTrackWidth;
			const getDayColumnWidth = () => defaultTrackWidth + SUMMARY_STACK_OFFSET;

			// Build date header
			let headerHTML = '<tr><th class="time-col">Hora</th>';

			const weekDates = [];
			for (let i = 0; i < 7; i++) {
				const date = new Date(currentWeekStart);
				date.setDate(currentWeekStart.getDate() + i);
				const dateISO = formatDateISO(date);
				weekDates.push({ date, dateISO });

				const dayName = getDayName(date.getDay());
				const dateStr = formatDate(date);
				const holidayInfo = getHolidayInfoByDate(dateISO);
				const holidayTitleAttr =
					holidayInfo && holidayInfo.name
						? ` title="${escapeHtml(holidayInfo.name)}"`
						: "";
				const holidayBadge =
					holidayInfo && holidayInfo.name
						? `<span class="holiday-name">${escapeHtml(
								holidayInfo.name
						  )}</span>`
						: "";

				const dayWidth = getDayColumnWidth(dateISO);
				const dayWidthAttr = `style="width:${dayWidth}px;min-width:${dayWidth}px;"`;
				const headerClasses = ["day-header"];
				if (date.getDay() === 0 || date.getDay() === 6) {
					headerClasses.push("is-weekend");
				}
				if (holidayInfo) {
					headerClasses.push("is-holiday");
				}
				let dayInner = `${dayName}<br>${dateStr}${holidayBadge}`;
				const dayViewBase =
					window.SHIFT_DAY_VIEW_BASE ||
					(window.DOL_MAIN_URL_ROOT
						? `${window.DOL_MAIN_URL_ROOT}/custom/kreaflow/schedule/schedule_bars_horizontal.php`
						: "/custom/kreaflow/schedule/schedule_bars_horizontal.php");
				if (dayViewBase) {
					dayInner = `<a class="day-link" href="${dayViewBase}?day=${encodeURIComponent(
						dateISO
					)}">${dayInner}</a>`;
				}
				headerHTML += `<th class="${headerClasses.join(
					" "
				)}" data-day="${i}" data-date="${dateISO}"${holidayTitleAttr} ${dayWidthAttr}>${dayInner}</th>`;
			}
			headerHTML += "</tr>";
			header.innerHTML = headerHTML;

			// Build sub-header for employee lanes
			const subHeaderCells = [];
			subHeaderCells.push(
				'<tr class="employee-subheader"><th style="color:#000;font-weight:600;">Funcionários</th>'
			);
			for (let i = 0; i < weekDates.length; i++) {
				const { dateISO } = weekDates[i];
				const assignment = laneOccupancyByDate[dateISO];
				const laneLabels = assignment
					? assignment.order
							.map((entry) => {
								const left = entry.lane * LANE_WIDTH_PX + LANE_OFFSET_PX;
								const style = `left:${left}px;background:${entry.color};color:${
									entry.textColor || DEFAULT_TASK_TEXT_COLOR
								};opacity:0.55;`;
								return `<span class="employee-subheader-pill" style="${style}">${entry.initials}</span>`;
							})
							.join("")
					: "";
				const dayWidth = getDayColumnWidth(dateISO);
				const trackWidth = getLaneTrackWidth(dateISO);
				subHeaderCells.push(
					`<th style="width:${dayWidth}px;min-width:${dayWidth}px;padding-left:${SUMMARY_STACK_OFFSET}px;"><div class="employee-subheader-track" style="width:${trackWidth}px">${laneLabels}</div></th>`
				);
			}
			subHeaderCells.push("</tr>");
			header.insertAdjacentHTML("beforeend", subHeaderCells.join(""));

			const totalsRowCells = [];
			totalsRowCells.push(
				'<tr class="employee-subheader employee-subheader-hours"><th style="color:#000;font-weight:600;">Horas</th>'
			);
			for (let i = 0; i < weekDates.length; i++) {
				const { dateISO } = weekDates[i];
				const assignment = laneOccupancyByDate[dateISO];
				const laneTotals = assignment
					? assignment.order
							.map((entry) => {
								const left = entry.lane * LANE_WIDTH_PX + LANE_OFFSET_PX;
								const hoursLabel = formatHoursLabelNoSuffix(
									entry.totalMinutes / 60
								);
								const style = `left:${left}px;background:${entry.color};color:${
									entry.textColor || DEFAULT_TASK_TEXT_COLOR
								};opacity:0.55;`;
								return `<span class="employee-subheader-hours-pill" style="${style}">${hoursLabel}</span>`;
							})
							.join("")
					: "";
				const dayWidth = getDayColumnWidth(dateISO);
				const trackWidth = getLaneTrackWidth(dateISO);
				totalsRowCells.push(
					`<th style="width:${dayWidth}px;min-width:${dayWidth}px;padding-left:${SUMMARY_STACK_OFFSET}px;"><div class="employee-subheader-track employee-subheader-hours-track" style="width:${trackWidth}px">${laneTotals}</div></th>`
				);
			}
			totalsRowCells.push("</tr>");
			header.insertAdjacentHTML("beforeend", totalsRowCells.join(""));

			// Build body - one row per hour
			let bodyHTML = "";
			const hourSequence = buildHourSequence();
			hourSequence.forEach((hour, rowIndex) => {
				const timeStr = `${String(hour).padStart(2, "0")}:00`;
				bodyHTML += `<tr data-hour="${hour}" data-row-index="${rowIndex}"><th>${timeStr}</th>`;
				for (let dayOffset = 0; dayOffset < weekDates.length; dayOffset++) {
					const dateISO = weekDates[dayOffset].dateISO;
					const holidayInfo = getHolidayInfoByDate(dateISO);
					const classAttr = holidayInfo ? ' class="holiday-cell"' : "";
					const holidayAttr =
						holidayInfo && holidayInfo.name
							? ` data-holiday-name="${escapeHtml(holidayInfo.name)}"`
							: "";
					const dayWidth = getDayColumnWidth(dateISO);
					const dayWidthAttr = `style="width:${dayWidth}px;min-width:${dayWidth}px;"`;
					const summaryStack = buildHourlySummaryStack(dateISO, rowIndex, hour);
					bodyHTML += `<td${classAttr} ${dayWidthAttr} data-date="${dateISO}" data-hour="${hour}"${holidayAttr}>${summaryStack}</td>`;
				}
				bodyHTML += "</tr>";
			});

			body.innerHTML = bodyHTML;
			updateHourRowHeight();

			// Now render vertical shift bars
			renderShiftBars(laneOccupancyByDate);
		}

		function updateHourRowHeight() {
			const firstDataCell = document.querySelector(
				"#table-body tr:first-child td[data-date]"
			);
			if (
				firstDataCell &&
				typeof firstDataCell.getBoundingClientRect === "function"
			) {
				const rect = firstDataCell.getBoundingClientRect();
				if (rect && rect.height) {
					hourRowHeightPx = rect.height;
				}
			}
		}

		function buildHourSequence() {
			const sequence = [];
			const totalRows = Math.max(1, Math.ceil(SHIFT_DAY_DURATION_MINUTES / 60));
			for (let i = 0; i < totalRows; i++) {
				sequence.push((SHIFT_DAY_START_HOUR + i) % 24);
			}
			return sequence;
		}

		function buildLaneAssignments() {
			const perDate = {};
			let maxLaneCount = 0;
			const dateEmpData = {};

			shifts.forEach((shift) => {
				const dateISO = shiftplannerGetDisplayDate(shift.start);
				if (!dateISO) return;
				const empId = getShiftEmployeeKey(shift);
				if (!empId || !employeeMap[empId]) return;
				const taskId = getShiftTaskKey(shift);
				const startMinutesRaw = parseTime(shift.start);
				const endMinutesRaw = parseTime(shift.end);
				if (startMinutesRaw === null || endMinutesRaw === null) return;
				const normalizedStart = shiftplannerNormalizeMinutes(startMinutesRaw);
				let normalizedEnd = shiftplannerNormalizeMinutes(endMinutesRaw);
				if (normalizedStart === null || normalizedEnd === null) {
					return;
				}
				if (normalizedEnd <= normalizedStart) {
					normalizedEnd += MINUTES_PER_DAY;
				}
				const durationMinutes = normalizedEnd - normalizedStart;
				if (durationMinutes <= 0) return;
				if (!dateEmpData[dateISO]) {
					dateEmpData[dateISO] = {};
				}
				if (!dateEmpData[dateISO][empId]) {
					dateEmpData[dateISO][empId] = {
						taskId: null,
						color: null,
						textColor: null,
						totalMinutes: 0,
					};
				}
				const entry = dateEmpData[dateISO][empId];
				if (!entry.taskId && taskId) {
					entry.taskId = taskId;
				}
				if (!entry.color) {
					entry.color =
						(taskId && taskMap[taskId] && taskMap[taskId].color) ||
						shift.color ||
						shift.taskColor ||
						"#607d8b";
				}
				if (!entry.textColor) {
					entry.textColor =
						(taskId && taskMap[taskId] && taskMap[taskId].textColor) ||
						shift.taskTextColor ||
						DEFAULT_TASK_TEXT_COLOR;
				}
				entry.totalMinutes += durationMinutes;
			});

			Object.keys(dateEmpData).forEach((dateISO) => {
				const entries = Object.keys(dateEmpData[dateISO]).map((empId) => {
					const emp = employeeMap[empId];
					const { first: firstName, last: lastName } = getSortableNames(
						emp ? emp.title : ""
					);
					const taskId = dateEmpData[dateISO][empId].taskId;
					const taskLabel =
						taskId && taskMap[taskId] ? taskMap[taskId].label : "";
					return {
						empId,
						initials: emp ? getInitials(emp.title) : "",
						color: dateEmpData[dateISO][empId].color || "#607d8b",
						textColor:
							dateEmpData[dateISO][empId].textColor || DEFAULT_TASK_TEXT_COLOR,
						totalMinutes: dateEmpData[dateISO][empId].totalMinutes || 0,
						taskSort: (taskLabel || "zzzz").toLowerCase(),
						firstName,
						lastName,
					};
				});

				entries.sort(
					(a, b) =>
						a.taskSort.localeCompare(b.taskSort) ||
						a.firstName.localeCompare(b.firstName) ||
						a.lastName.localeCompare(b.lastName)
				);

				const laneByEmp = {};
				entries.forEach((entry, idx) => {
					entry.lane = idx;
					laneByEmp[entry.empId] = idx;
				});

				perDate[dateISO] = { order: entries, laneByEmp };
				if (entries.length > maxLaneCount) {
					maxLaneCount = entries.length;
				}
			});

			return { perDate, maxLaneCount };
		}

		function buildHourlyTaskSummary() {
			const summary = {};
			const hours = buildHourSequence();
			const binCount = hours.length;
			if (!binCount) {
				return summary;
			}
			const dayStart = SHIFT_DAY_START_MINUTES;
			const dayEndExclusive = dayStart + binCount * 60;

			shifts.forEach((shift) => {
				const dateISO = shiftplannerGetDisplayDate(shift.start);
				if (!dateISO) return;
				const rawTaskId = getShiftTaskKey(shift);
				const taskId = rawTaskId || "__UNASSIGNED__";
				const empId = getShiftEmployeeKey(shift);
				// Only count shifts with valid employee assignments
				if (!empId || !employeeMap[empId]) return;
				const startMinutesRaw = parseTime(shift.start);
				const endMinutesRaw = parseTime(shift.end);
				if (startMinutesRaw === null || endMinutesRaw === null) return;
				let normalizedStart = shiftplannerNormalizeMinutes(startMinutesRaw);
				let normalizedEnd = shiftplannerNormalizeMinutes(endMinutesRaw);
				if (normalizedStart === null || normalizedEnd === null) return;
				if (normalizedEnd <= normalizedStart) {
					normalizedEnd += MINUTES_PER_DAY;
				}
				const clampedStart = Math.max(normalizedStart, dayStart);
				const clampedEnd = Math.min(normalizedEnd, dayEndExclusive);
				if (clampedEnd <= clampedStart) return;
				const startBin = Math.max(
					0,
					Math.floor((clampedStart - dayStart) / 60)
				);
				const endBin = Math.min(
					binCount,
					Math.ceil((clampedEnd - dayStart) / 60)
				);
				if (!summary[dateISO]) {
					summary[dateISO] = new Array(binCount);
				}
				for (let bin = startBin; bin < endBin; bin++) {
					const bucketStart = dayStart + bin * 60;
					const bucketEnd = bucketStart + 60;
					// Count employee in bucket only if shift actually covers the bucket hour
					// Shift must: start before bucket ends AND end after bucket starts
					if (normalizedStart >= bucketEnd || normalizedEnd <= bucketStart) {
						continue;
					}
					if (!summary[dateISO][bin]) {
						summary[dateISO][bin] = new Map();
					}
					const bucket = summary[dateISO][bin];
					if (!bucket.has(taskId)) {
						bucket.set(taskId, new Set());
					}
					bucket.get(taskId).add(empId);
				}
			});

			return summary;
		}

		function updateSummaryTaskMeta() {
			summaryTaskIds = [];
			summaryTaskLabels = [];
			if (!Array.isArray(tasks) || !tasks.length) {
				return;
			}
			tasks.forEach((task) => {
				if (!task || task.id === undefined || task.id === null) {
					return;
				}
				const taskKey = normalizeTaskKey(task.id);
				if (!taskKey) return;
				summaryTaskIds.push(taskKey);
				summaryTaskLabels.push(task.label || "");
			});
		}

		function getBucketCount(bucket, taskId) {
			if (!bucket) return 0;
			if (bucket instanceof Map) {
				const set = bucket.get(taskId);
				return set ? set.size : 0;
			}
			if (typeof bucket === "object") {
				const value = bucket[taskId];
				return typeof value === "number" ? value : 0;
			}
			return 0;
		}

		function getHourlySummaryParts(dateISO, hourIndex) {
			const bucket =
				hourlyTaskSummary[dateISO] && hourlyTaskSummary[dateISO][hourIndex]
					? hourlyTaskSummary[dateISO][hourIndex]
					: null;
			const taskOrder =
				summaryTaskIds.length > 0
					? summaryTaskIds
					: (tasks || [])
							.map((task) => normalizeTaskKey(task.id))
							.filter(Boolean);
			const parts = taskOrder.map((taskId) => {
				const count = getBucketCount(bucket, taskId);
				return {
					taskId,
					count,
					color: taskMap[taskId] ? taskMap[taskId].color : "#545454",
					label: taskMap[taskId] ? taskMap[taskId].label : "",
				};
			});
			return parts;
		}

		function buildHourlySummaryStack(dateISO, hourIndex, hourValue) {
			const parts = getHourlySummaryParts(dateISO, hourIndex);
			if (!parts.length || parts.every((part) => part.count === 0)) {
				return "";
			}
			const stack = parts
				.map((part, idx) => {
					return `<span class="summary-stack-segment" style="background:${
						part.color || "#545454"
					};opacity:0.75;" data-task-id="${escapeHtml(
						part.taskId || ""
					)}">${escapeHtml(String(part.count))}</span>`;
				})
				.join("");
			const stackHtml = `<div class="timegrid-summary-stack">${stack}</div>`;
			const badgeHtml = buildHourBadge(hourValue);
			return `<div class="timegrid-summary-wrapper">${stackHtml}${badgeHtml}</div>`;
		}

		function buildHourBadge(hourValue) {
			if (typeof hourValue !== "number" || Number.isNaN(hourValue)) {
				return "";
			}
			const label = String(hourValue).padStart(2, "0");
			return `<span class="summary-stack-segment summary-hour-badge">${escapeHtml(
				label
			)}</span>`;
		}

		function getSortableNames(name) {
			const parts = name.trim().toLowerCase().split(/\s+/).filter(Boolean);
			if (!parts.length) return { first: "", last: "" };
			return {
				first: parts[0],
				last: parts.length > 1 ? parts[parts.length - 1] : "",
			};
		}

		function hexToRgba(hex, alpha) {
			if (!hex) {
				return `rgba(96, 125, 139, ${alpha})`;
			}
			let value = hex.replace("#", "");
			if (value.length === 3) {
				value = value
					.split("")
					.map((ch) => ch + ch)
					.join("");
			}
			const r = parseInt(value.substring(0, 2), 16);
			const g = parseInt(value.substring(2, 4), 16);
			const b = parseInt(value.substring(4, 6), 16);
			if ([r, g, b].some((v) => Number.isNaN(v))) {
				return `rgba(96, 125, 139, ${alpha})`;
			}
			return `rgba(${r}, ${g}, ${b}, ${alpha})`;
		}

		function hexToRgbObject(hex) {
			if (!hex) {
				return null;
			}
			let value = hex.replace("#", "");
			if (value.length === 3) {
				value = value
					.split("")
					.map((ch) => ch + ch)
					.join("");
			}
			const r = parseInt(value.substring(0, 2), 16);
			const g = parseInt(value.substring(2, 4), 16);
			const b = parseInt(value.substring(4, 6), 16);
			if ([r, g, b].some((v) => Number.isNaN(v))) {
				return null;
			}
			return { r, g, b };
		}

		function getWarningAccentColor(bgColor) {
			const rgb = hexToRgbObject(bgColor);
			if (!rgb) {
				return "#ffeb3b";
			}
			const brightness = (rgb.r * 299 + rgb.g * 587 + rgb.b * 114) / 1000;
			return brightness >= 170 ? "#8b0000" : "#ffeb3b";
		}

		// Local references to shared conflict resolution helpers
		const resolveConflictRecords = window.resolveConflictRecords;

		function buildShiftBarLabelLines(emp, durationMinutes, barHeight) {
			if (!emp || typeof barHeight !== "number") {
				return [];
			}

			const thresholds = SHIFT_BAR_LABEL_THRESHOLDS;
			if (barHeight < thresholds.initials) {
				return [];
			}

			const initials = getInitials(emp.title || "").trim();
			const durationHoursLabel = formatHoursValue(durationMinutes / 60, false);

			if (barHeight < thresholds.initialsAndHours) {
				return initials ? [initials] : [];
			}

			if (barHeight < thresholds.fullName) {
				const lines = [];
				if (initials) {
					lines.push(initials);
				}
				lines.push(durationHoursLabel);
				return lines;
			}

			const shortName = getShortName(emp.title || "").trim();
			const nameLine = shortName || initials;
			const lines = [];
			if (nameLine) {
				lines.push(nameLine);
			}
			lines.push(durationHoursLabel);
			return lines;
		}

		function renderShiftBars(laneAssignmentsByDate) {
			// Group shifts by display date
			const shiftsByDate = {};

			shifts.forEach((shift) => {
				const shiftDate = shiftplannerGetDisplayDate(shift.start);
				if (!shiftDate) return;
				if (!shiftsByDate[shiftDate]) {
					shiftsByDate[shiftDate] = [];
				}
				shiftsByDate[shiftDate].push(shift);
			});

	// For each date column, render vertical bars
	Object.keys(shiftsByDate).forEach((dateISO) => {
		const dayShifts = shiftsByDate[dateISO];
		if (Array.isArray(dayShifts)) {
			dayShifts.sort((a, b) => {
				const ta = getShiftTaskKey(a);
				const tb = getShiftTaskKey(b);
				const ra = getTaskRank(ta);
				const rb = getTaskRank(tb);
				if (ra !== rb) return ra - rb;
				return String(a.id || "").localeCompare(String(b.id || ""));
			});
		}

		// Get the first cell of this date column to use as container
		const firstCell = document.querySelector(
			`td[data-date="${dateISO}"][data-hour="${SHIFT_DAY_START_HOUR}"]`
		);
				if (!firstCell) return;

				// Render each shift as a vertical bar
				dayShifts.forEach((shift) => {
					const empId = getShiftEmployeeKey(shift);
					if (!empId || !employeeMap[empId]) return;

					const startMinutesRaw = parseTime(shift.start);
					const endMinutesRaw = parseTime(shift.end);
					if (startMinutesRaw === null || endMinutesRaw === null) return;

					const normalizedStartMinutes =
						shiftplannerNormalizeMinutes(startMinutesRaw);
					let normalizedEndMinutes =
						shiftplannerNormalizeMinutes(endMinutesRaw);
					if (normalizedStartMinutes === null || normalizedEndMinutes === null)
						return;
					if (normalizedEndMinutes <= normalizedStartMinutes) {
						normalizedEndMinutes += MINUTES_PER_DAY;
					}

					const durationMinutes = normalizedEndMinutes - normalizedStartMinutes;
					if (durationMinutes <= 0) return;

					// Use measured row height for precise alignment
					const rowHeight = hourRowHeightPx;
					const minutesFromDayStart = Math.max(
						0,
						normalizedStartMinutes - SHIFT_DAY_START_MINUTES
					);
					const topOffset = (minutesFromDayStart / 60) * rowHeight;
					const barHeight = (durationMinutes / 60) * rowHeight;

					// Create shift bar
					const bar = document.createElement("div");
					bar.className = "shift-bar";
					bar.draggable = Boolean(canWrite);
					bar.dataset.shiftId = shift.id;
					bar.dataset.empId = empId;
					bar.dataset.date = dateISO;
					bar.dataset.startTime = startMinutesRaw;
					bar.dataset.endTime = endMinutesRaw;
					const taskId = getShiftTaskKey(shift);
					if (taskId) {
						bar.dataset.taskId = taskId;
					}

					const startStr = formatMinutesToTime(startMinutesRaw);
					const endStr = formatMinutesToTime(endMinutesRaw);
					const taskLabel =
						taskId && taskMap[taskId] ? ` (${taskMap[taskId].label})` : "";

					bar.title = `${employeeMap[empId].title}${taskLabel}: ${startStr}–${endStr}`;

					// Position and size
					bar.style.top = `${topOffset}px`;
					bar.style.height = `${barHeight}px`;

					// Use employee's consistent lane and color
					const laneInfo = laneAssignmentsByDate[dateISO];
					const lane =
						laneInfo &&
						laneInfo.laneByEmp &&
						laneInfo.laneByEmp[empId] !== undefined
							? laneInfo.laneByEmp[empId]
							: 0;
						const horizontalOffset =
							SUMMARY_STACK_OFFSET + lane * LANE_WIDTH_PX + LANE_OFFSET_PX;
						bar.style.left = `${horizontalOffset}px`;
						const taskColor =
							(taskId && taskMap[taskId] && taskMap[taskId].color) ||
							shift.color ||
							shift.taskColor ||
							"#3788d8";
						bar.style.backgroundColor = taskColor;
						const textColor =
							(taskId && taskMap[taskId] && taskMap[taskId].textColor) ||
							shift.taskTextColor ||
							DEFAULT_TASK_TEXT_COLOR;
						bar.style.color = textColor;
						bar.classList.remove("over-capacity");
						const overCapacity = false;
						const warningAccentColor = getWarningAccentColor(taskColor);

					const labelLines = buildShiftBarLabelLines(
						employeeMap[empId],
						durationMinutes,
						barHeight
					);
					if (labelLines.length) {
						if (labelLines.length === 1) {
							const single = labelLines[0];
							if (overCapacity) {
								bar.innerHTML = `<span class="shift-bar-hours-over" style="color:${warningAccentColor};">${escapeHtml(
									single
								)}</span>`;
							} else {
								bar.textContent = single;
							}
						} else {
							const timePart = labelLines[labelLines.length - 1];
							const labelPart = labelLines.slice(0, -1).join(" ").trim();
							if (overCapacity) {
								const prefix = labelPart
									? `${escapeHtml(labelPart)}&nbsp;`
									: "";
								bar.innerHTML = `${prefix}<span class="shift-bar-hours-over" style="color:${warningAccentColor};">${escapeHtml(
									`(${timePart})`
								)}</span>`;
							} else {
								const composed = labelPart
									? `${labelPart} (${timePart})`
									: `(${timePart})`;
								bar.textContent = composed;
							}
						}
					} else {
						bar.textContent = "";
					}

					// Always allow opening the modal (view-only if no write rights)
					bar.addEventListener("click", handleBarClick);

					// Drag/resize only when edits are allowed
					if (canWrite) {
						bar.addEventListener("mousedown", handleMouseDown);
						bar.addEventListener("dragstart", handleShiftBarDragStart);
						bar.addEventListener("dragend", handleShiftBarDragEnd);
					}

					firstCell.appendChild(bar);
				});
			});
		}

		function parseTime(isoString) {
			if (!isoString) return null;
			const match = isoString.match(/[T\s](\d{2}):(\d{2})/);
			if (!match) return null;

			const hours = parseInt(match[1], 10);
			const minutes = parseInt(match[2], 10);
			if (Number.isNaN(hours) || Number.isNaN(minutes)) return null;

			return hours * 60 + minutes;
		}

		function formatMinutesToTime(minutes) {
			const h = Math.floor(minutes / 60);
			const m = minutes % 60;
			return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
		}

		function formatDateTimeLocal(date) {
			const year = date.getFullYear();
			const month = String(date.getMonth() + 1).padStart(2, "0");
			const day = String(date.getDate()).padStart(2, "0");
			const hours = String(date.getHours()).padStart(2, "0");
			const minutes = String(date.getMinutes()).padStart(2, "0");
			return `${year}-${month}-${day}T${hours}:${minutes}`;
		}

		function extractTimeFromISO(isoString) {
			if (!isoString) return "";
			const match = isoString.match(/T(\d{2}):(\d{2})/);
			if (!match) return "";
			return `${match[1]}:${match[2]}`;
		}

		function combineDateAndTime(dateISO, timeValue) {
			if (!dateISO || !timeValue) return "";
			const normalizedTime =
				timeValue.length === 5 ? `${timeValue}:00` : timeValue;
			return `${dateISO}T${normalizedTime}`;
		}

		function isoToDateTimeLocal(value) {
			if (!value) return "";
			return value.slice(0, 16);
		}

		function normalizeDateTimeLocal(value) {
			if (!value) return "";
			return value.length === 16 ? `${value}:00` : value;
		}

		function normalizeTimeString(value) {
			if (!value) return "";
			const trimmed = value.trim();
			if (!trimmed) return "";
			const timeMatch = trimmed.match(/^(\d{1,2})(?::(\d{0,2}))?$/);
			if (timeMatch) {
				let hours = parseInt(timeMatch[1], 10);
				let minutes =
					timeMatch[2] !== undefined ? parseInt(timeMatch[2], 10) : 0;
				if (Number.isNaN(hours) || Number.isNaN(minutes)) {
					return "";
				}
				hours = Math.max(0, Math.min(23, hours));
				minutes = Math.max(0, Math.min(59, minutes));
				return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(
					2,
					"0"
				)}`;
			}
			const parsed = new Date(trimmed);
			if (!Number.isNaN(parsed.getTime())) {
				return `${String(parsed.getHours()).padStart(2, "0")}:${String(
					parsed.getMinutes()
				).padStart(2, "0")}`;
			}
			return "";
		}

		function escapeHtml(str) {
			if (str === undefined || str === null) return "";
			return String(str)
				.replace(/&/g, "&amp;")
				.replace(/</g, "&lt;")
				.replace(/>/g, "&gt;")
				.replace(/"/g, "&quot;")
				.replace(/'/g, "&#39;");
		}

		function handleMouseDown(e) {
			if (!canWrite) return;
			const bar = e.currentTarget;
			const rect = bar.getBoundingClientRect();
			const clickY = e.clientY - rect.top;

			// Check if clicking on resize handles (top 6px or bottom 6px)
			if (clickY <= 6) {
				// Resize start (top)
				isResizing = true;
				suppressNextBarClick = true;
				resizeHandle = "start";
				resizingBar = bar;
				resizeStartY = e.clientY;
				resizeOriginalTop = parseFloat(bar.style.top);
				resizeOriginalHeight = parseFloat(bar.style.height);
				bar.classList.add("resizing");
				e.preventDefault();
				e.stopPropagation();
			} else if (clickY >= rect.height - 6) {
				// Resize end (bottom)
				isResizing = true;
				suppressNextBarClick = true;
				resizeHandle = "end";
				resizingBar = bar;
				resizeStartY = e.clientY;
				resizeOriginalHeight = parseFloat(bar.style.height);
				bar.classList.add("resizing");
				e.preventDefault();
				e.stopPropagation();
			}
		}

		function handleMouseMove(e) {
			if (!canWrite) return;
			if (!isResizing || !resizingBar) return;

			const rowHeight = hourRowHeightPx;
			const minuteStepHeight = rowHeight * (SHIFT_MINUTE_STEP / 60);
			const maxShiftHeight =
				SHIFT_MAX_SHIFT_MINUTES > 0
					? (SHIFT_MAX_SHIFT_MINUTES / 60) * rowHeight
					: null;
			const deltaY = e.clientY - resizeStartY;

			if (resizeHandle === "start") {
				// Resize from top - adjust both top position and height
				let newTop = resizeOriginalTop + deltaY;
				let newHeight = resizeOriginalHeight - deltaY;

				newTop = Math.round(newTop / minuteStepHeight) * minuteStepHeight;
				newHeight = resizeOriginalTop + resizeOriginalHeight - newTop;

				if (newHeight >= minuteStepHeight) {
					if (maxShiftHeight && newHeight > maxShiftHeight) {
						newHeight = maxShiftHeight;
						const bottom = resizeOriginalTop + resizeOriginalHeight;
						newTop = bottom - newHeight;
					}
					resizingBar.style.top = `${newTop}px`;
					resizingBar.style.height = `${newHeight}px`;
				}
			} else if (resizeHandle === "end") {
				// Resize from bottom - only adjust height
				let newHeight = resizeOriginalHeight + deltaY;

				newHeight = Math.round(newHeight / minuteStepHeight) * minuteStepHeight;

				if (newHeight >= minuteStepHeight) {
					if (maxShiftHeight && newHeight > maxShiftHeight) {
						newHeight = maxShiftHeight;
					}
					resizingBar.style.height = `${newHeight}px`;
				}
			}
		}

		function handleMouseUp(e) {
			if (!canWrite) return;
			if (!isResizing || !resizingBar) return;

			resizingBar.classList.remove("resizing");

			const shiftId = resizingBar.dataset.shiftId;
			const shift = shifts.find((s) => s.id == shiftId);
			if (!shift) {
				isResizing = false;
				resizingBar = null;
				return;
			}

			// Calculate new times based on bar position and height
			const rowHeight = hourRowHeightPx;
			const top = parseFloat(resizingBar.style.top);
			const height = parseFloat(resizingBar.style.height);

			// Convert pixels to minutes relative to configured day start
			const minutesFromDayStartStart = (top / rowHeight) * 60;
			const minutesFromDayStartEnd = ((top + height) / rowHeight) * 60;

			// Convert to minutes from midnight and snap to configured interval
			let newStartMinutes = SHIFT_DAY_START_MINUTES + minutesFromDayStartStart;
			let newEndMinutes = SHIFT_DAY_START_MINUTES + minutesFromDayStartEnd;
			newStartMinutes =
				Math.round(newStartMinutes / SHIFT_MINUTE_STEP) * SHIFT_MINUTE_STEP;
			newEndMinutes =
				Math.round(newEndMinutes / SHIFT_MINUTE_STEP) * SHIFT_MINUTE_STEP;
			if (newEndMinutes <= newStartMinutes) {
				newEndMinutes = newStartMinutes + SHIFT_MINUTE_STEP;
			}

			// Format as ISO strings
			const dateISO = resizingBar.dataset.date;
			const newStart = shiftplannerMinutesToIso(dateISO, newStartMinutes);
			const newEnd = shiftplannerMinutesToIso(dateISO, newEndMinutes);

			// Update via API
			updateShift(
				shiftId,
				shift.resourceId || shift.fk_user,
				shift.title,
				newStart,
				newEnd,
				shift.fk_task || shift.taskId || "",
				shift.shift_type || shift.shiftType || "1"
			);

			isResizing = false;
			resizeHandle = null;
			resizingBar = null;
			setTimeout(() => {
				suppressNextBarClick = false;
			}, 0);
		}

		function handleBarClick(e) {
			e.stopPropagation();
			if (suppressNextBarClick) {
				suppressNextBarClick = false;
				return;
			}

			const shiftId = e.currentTarget.dataset.shiftId;
			const shift = shifts.find((s) => String(s.id) === String(shiftId));
			if (!shift) return;

			const readOnlyView = !canWrite;
			const shiftDate = shift.start ? shift.start.split("T")[0] : "";
			const empId = shift.resourceId || shift.fk_user || shift.empId;
			const taskId = shift.fk_task || shift.taskId || null;
			openShiftDialog({
				mode: "edit",
				title: shiftDate ? `Editar Turno - ${shiftDate}` : "Editar Turno",
				shiftId: shift.id,
				selectedEmployeeId: empId,
				taskId,
				startValue: isoToDateTimeLocal(shift.start),
				endValue: isoToDateTimeLocal(shift.end),
				labelValue: shift.title || shift.label || "",
				preset: { shiftType: shift.shift_type || shift.shiftType || "1" },
				readOnly: readOnlyView,
			});
		}

		function handleShiftBarDragStart(e) {
			if (!canWrite) return;
			const bar = e.currentTarget;
			const shiftId = bar && bar.dataset ? bar.dataset.shiftId : null;
			if (!shiftId || !e.dataTransfer) return;
			draggedShiftId = shiftId;
			suppressNextBarClick = true;
			e.dataTransfer.effectAllowed = "move";
			e.dataTransfer.setData(SHIFT_DRAG_TYPE, shiftId);
			e.dataTransfer.setData("text/plain", shiftId);

			// Add dragging class first
			if (bar && bar.classList) {
				bar.classList.add("dragging");
			}

			// Create a custom drag image to preserve rotated text and anchor the top
			const dragImage = bar.cloneNode(true);
			dragImage.style.position = "absolute";
			dragImage.style.top = "-9999px";
			dragImage.style.opacity = "0.6";
			dragImage.classList.add("dragging");
			document.body.appendChild(dragImage);

			const rect = bar.getBoundingClientRect();
			const dragOffsetX = rect.width / 2;
			const dragOffsetY = 0; // anchor the top of the bar
			e.dataTransfer.setDragImage(dragImage, dragOffsetX, dragOffsetY);

			setTimeout(() => {
				if (dragImage && dragImage.parentNode) {
					document.body.removeChild(dragImage);
				}
			}, 0);
		}

		function handleShiftBarDragEnd(e) {
			const bar = e.currentTarget;
			if (bar && bar.classList) {
				bar.classList.remove("dragging");
			}
			draggedShiftId = null;
			setTimeout(() => {
				suppressNextBarClick = false;
			}, 0);
		}

		async function updateShift(
			id,
			empId,
			label,
			start,
			end,
			taskId,
			shiftType
		) {
			if (start && end && !isShiftDurationAllowed(start, end)) {
				alert(getShiftDurationLimitMessage());
				return;
			}
			try {
				const resp = await fetch(apiUrl, {
					method: "POST",
					headers: { "Content-Type": "application/x-www-form-urlencoded" },
					body: new URLSearchParams({
						action: "update",
						id: id,
						fk_user: empId,
						fk_task: taskId !== undefined ? taskId : "",
						label: label,
						start: start,
						end: end,
						shift_type: shiftType || "1",
					}),
				});

				const text = await resp.text();
				let result;
				try {
					result = JSON.parse(text);
				} catch (err) {
					throw new Error(text || err.message || "Erro ao atualizar turno");
				}
				if (!result.success) {
					throw new Error(
						translateApiError(result.error, "Erro ao atualizar turno")
					);
				}

				// Reload data to refresh view
				loadData();
			} catch (error) {
				alert(error.message);
				loadData(); // Reload to revert visual state
			}
		}

		function handleCellClick(e) {
			if (!canWrite) return;
			const cell = e.target.closest("td[data-date][data-hour]");
			if (!cell) return;
			if (cell.classList && cell.classList.contains("conflict")) {
				handleConflictCellClick(cell);
				return;
			}

			// Don't open dialog if clicking on a shift bar
			if (e.target.closest(".shift-bar")) return;

			const dateISO = cell.dataset.date;
			const hour = parseInt(cell.dataset.hour, 10);

			showCreateDialog(dateISO, hour);
		}

		function handleEmployeeDragStart(e) {
			if (!canWrite) return;
			const empId = e.currentTarget.dataset.empId;
			if (!empId || !e.dataTransfer) return;
			e.dataTransfer.setData(EMP_DRAG_TYPE, empId);
			e.dataTransfer.effectAllowed = "copy";
		}

		function handleGridDragOver(e) {
			if (!canWrite) return;
			const cell = e.target.closest("td[data-date][data-hour]");
			if (!cell || !e.dataTransfer) return;
			const types = Array.from(e.dataTransfer.types || []);
			const hasEmployee = types.includes(EMP_DRAG_TYPE);
			const hasShift = types.includes(SHIFT_DRAG_TYPE);
			if (!hasEmployee && !hasShift) return;
			if (hasShift && !draggedShiftId) return;
			e.preventDefault();
			e.dataTransfer.dropEffect = hasShift ? "move" : "copy";
		}

		function handleGridDrop(e) {
			if (!canWrite) return;
			const cell = e.target.closest("td[data-date][data-hour]");
			if (!cell || !e.dataTransfer) return;
			const types = Array.from(e.dataTransfer.types || []);
			if (types.includes(SHIFT_DRAG_TYPE)) {
				const shiftId = e.dataTransfer.getData(SHIFT_DRAG_TYPE);
				if (!shiftId) return;
				e.preventDefault();
				e.stopPropagation();
				const dateISO = cell.dataset.date;
				if (!dateISO) return;
				const dropOffset = computeDropMinutesOffset(dateISO, e.clientY);
				moveShiftToDate(shiftId, dateISO, dropOffset);
				return;
			}
			if (!types.includes(EMP_DRAG_TYPE)) return;
			const empId = e.dataTransfer.getData(EMP_DRAG_TYPE);
			if (!empId) return;
			e.preventDefault();
			e.stopPropagation();
			const hour = parseInt(cell.dataset.hour, 10);
			if (Number.isNaN(hour)) return;
			showCreateDialog(cell.dataset.date, hour, empId);
		}

		function computeDropMinutesOffset(dateISO, clientY) {
			if (!dateISO) return null;
			const anchorCell = document.querySelector(
				`td[data-date="${dateISO}"][data-hour="${SHIFT_DAY_START_HOUR}"]`
			);
			if (
				!anchorCell ||
				typeof anchorCell.getBoundingClientRect !== "function"
			) {
				return null;
			}
			const rect = anchorCell.getBoundingClientRect();
			const rowHeight = hourRowHeightPx || rect.height || 40;
			let relativeY = typeof clientY === "number" ? clientY - rect.top : 0;
			if (!Number.isFinite(relativeY)) {
				relativeY = 0;
			}
			relativeY = Math.max(0, relativeY);
			const minutesWithinDay = (relativeY / rowHeight) * 60;
			const snappedMinutes =
				Math.floor(minutesWithinDay / SHIFT_MINUTE_STEP) * SHIFT_MINUTE_STEP;
			return snappedMinutes;
		}

		function showCreateDialog(dateISO, startHour, preferredEmployeeId) {
			if (!canWrite) return;
			const [year, month, day] = dateISO.split("-").map(Number);
			const startDate = new Date(year, (month || 1) - 1, day || 1, 0, 0, 0);
			let normalizedStartMinutes = shiftplannerNormalizeMinutes(startHour * 60);
			if (normalizedStartMinutes === null) {
				normalizedStartMinutes = startHour * 60;
			}
			startDate.setMinutes(normalizedStartMinutes);
			const endDate = new Date(startDate.getTime() + 60 * 60 * 1000);
			const defaultEmployeeId =
				preferredEmployeeId || (employees.length ? employees[0].id : "");
			const defaultLabel = employeeMap[defaultEmployeeId]
				? employeeMap[defaultEmployeeId].title
				: "";

			openShiftDialog({
				mode: "create",
				title: `Criar Turno - ${dateISO}`,
				shiftId: null,
				selectedEmployeeId: defaultEmployeeId,
				taskId: null,
				startValue: formatDateTimeLocal(startDate),
				endValue: formatDateTimeLocal(endDate),
				labelValue: defaultLabel,
			});
		}

		function showEditDialog(shift, readOnly = false) {
			const shiftDate = shift.start ? shift.start.split("T")[0] : "";
			openShiftDialog({
				mode: "edit",
				title: shiftDate ? `Editar Turno - ${shiftDate}` : "Editar Turno",
				shiftId: shift.id,
				selectedEmployeeId: shift.resourceId || shift.fk_user,
				taskId: shift.fk_task || shift.taskId || null,
				startValue: isoToDateTimeLocal(shift.start),
				endValue: isoToDateTimeLocal(shift.end),
				labelValue: shift.title || "",
				preset: { shiftType: shift.shift_type || shift.shiftType || "1" },
				readOnly,
			});
		}

		function openShiftDialog({
			mode,
			title,
			shiftId,
			selectedEmployeeId,
			taskId,
			startValue,
			endValue,
			labelValue,
		readOnly = false,
		}) {
			const isEdit = mode === "edit";
			if (!employees.length && !selectedEmployeeId) {
				alert("Não existem funcionários ativos para planear.");
				return;
			}

			// Remove any existing dialog
			const existing = document.getElementById("shift-dialog-overlay");
			if (existing) {
				existing.remove();
			}

			const startInputValueRaw = startValue || "";
			const endInputValueRaw = endValue || "";
			const startDateISO =
				(startInputValueRaw && startInputValueRaw.split("T")[0]) ||
				formatDateISO(new Date());
			const endDateISO =
				(endInputValueRaw && endInputValueRaw.split("T")[0]) || startDateISO;
			const startTimeValue = extractTimeFromISO(startInputValueRaw) || "09:00";
			const endTimeValue = extractTimeFromISO(endInputValueRaw) || "17:00";
			const startPickerId = `shift-start-picker-${Date.now()}-${Math.random()
				.toString(36)
				.slice(2, 7)}`;
			const endPickerId = `shift-end-picker-${Date.now()}-${Math.random()
				.toString(36)
				.slice(2, 7)}`;
			const initialShiftType = (() => {
				if (!isEdit) return "1";
				const existing = shiftMap.get(String(shiftId));
				if (!existing) return "1";
				const raw =
					existing.shift_type !== undefined && existing.shift_type !== null
						? existing.shift_type
						: existing.shiftType !== undefined && existing.shiftType !== null
						? existing.shiftType
						: 1;
				const num = Number.isFinite(Number(raw)) ? Number(raw) : 1;
				return String(num);
			})();

			const optionSource = employees.slice();
			if (
				selectedEmployeeId &&
				!optionSource.some(
					(emp) => String(emp.id) === String(selectedEmployeeId)
				)
			) {
				const fallbackTitle =
					(employeeMap[selectedEmployeeId] &&
						employeeMap[selectedEmployeeId].title) ||
					`Funcionário #${selectedEmployeeId}`;
				optionSource.push({ id: selectedEmployeeId, title: fallbackTitle });
				if (!employeeMap[selectedEmployeeId]) {
					employeeMap[selectedEmployeeId] = {
						id: selectedEmployeeId,
						title: fallbackTitle,
					};
				}
			}

			const employeeOptions = optionSource
				.map((emp) => {
					const selected =
						String(emp.id) === String(selectedEmployeeId) ? " selected" : "";
					return `<option value="${emp.id}"${selected}>${escapeHtml(
						emp.title
					)}</option>`;
				})
				.join("");

			const dialogHTML = `
					<div id="shift-dialog-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 13000; display: flex; align-items: center; justify-content: center;">
						<div id="shift-dialog" class="shift-dialog conflict-dialog" style="overflow: visible;">
							<h3>${escapeHtml(title)}</h3>
							<div style="margin-bottom: 12px;">
								<label style="display: block; margin-bottom: 5px;">Funcionário:</label>
								<select id="dialog-employee" style="width: 100%; padding: 5px;">
									${employeeOptions}
								</select>
							</div>
							<div style="margin-bottom: 12px;">
								<label style="display: block; margin-bottom: 5px;">Tarefa:</label>
								<select id="dialog-task" style="width: 100%; padding: 5px;"></select>
							</div>
							<div style="margin-bottom: 12px;">
								<label style="display: block; margin-bottom: 5px;">Tipo de turno:</label>
								<select id="dialog-shift-type" style="width: 100%; padding: 5px;">
									<option value="1"${initialShiftType === "1" ? " selected" : ""}>Turno Normal</option>
									<option value="2"${initialShiftType === "2" ? " selected" : ""}>Turno Especial</option>
								</select>
							</div>
							<div style="display: flex; gap: 10px; margin-bottom: 12px;">
								<div style="flex: 1;">
									<label style="display: block; margin-bottom: 5px;">Data Início:</label>
									<input type="date" id="dialog-start-date" value="${startDateISO}" style="width: 100%; padding: 5px;">
								</div>
								<div style="flex: 1;">
									<label style="display: block; margin-bottom: 5px;">Data Fim:</label>
									<input type="date" id="dialog-end-date" value="${endDateISO}" style="width: 100%; padding: 5px;">
								</div>
							</div>
							<div style="display: flex; gap: 10px; margin-bottom: 12px;">
								<div style="flex: 1;">
									<label style="display: block; margin-bottom: 5px;">Hora Início:</label>
									<div class="input-group input-group-sm date timepicker-control" id="${startPickerId}" data-target-input="nearest">
										<input type="text" class="form-control form-control-sm datetimepicker-input" id="dialog-start" autocomplete="off" value="${escapeHtml(
											startTimeValue
										)}" style="width: 100%; padding: 5px;">
									</div>
								</div>
								<div style="flex: 1;">
									<label style="display: block; margin-bottom: 5px;">Hora Fim:</label>
									<div class="input-group input-group-sm date timepicker-control" id="${endPickerId}" data-target-input="nearest">
										<input type="text" class="form-control form-control-sm datetimepicker-input" id="dialog-end" autocomplete="off" value="${escapeHtml(
											endTimeValue
										)}" style="width: 100%; padding: 5px;">
									</div>
								</div>
							</div>
							<div style="display: flex; gap: 10px; justify-content: flex-end;">
								<button id="dialog-cancel" style="padding: 8px 16px;">Cancelar</button>
								${
									isEdit
										? '<button id="dialog-delete" style="padding: 8px 16px; background: #d9534f; color: white; border: none; cursor: pointer;">Eliminar</button>'
										: ""
								}
								<button id="dialog-save" style="padding: 8px 16px; background: #0066cc; color: white; border: none; cursor: pointer;">Guardar</button>
							</div>
						</div>
					</div>
				`;

			document.body.insertAdjacentHTML("beforeend", dialogHTML);

			const dialogOverlay = document.getElementById("shift-dialog-overlay");
			const dialog = document.getElementById("shift-dialog");
			const dialogInner = dialog;
			const saveBtn = document.getElementById("dialog-save");
			const cancelBtn = document.getElementById("dialog-cancel");
			const employeeSelect = document.getElementById("dialog-employee");
			const taskSelect = document.getElementById("dialog-task");
			const startDateInput = document.getElementById("dialog-start-date");
			const endDateInput = document.getElementById("dialog-end-date");
			const startInput = document.getElementById("dialog-start");
			const endInput = document.getElementById("dialog-end");
			const deleteBtn = document.getElementById("dialog-delete");
			initializeTimePicker(startPickerId, startTimeValue, dialogInner);
			initializeTimePicker(endPickerId, endTimeValue, dialogInner);

			populateTaskSelect(taskSelect, taskId, selectedEmployeeId);

			if (readOnly) {
				// Disable all form fields for view-only mode
				dialogOverlay.querySelectorAll("input, select, textarea, button").forEach((el) => {
					if (el.id === "dialog-cancel") return; // Keep cancel button enabled
					el.disabled = true;
				});
			if (saveBtn) {
				saveBtn.style.display = "none";
			}
			if (deleteBtn) {
				deleteBtn.style.display = "none";
			}
		}

			saveBtn.addEventListener("click", () => {
				const empId = employeeSelect.value;
				const startDateISO = startDateInput.value;
				const endDateISO = endDateInput.value || startDateISO;
				const startTimeVal = normalizeTimeString(startInput.value);
				const endTimeVal = normalizeTimeString(endInput.value);
				const taskIdValue = taskSelect.value;
				const label = "";

				if (!empId) {
					alert("Selecione um funcionário.");
					return;
				}
				if (!startDateISO || !startTimeVal || !endDateISO || !endTimeVal) {
					alert("Preencha data e hora.");
					return;
				}
				const startDateTime = combineDateAndTime(startDateISO, startTimeVal);
				const endDateTime = combineDateAndTime(endDateISO, endTimeVal);
				if (!startDateTime || !endDateTime) {
					alert("Datas inválidas.");
					return;
				}
				if (
					!shiftplannerMatchesMinuteStep(startDateTime) ||
					!shiftplannerMatchesMinuteStep(endDateTime)
				) {
					alert(
						`Os horários devem respeitar o intervalo configurado de ${SHIFT_MINUTE_STEP} minutos.`
					);
					return;
				}
				if (!isShiftDurationAllowed(startDateTime, endDateTime)) {
					alert(getShiftDurationLimitMessage());
					return;
				}
				if (startDateTime >= endDateTime) {
					alert("A hora de fim deve ser posterior ao início.");
					return;
				}
				if (!isShiftDurationAllowed(startDateTime, endDateTime)) {
					alert(getShiftDurationLimitMessage());
					return;
				}
				if (!taskIdValue) {
					alert("Selecione uma tarefa.");
					return;
				}

				const shiftTypeValue =
					(document.getElementById("dialog-shift-type") || {}).value || "1";

				if (isEdit) {
					updateShift(
						shiftId,
						empId,
						label,
						startDateTime,
						endDateTime,
						taskIdValue,
						shiftTypeValue
					);
				} else {
					createShift(
						empId,
						label,
						startDateTime,
						endDateTime,
						taskIdValue,
						shiftTypeValue
					);
				}

				dialogOverlay.remove();
			});

			cancelBtn.addEventListener("click", () => dialogOverlay.remove());
			dialogOverlay.addEventListener("click", (e) => {
				if (e.target === dialogOverlay) dialogOverlay.remove();
			});

			if (deleteBtn && isEdit) {
				deleteBtn.addEventListener("click", async () => {
					if (!confirm("Eliminar este turno?")) return;
					await deleteShift(shiftId);
					dialogOverlay.remove();
				});
			}

			// Close on background click
			dialog.addEventListener("click", (e) => {
				if (e.target === dialog) {
					dialogOverlay.remove();
				}
			});
		}

		function openTaskManager(onClose) {
			const existing = document.getElementById("task-manager-overlay");
			if (existing) existing.remove();

			const overlay = document.createElement("div");
			overlay.id = "task-manager-overlay";
			overlay.style.position = "fixed";
			overlay.style.top = "0";
			overlay.style.left = "0";
			overlay.style.width = "100%";
			overlay.style.height = "100%";
			overlay.style.background = "rgba(0,0,0,0.5)";
			overlay.style.zIndex = "20000";
			overlay.style.display = "flex";
			overlay.style.alignItems = "center";
			overlay.style.justifyContent = "center";

			const dialog = document.createElement("div");
			dialog.className = "task-manager-dialog";
			dialog.style.background = "#fff";
			dialog.style.padding = "20px";
			dialog.style.borderRadius = "8px";
			dialog.style.minWidth = "420px";
			dialog.style.maxWidth = "520px";
			dialog.style.maxHeight = "80vh";
			dialog.style.overflowY = "auto";
			dialog.style.zIndex = "20001";

			dialog.innerHTML = `
    			<h3>Gerir Tarefas</h3>
    			<div id="task-manager-list"></div>
    			<div style="margin-top: 15px;">
    				<h4>Nova tarefa</h4>
    				<div style="display: flex; gap: 8px; align-items: center;">
    					<input type="text" id="task-new-name" placeholder="Nome da tarefa" style="flex: 1; padding: 6px;">
    					<input type="color" id="task-new-color" value="#3788d8" style="width: 60px; height: 34px; border: none;">
    					<input type="color" id="task-new-text-color" value="#ffffff" style="width: 60px; height: 34px; border: none;" title="Cor do texto">
    					<div id="task-new-preview" class="task-color-preview">Aa</div>
    					<button id="task-add-btn" style="padding: 8px 12px;">Adicionar</button>
    				</div>
    			</div>
    			<div style="text-align: right; margin-top: 16px;">
    				<button id="task-close-btn" style="padding: 8px 16px;">Fechar</button>
    			</div>
    		`;

			overlay.appendChild(dialog);
			document.body.appendChild(overlay);

			const taskList = dialog.querySelector("#task-manager-list");
			const addBtn = dialog.querySelector("#task-add-btn");
			const newNameInput = dialog.querySelector("#task-new-name");
			const newColorInput = dialog.querySelector("#task-new-color");
			const newTextColorInput = dialog.querySelector("#task-new-text-color");
			const newPreview = dialog.querySelector("#task-new-preview");
			const closeBtn = dialog.querySelector("#task-close-btn");

			const canManageTasks = window.SHIFT_CAN_MANAGE_TASKS || false;

			// Disable add button and inputs if no permission
			if (!canManageTasks && addBtn) {
				addBtn.disabled = true;
				addBtn.style.opacity = "0.5";
				addBtn.style.cursor = "not-allowed";
				if (newNameInput) newNameInput.disabled = true;
				if (newColorInput) newColorInput.disabled = true;
				if (newTextColorInput) newTextColorInput.disabled = true;
			}

			const updateNewPreview = () => {
				if (!newPreview) return;
				const bg = newColorInput ? newColorInput.value || "#3788d8" : "#3788d8";
				const textColor = newTextColorInput
					? newTextColorInput.value || DEFAULT_TASK_TEXT_COLOR
					: DEFAULT_TASK_TEXT_COLOR;
				newPreview.style.backgroundColor = bg;
				newPreview.style.color = textColor;
			};
			updateNewPreview();
			if (newColorInput) {
				newColorInput.addEventListener("input", updateNewPreview);
			}
			if (newTextColorInput) {
				newTextColorInput.addEventListener("input", updateNewPreview);
			}

			const refreshLists = () => {
				renderLegend();
				renderTable();
			};

			const renderRows = () => {
				if (!tasks.length) {
					taskList.innerHTML =
						'<div style="padding: 8px; font-style: italic; color: #555;">Nenhuma tarefa definida.</div>';
					return;
				}
				const disabledStyle = canManageTasks
					? ""
					: ' disabled style="opacity:0.5; cursor:not-allowed;"';
				const inputDisabled = canManageTasks ? "" : " disabled";
				taskList.innerHTML = tasks
					.map(
						(task) => `
    				<div class="task-row" data-id="${
							task.id
						}" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
    					<input type="text" class="task-name" value="${escapeHtml(
								task.label
							)}" style="flex:1; padding:6px;"${inputDisabled}>
    					<input type="color" class="task-color" value="${
								task.color || "#3788d8"
							}" style="width:60px; height:34px; border:none;"${inputDisabled}>
    					<input type="color" class="task-text-color" value="${
								task.textColor || DEFAULT_TASK_TEXT_COLOR
							}" style="width:60px; height:34px; border:none;" title="Cor do texto"${inputDisabled}>
    					<div class="task-color-preview"${inputDisabled.replace(
								" disabled",
								""
							)} style="opacity:0.55;">Aa</div>
    					<button class="task-save" style="padding:6px 10px;"${disabledStyle}>Guardar</button>
    					<button class="task-delete" style="padding:6px 10px; background:#d9534f; color:#fff; border:none;"${disabledStyle}>Eliminar</button>
    				</div>
    			`
					)
					.join("");

				taskList.querySelectorAll(".task-row").forEach((row) => {
					const colorInput = row.querySelector(".task-color");
					const textColorInput = row.querySelector(".task-text-color");
					const preview = row.querySelector(".task-color-preview");
					const updatePreview = () => {
						if (!preview) return;
						const bg = (colorInput && colorInput.value) || "#3788d8";
						const textColor =
							(textColorInput && textColorInput.value) ||
							DEFAULT_TASK_TEXT_COLOR;
						preview.style.backgroundColor = bg;
						preview.style.color = textColor;
					};
					updatePreview();
					if (colorInput) {
						colorInput.addEventListener("input", updatePreview);
					}
					if (textColorInput) {
						textColorInput.addEventListener("input", updatePreview);
					}
				});

				taskList.querySelectorAll(".task-row").forEach((row) => {
					const colorInput = row.querySelector(".task-color");
					const textColorInput = row.querySelector(".task-text-color");
					const preview = row.querySelector(".task-color-preview");
					const updatePreview = () => {
						if (!preview) return;
						const bg = (colorInput && colorInput.value) || "#3788d8";
						const textColor =
							(textColorInput && textColorInput.value) ||
							DEFAULT_TASK_TEXT_COLOR;
						preview.style.backgroundColor = bg;
						preview.style.color = textColor;
					};
					updatePreview();
					if (colorInput) {
						colorInput.addEventListener("input", updatePreview);
					}
					if (textColorInput) {
						textColorInput.addEventListener("input", updatePreview);
					}
				});

				taskList.querySelectorAll(".task-save").forEach((btn) => {
					btn.addEventListener("click", async () => {
						const row = btn.closest(".task-row");
						const id = row.dataset.id;
						const name = row.querySelector(".task-name").value.trim();
						const color = row.querySelector(".task-color").value;
						const textColorInput = row.querySelector(".task-text-color");
						const textColor = textColorInput
							? textColorInput.value
							: DEFAULT_TASK_TEXT_COLOR;
						if (!name) {
							alert("Nome da tarefa obrigatório.");
							return;
						}
						try {
							await updateTaskApi(id, name, color, textColor);
							await fetchTasks();
							renderRows();
							refreshLists();
						} catch (error) {
							alert(error.message);
						}
					});
				});

				taskList.querySelectorAll(".task-delete").forEach((btn) => {
					btn.addEventListener("click", async () => {
						const row = btn.closest(".task-row");
						const id = row.dataset.id;
						if (!confirm("Eliminar esta tarefa?")) return;
						try {
							await deleteTaskApi(id);
							await fetchTasks();
							renderRows();
							refreshLists();
						} catch (error) {
							alert(error.message);
						}
					});
				});
			};

			renderRows();

			if (canManageTasks) {
				addBtn.addEventListener("click", async () => {
					const name = newNameInput.value.trim();
					const color = newColorInput.value;
					const textColor = newTextColorInput
						? newTextColorInput.value
						: DEFAULT_TASK_TEXT_COLOR;
					if (!name) {
						alert("Introduza o nome da tarefa.");
						return;
					}
					try {
						await createTaskApi(name, color, textColor);
						newNameInput.value = "";
						if (newTextColorInput) {
							newTextColorInput.value = DEFAULT_TASK_TEXT_COLOR;
						}
						updateNewPreview();
						await fetchTasks();
						renderRows();
						refreshLists();
					} catch (error) {
						alert(error.message);
					}
				});
			}

			const closeManager = () => {
				overlay.remove();
				if (typeof onClose === "function") {
					onClose();
				}
			};

			closeBtn.addEventListener("click", closeManager);
			overlay.addEventListener("click", (e) => {
				if (e.target === overlay) {
					closeManager();
				}
			});
		}

		async function createTaskApi(label, color, textColor) {
			const resp = await fetch(apiUrl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "task_create",
					label: label,
					color: color,
					text_color: textColor || DEFAULT_TASK_TEXT_COLOR,
				}),
			});
			const result = await resp.json();
			if (!result.success) {
				throw new Error(
					translateApiError(result.error, "Erro ao criar tarefa")
				);
			}
		}

		async function updateTaskApi(id, label, color, textColor) {
			const resp = await fetch(apiUrl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "task_update",
					id: id,
					label: label,
					color: color,
					text_color: textColor || DEFAULT_TASK_TEXT_COLOR,
				}),
			});
			const result = await resp.json();
			if (!result.success) {
				throw new Error(
					translateApiError(result.error, "Erro ao atualizar tarefa")
				);
			}
		}

		async function deleteTaskApi(id) {
			const resp = await fetch(apiUrl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "task_delete",
					id: id,
				}),
			});
			const result = await resp.json();
			if (!result.success) {
				throw new Error(
					translateApiError(result.error, "Erro ao eliminar tarefa")
				);
			}
		}

			async function fetchTasks() {
				const tasksResp = await fetch(
					apiUrl + "?" + new URLSearchParams({ action: "task_list" })
				);
				const data = await tasksResp.json();
			tasks = Array.isArray(data)
				? data.map((task) => shiftplannerNormalizeTaskRecord(task))
				: [];
				taskMap = {};
				tasks.forEach((task) => {
					const taskKey = normalizeTaskKey(task.id);
					if (taskKey !== null) {
						taskMap[taskKey] = task;
						const numericKey = Number(taskKey);
						if (!Number.isNaN(numericKey)) {
							taskMap[numericKey] = task;
						}
					}
				});
				taskOrderRank = {};
				tasks.forEach((task, idx) => {
					const key = normalizeTaskKey(task.id);
					if (key !== null) {
						taskOrderRank[key] = idx;
						const num = Number(key);
						if (!Number.isNaN(num)) {
							taskOrderRank[num] = idx;
						}
					}
				});
				updateSummaryTaskMeta();
			}

		async function createShift(
			empId,
			label,
			startDateTime,
			endDateTime,
			taskId,
			shiftType = "1"
		) {
			if (!isShiftDurationAllowed(startDateTime, endDateTime)) {
				alert(getShiftDurationLimitMessage());
				return;
			}
			try {
				const empName =
					label || (employeeMap[empId] ? employeeMap[empId].title : "Turno");

				const resp = await fetch(apiUrl, {
					method: "POST",
					headers: { "Content-Type": "application/x-www-form-urlencoded" },
					body: new URLSearchParams({
						action: "create",
						fk_user: empId,
						fk_task: taskId || "",
						label: empName,
						start: startDateTime,
						end: endDateTime,
						shift_type: shiftType || "1",
					}),
				});

			const result = await resp.json();
			if (!result.success) {
				const errorMsg = translateApiError(result.error, "Erro ao criar turno");
				const missingFields = result.missing
					? " (Missing: " + result.missing.join(", ") + ")"
					: "";
				throw new Error(errorMsg + missingFields);
				}

				// Reload data to refresh view
				loadData();
			} catch (error) {
				console.error("Create shift error:", error);
				alert(error.message);
			}
		}

		async function deleteShift(id) {
			try {
				const resp = await fetch(apiUrl, {
					method: "POST",
					headers: { "Content-Type": "application/x-www-form-urlencoded" },
					body: new URLSearchParams({
						action: "delete",
						id: id,
					}),
				});

			const result = await resp.json();
			if (!result.success) {
				throw new Error(
					translateApiError(result.error, "Erro ao eliminar turno")
				);
			}

				loadData();
			} catch (error) {
				console.error("Delete shift error:", error);
				alert(error.message);
			}
		}

		function updateWeekPicker() {
			if (!weekPicker) return;
			if (weekPickerWidget && typeof weekPickerWidget.update === "function") {
				weekPickerWidget.update(currentWeekStart);
			} else {
				setWeekInputDisplay(weekPicker, currentWeekStart);
			}
		}

		function handleWeekPickerChange(e) {
			const value =
				(e.target.dataset && e.target.dataset.isoValue) || e.target.value;
			const parsed = parseWeekInput(value);
			if (!parsed) {
				updateWeekPicker();
				return;
			}
			currentWeekStart = parsed;
			syncWeekState();
			loadData();
		}

		function openWeekCopyDialog() {
			if (!weekCopyModal) return;
			if (weekCopySourceInput) {
				setWeekInputDisplay(weekCopySourceInput, currentWeekStart);
				if (
					weekCopySourceWidget &&
					typeof weekCopySourceWidget.update === "function"
				) {
					weekCopySourceWidget.update(currentWeekStart);
				}
			}
			if (weekCopyTargetInput) {
				setWeekInputDisplay(weekCopyTargetInput, currentWeekStart);
				if (
					weekCopyTargetWidget &&
					typeof weekCopyTargetWidget.update === "function"
				) {
					weekCopyTargetWidget.update(currentWeekStart);
				}
			}
			const mergeModeInput = weekCopyModeInputs.find(
				(input) => input && String(input.value).toLowerCase() === "merge"
			);
			if (mergeModeInput) {
				mergeModeInput.checked = true;
			}
			updateWeekCopyModeUI();
			weekCopyModal.classList.add("open");
			weekCopyModal.style.display = "flex";
			weekCopyModal.setAttribute("aria-hidden", "false");
		}

		function closeWeekCopyDialog() {
			if (!weekCopyModal) return;
			weekCopyModal.classList.remove("open");
			weekCopyModal.style.display = "none";
			weekCopyModal.setAttribute("aria-hidden", "true");
			if (weekCopyConfirmBtn) {
				weekCopyConfirmBtn.disabled = false;
			}
			isCopyingWeek = false;
		}

		function formatWeekCopyConflicts(conflicts) {
			if (!Array.isArray(conflicts) || !conflicts.length) return "";
			const header = getShiftLabel(
				"copyWeekConflictHeader",
				"Conflitos encontrados:"
			);
			const typeLabels = {
				source_overlap: getShiftLabel(
					"copyWeekConflictSourceOverlap",
					"Origem: turno e folga no mesmo dia"
				),
				target_shift: getShiftLabel(
					"copyWeekConflictTargetShift",
					"Destino já tem turno"
				),
				target_dayoff: getShiftLabel(
					"copyWeekConflictTargetDayOff",
					"Destino já tem folga"
				),
				target_leave: getShiftLabel(
					"copyWeekConflictTargetLeave",
					"Destino já tem ausência"
				),
			};
			const lines = conflicts.map((conflict) => {
				const empId = conflict && conflict.employee_id ? conflict.employee_id : "";
				const emp = empId && employeeMap ? employeeMap[empId] : null;
				const empLabel = emp && emp.title ? emp.title : empId ? `ID ${empId}` : "";
				const dateLabel = conflict && conflict.date ? conflict.date : "";
				const typeLabel = typeLabels[conflict.type] || conflict.type || "";
				const base = empLabel || empId ? `${empLabel || "ID " + empId}` : "";
				if (base && dateLabel) {
					return `${base} - ${dateLabel}: ${typeLabel}`;
				}
				if (dateLabel) {
					return `${dateLabel}: ${typeLabel}`;
				}
				return typeLabel;
			});
			return [header].concat(lines).join("\n");
		}

		function formatWeekCopySkippedSummary(result) {
			const skippedTotal = Number(result && result.skipped ? result.skipped : 0);
			if (!Number.isFinite(skippedTotal) || skippedTotal <= 0) return "";
			const base = tLabel(
				"copyWeekSkippedInfo",
				"Alguns registos não foram copiados devido a conflitos."
			);
			const byType =
				result &&
				result.skipped_conflicts &&
				typeof result.skipped_conflicts === "object"
					? result.skipped_conflicts
					: null;
			if (!byType) {
				return `${base} (${skippedTotal})`;
			}
			const typeLabels = {
				source_overlap: getShiftLabel(
					"copyWeekConflictSourceOverlap",
					"Origem: turno e folga no mesmo dia"
				),
				target_shift: getShiftLabel(
					"copyWeekConflictTargetShift",
					"Destino já tem turno"
				),
				target_dayoff: getShiftLabel(
					"copyWeekConflictTargetDayOff",
					"Destino já tem folga"
				),
				target_leave: getShiftLabel(
					"copyWeekConflictTargetLeave",
					"Destino já tem ausência"
				),
			};
			const details = [];
			Object.keys(typeLabels).forEach((type) => {
				const count = Number(byType[type] || 0);
				if (Number.isFinite(count) && count > 0) {
					details.push(`- ${typeLabels[type]}: ${count}`);
				}
			});
			if (!details.length) {
				return `${base} (${skippedTotal})`;
			}
			return `${base} (${skippedTotal})\n${details.join("\n")}`;
		}

		async function handleWeekCopyConfirm() {
			if (!weekCopySourceInput || !weekCopyTargetInput || !weekCopyConfirmBtn)
				return;
			const sourceDate = getWeekInputDateValue(weekCopySourceInput);
			const targetDate = getWeekInputDateValue(weekCopyTargetInput);
			if (!sourceDate || !targetDate) {
				alert("Selecione semanas válidas.");
				return;
			}
			const sourceIso = formatDateISO(sourceDate);
			const targetIso = formatDateISO(targetDate);
			if (!sourceIso || !targetIso) {
				alert("Não foi possível identificar as semanas.");
				return;
			}
			if (sourceIso === targetIso) {
				alert("A semana de origem e destino precisam ser diferentes.");
				return;
			}
			const sourceLabel = formatDetailedWeekRange(sourceDate);
			const targetLabel = formatDetailedWeekRange(targetDate);
			const copyMode = getSelectedWeekCopyMode();
			let confirmMessage = `Copiar ${sourceLabel} para ${targetLabel}?`;
			if (copyMode === "replace") {
				confirmMessage += `\n\n${replaceModeWarningText}`;
			}
			if (!window.confirm(confirmMessage)) return;
			if (isCopyingWeek) return;
			isCopyingWeek = true;
			weekCopyConfirmBtn.disabled = true;
			try {
				const resp = await fetch(apiUrl, {
					method: "POST",
					headers: { "Content-Type": "application/x-www-form-urlencoded" },
					body: new URLSearchParams({
						action: "copy_week",
						source_week: sourceIso,
						target_week: targetIso,
						copy_mode: copyMode,
					}),
				});
				const result = await resp.json();
				if (!resp.ok || !result.success) {
					const conflictMessage = formatWeekCopyConflicts(result.conflicts);
					if (conflictMessage) {
						throw new Error(conflictMessage);
					}
					throw new Error(
						translateApiError(result.error, "Falha ao copiar semana.")
					);
				}
				closeWeekCopyDialog();
				loadData();
				const skippedMessage = formatWeekCopySkippedSummary(result);
				if (skippedMessage) {
					alert(skippedMessage);
				}
			} catch (error) {
				alert(error.message || "Falha ao copiar semana.");
			} finally {
				isCopyingWeek = false;
				if (weekCopyConfirmBtn) weekCopyConfirmBtn.disabled = false;
			}
		}

		function openPrintWindow(view) {
			const weekIso = formatDateISO(currentWeekStart);
			const url = `${printUrl}?view=${encodeURIComponent(
				view
			)}&week=${encodeURIComponent(weekIso)}`;

			// Create a temporary anchor element and click it to open in new tab
			// This ensures proper cookie handling compared to window.open()
			const anchor = document.createElement("a");
			anchor.href = url;
			anchor.target = "_blank";
			anchor.rel = "noopener noreferrer";
			anchor.style.display = "none";
			document.body.appendChild(anchor);
			anchor.click();
			// Clean up after a short delay
			setTimeout(() => document.body.removeChild(anchor), 100);
		}

		function recalculateWeeklyMinutes() {
			weeklyMinutesByEmployee = {};
			weeklyMinutesByEmployeeNormal = {};
			if (!Array.isArray(shifts)) return;
			shifts.forEach((shift) => {
				const empIdRaw =
					shift.empId ||
					shift.resourceId ||
					shift.fk_user ||
					getShiftEmployeeKey(shift);
				const empKey =
					empIdRaw !== undefined && empIdRaw !== null ? String(empIdRaw) : "";
				if (!empKey) return;
				const minutes = getShiftDurationMinutes(shift.start, shift.end);
				if (minutes <= 0) return;
				const stype =
					shift.shift_type !== undefined && shift.shift_type !== null
						? Number(shift.shift_type)
						: shift.shiftType !== undefined && shift.shiftType !== null
						? Number(shift.shiftType)
						: 1;
				weeklyMinutesByEmployee[empKey] =
					(weeklyMinutesByEmployee[empKey] || 0) + minutes;
				if (stype === 1) {
					weeklyMinutesByEmployeeNormal[empKey] =
						(weeklyMinutesByEmployeeNormal[empKey] || 0) + minutes;
				}
			});
			if (typeof console !== "undefined" && console.log) {
				console.log(
					"[KreaFlow] Weekly minutes (all)",
					weeklyMinutesByEmployee
				);
				console.log(
					"[KreaFlow] Weekly minutes (type 1 only)",
					weeklyMinutesByEmployeeNormal
				);
			}
		}
		if (typeof window !== "undefined") {
			window.recalculateWeeklyMinutes = recalculateWeeklyMinutes;
		}

		function getEmployeeExpectedHours(empId) {
			let emp = employeeMap[empId];
			if (!emp && empId !== undefined && empId !== null) {
				const altKey = String(empId);
				emp = employeeMap[altKey] || employeeMap[Number(altKey)];
			}
			if (
				!emp ||
				emp.expectedHours === undefined ||
				emp.expectedHours === null
			) {
				return null;
			}
			const value = parseFloat(emp.expectedHours);
			return Number.isFinite(value) && value > 0 ? value : null;
		}

		function isEmployeeOverCapacity(empId) {
			const expected = getEmployeeExpectedHours(empId);
			if (!expected) return false;
			const actualHours = (weeklyMinutesByEmployee[empId] || 0) / 60;
			return actualHours - expected > 0.01;
		}

		function isEmployeeOverCapacityNormal(empId) {
			const key = empId !== undefined && empId !== null ? String(empId) : "";
			const expected =
				getEmployeeExpectedHours(key) ??
				getEmployeeExpectedHours(key !== "" ? Number(key) : key);
			if (!expected) return false;
			const normalMinutes =
				(weeklyMinutesByEmployeeNormal[key] ||
					weeklyMinutesByEmployeeNormal[Number(key)] ||
					0) / 60;
			return normalMinutes - expected > 0.01;
		}

		function parseExpectedHours(value) {
			if (value === null || value === undefined || value === "") return null;
			const num = parseFloat(value);
			if (!Number.isFinite(num) || num <= 0) return null;
			return num;
		}

		function getShiftDurationMinutes(startIso, endIso) {
			const startMs = parseIsoToUtcMs(startIso);
			const endMs = parseIsoToUtcMs(endIso);
			if (startMs === null || endMs === null || endMs <= startMs) return 0;
			return (endMs - startMs) / 60000;
		}

		function parseIsoToUtcMs(value) {
			const match =
				value && value.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
			if (!match) return null;
			const [, year, month, day, hour, minute] = match;
			return Date.UTC(
				parseInt(year, 10),
				parseInt(month, 10) - 1,
				parseInt(day, 10),
				parseInt(hour, 10),
				parseInt(minute, 10)
			);
		}

		function clampGridOffsetMinutes(offsetMinutes, durationMinutes) {
			const clampedOffset = Number.isFinite(offsetMinutes)
				? Math.max(0, offsetMinutes)
				: 0;
			const maxOffset =
				SHIFT_DAY_DURATION_MINUTES > durationMinutes
					? SHIFT_DAY_DURATION_MINUTES - durationMinutes
					: 0;
			return Math.min(clampedOffset, maxOffset);
		}

		function moveShiftToDate(shiftId, targetDateISO, gridOffsetMinutes) {
			const shift = shiftMap.get(String(shiftId));
			if (!shift || !targetDateISO) return;
			const currentDisplayDate = shiftplannerGetDisplayDate(shift.start);
			const startMinutesRaw = parseTime(shift.start);
			const endMinutesRaw = parseTime(shift.end);
			if (startMinutesRaw === null || endMinutesRaw === null) return;
			let normalizedStart = shiftplannerNormalizeMinutes(startMinutesRaw);
			let normalizedEnd = shiftplannerNormalizeMinutes(endMinutesRaw);
			if (normalizedStart === null || normalizedEnd === null) return;
			if (normalizedEnd <= normalizedStart) {
				normalizedEnd += MINUTES_PER_DAY;
			}
			const durationMinutes = normalizedEnd - normalizedStart;
			let targetStartMinutes = normalizedStart;
			if (typeof gridOffsetMinutes === "number") {
				const clampedOffset = clampGridOffsetMinutes(
					gridOffsetMinutes,
					durationMinutes
				);
				targetStartMinutes = SHIFT_DAY_START_MINUTES + clampedOffset;
			} else if (currentDisplayDate !== targetDateISO) {
				targetStartMinutes = normalizedStart;
			} else {
				// Same date and no offset change requested
				return;
			}
			let targetEndMinutes = targetStartMinutes + durationMinutes;
			const newStart = shiftplannerMinutesToIso(
				targetDateISO,
				targetStartMinutes
			);
			const newEnd = shiftplannerMinutesToIso(targetDateISO, targetEndMinutes);
			updateShift(
				shift.id,
				shift.resourceId || shift.fk_user,
				shift.title,
				newStart,
				newEnd,
				shift.fk_task || shift.taskId || "",
				shift.shift_type || shift.shiftType || "1"
			);
		}

		if (weekCopyBtn && weekCopyModal) {
			weekCopyBtn.addEventListener("click", openWeekCopyDialog);
			if (weekCopyCancelBtn)
				weekCopyCancelBtn.addEventListener("click", closeWeekCopyDialog);
			if (weekCopyBackdrop)
				weekCopyBackdrop.addEventListener("click", closeWeekCopyDialog);
			if (weekCopyConfirmBtn)
				weekCopyConfirmBtn.addEventListener("click", handleWeekCopyConfirm);
		}
		if (weekCopyBtn && weekCopyModal) {
			weekCopyBtn.addEventListener("click", openWeekCopyDialog);
			if (weekCopyCancelBtn)
				weekCopyCancelBtn.addEventListener("click", closeWeekCopyDialog);
			if (weekCopyBackdrop)
				weekCopyBackdrop.addEventListener("click", closeWeekCopyDialog);
			if (weekCopyConfirmBtn)
				weekCopyConfirmBtn.addEventListener("click", handleWeekCopyConfirm);
		}
	} else if (isEmployees) {
		("use strict");

		const apiUrl = window.SHIFT_API_URL;
		const canWrite = window.SHIFT_CAN_WRITE;
		const canDelete = window.SHIFT_CAN_DELETE;
		const canManageEmployees = Boolean(window.SHIFT_CAN_MANAGE_EMPLOYEES);
		const canEditEmployeeHours = Boolean(window.SHIFT_CAN_EDIT_EMPLOYEE_HOURS);
		const canAssignMainTask = Boolean(window.SHIFT_CAN_ASSIGN_MAIN_TASK);
		const capNormalToContract = Boolean(window.SHIFT_CAP_NORMAL_TO_CONTRACT);
		const WEEK_STORAGE_KEY = "dolizsynch_shift_week_start";
		const EMPLOYEE_FORCE_STORAGE_KEY = "dolizsynch_week_employee_override";
		const initWeekIso = (window.SHIFT_INIT_WEEK || "").trim();
		const SHIFT_DRAG_TYPE = "text/x-shift";

		// Local reference to shared conflict resolution helper
		const resolveConflictRecords = window.resolveConflictRecords;

		// Public holidays data
		let currentWeekStart = getMonday(new Date());
		const explicitWeekStart = parseIsoWeek(initWeekIso);
		const storedWeekStart = loadStoredWeekStart();
		if (explicitWeekStart) {
			currentWeekStart = explicitWeekStart;
		} else if (storedWeekStart) {
			currentWeekStart = storedWeekStart;
		}
		let employees = [];
		let employeeMap = {};
		let shiftsByEmployee = {};
		let shiftMap = new Map();
		let tasks = [];
		let taskMap = {};
		let weeklyMinutesByEmployee = {};
		let weeklyMinutesByEmployeeNormal = {};
		let dailyMinutesByDate = {};
		let shifts = [];
		let draggedShiftId = null;
		let employeeDayOffs = {};
		let employeeLeaves = {};
		let leaveDateLookup = {};
		let activeLeaveCodes = new Set();
		const leaveById = new Map();
		const EMPLOYEE_SORT_STORAGE_KEY = "dolizsynch_employee_sort_mode";
		const SORT_MODE_NAME = "name";
		const SORT_MODE_TASK = "task";
		let employeeSortMode = loadStoredEmployeeSortMode();
		let weekEmployeeNotes = {};
		let weekNotesRequestToken = 0;
		const leaveTypes = Array.isArray(window.SHIFT_LEAVE_TYPES)
			? window.SHIFT_LEAVE_TYPES
			: [];
		const leaveTypeColorMap =
			(window.SHIFT_COLORS && window.SHIFT_COLORS.leaveTypes) || {};
			const leaveTypeFontMap =
				(window.SHIFT_COLORS && window.SHIFT_COLORS.leaveFonts) || {};
			const leaveFontDefault =
				(window.SHIFT_COLORS && window.SHIFT_COLORS.leaveFontDefault) ||
				"#000000";
			const coerceBadgeFlag = (val, fallback) => {
				if (typeof val === "boolean") return val;
				if (typeof val === "number") return val > 0;
				if (typeof val === "string") {
					const v = val.trim().toLowerCase();
					if (v === "") return fallback;
					if (v === "false" || v === "off" || v === "no") return false;
					if (v === "true" || v === "on" || v === "yes") return true;
					if (/^-?\d+(\.\d+)?$/.test(v)) return Number(v) > 0;
				}
				if (val === null || val === undefined) return fallback;
				return fallback;
			};
			const badgeShowNormal = coerceBadgeFlag(
				window.SHIFT_BADGE_SHOW_NORMAL,
				true
			);
			const badgeShowSpecial = coerceBadgeFlag(
				window.SHIFT_BADGE_SHOW_SPECIAL,
				true
			);
			const employeeWeekNotesEnabled = coerceBadgeFlag(
				window.SHIFT_EMPLOYEE_WEEK_NOTES_ENABLED,
				false
			);
		if (typeof console !== "undefined" && console.log) {
			console.log(
				"[KreaFlow] Badge flags",
				{ badgeShowNormal, badgeShowSpecial },
				"raw:",
				window.SHIFT_BADGE_SHOW_NORMAL,
				window.SHIFT_BADGE_SHOW_SPECIAL
			);
		}
		function recalculateWeeklyMinutes() {
			weeklyMinutesByEmployee = {};
			weeklyMinutesByEmployeeNormal = {};
			if (!Array.isArray(shifts)) return;
			shifts.forEach((shift) => {
				const empIdRaw =
					shift.empId ||
					shift.resourceId ||
					shift.fk_user ||
					getShiftEmployeeKey(shift);
				const empKey =
					empIdRaw !== undefined && empIdRaw !== null ? String(empIdRaw) : "";
				if (!empKey) return;
				const minutes = getShiftDurationMinutes(shift.start, shift.end);
				if (minutes <= 0) return;
				if (isMissedShift(shift)) return;
				const stype =
					shift.shift_type !== undefined && shift.shift_type !== null
						? Number(shift.shift_type)
						: shift.shiftType !== undefined && shift.shiftType !== null
						? Number(shift.shiftType)
						: 1;
				weeklyMinutesByEmployee[empKey] =
					(weeklyMinutesByEmployee[empKey] || 0) + minutes;
				if (stype === 1) {
					weeklyMinutesByEmployeeNormal[empKey] =
						(weeklyMinutesByEmployeeNormal[empKey] || 0) + minutes;
				}
			});
		}
		const normalizeLeaveKey = (key) => {
			if (!key) return "";
			let out = key
				.toString()
				.normalize("NFD")
				.replace(/[\u0300-\u036f]/g, "");
			out = out.replace(/[^A-Za-z0-9]+/g, "_");
			out = out.replace(/_{2,}/g, "_").replace(/^_+|_+$/g, "");
			return out.toUpperCase().trim();
		};
		const leaveCodeMap = new Map();
		if (leaveTypes && leaveTypes.length) {
			leaveTypes.forEach((lt) => {
				if (lt && lt.code) {
					const raw = String(lt.code).trim();
					leaveCodeMap.set(raw, lt.label || lt.code);
					leaveCodeMap.set(normalizeLeaveKey(raw), lt.label || lt.code);
				}
			});
		}
		window.addEventListener("resize", adjustDayTotalsOffset);
			const shiftColors = window.SHIFT_COLORS || {};
			const shiftLabels = window.SHIFT_LABELS || {};
			const fallbackLeaveColor = shiftColors.leave || "#cfcfcf";
			const fallbackFutureColor = shiftColors.future || "#d9d9d9";
			const fallbackMissedColor = shiftColors.missed || "#f8d7da";
			const fallbackMissedFontColor = shiftColors.missedFont || "#7f1d1d";
			const SHIFT_STATUS_SCHEDULED = "scheduled";
			const SHIFT_STATUS_MISSED = "missed";
			const SHIFT_STATUS_MISSED_UNJUSTIFIED = "missed_unjustified";
			const SHIFT_STATUS_MISSED_JUSTIFIED = "missed_justified";

			function normalizeShiftStatusValue(value) {
				const normalized =
					value === undefined || value === null
						? ""
						: String(value).trim().toLowerCase();
				if (normalized === SHIFT_STATUS_MISSED) return SHIFT_STATUS_MISSED;
				if (normalized === SHIFT_STATUS_MISSED_UNJUSTIFIED) {
					return SHIFT_STATUS_MISSED_UNJUSTIFIED;
				}
				if (normalized === SHIFT_STATUS_MISSED_JUSTIFIED) {
					return SHIFT_STATUS_MISSED_JUSTIFIED;
				}
				return SHIFT_STATUS_SCHEDULED;
			}

			function isMissedStatusValue(statusValue) {
				const normalized = normalizeShiftStatusValue(statusValue);
				return (
					normalized === SHIFT_STATUS_MISSED ||
					normalized === SHIFT_STATUS_MISSED_UNJUSTIFIED ||
					normalized === SHIFT_STATUS_MISSED_JUSTIFIED
				);
			}

			function isMissedAbsenceStatus(statusValue) {
				const normalized = normalizeShiftStatusValue(statusValue);
				return (
					normalized === SHIFT_STATUS_MISSED_UNJUSTIFIED ||
					normalized === SHIFT_STATUS_MISSED_JUSTIFIED
				);
			}

			function isMissedShift(shift) {
				if (!shift || typeof shift !== "object") return false;
				const rawStatus =
					shift.shift_status !== undefined && shift.shift_status !== null
						? shift.shift_status
						: shift.shiftStatus !== undefined && shift.shiftStatus !== null
						? shift.shiftStatus
						: SHIFT_STATUS_SCHEDULED;
				return isMissedStatusValue(rawStatus);
			}
			const employeeManagerModal = document.getElementById(
				"employee-manager-modal"
			);
		const employeeManagerList = document.getElementById(
			"employee-manager-list"
		);
		const employeeManagerEmpty = document.getElementById(
			"employee-manager-empty"
		);
		const employeeManagerSearchInput = document.getElementById(
			"employee-manager-search"
		);
		const employeeManagerSaveBtn = document.getElementById(
			"employee-manager-save"
		);
		const employeeManagerCancelBtn = document.getElementById(
			"employee-manager-cancel"
		);
		const employeeManagerFeedback = document.getElementById(
			"employee-manager-feedback"
		);
		const employeeManagerBackdropTriggers = document.querySelectorAll(
			"[data-employee-manager-close]"
		);
		let employeePool = [];
		let employeePoolSelected = new Set();
		let employeePoolLoaded = false;
		let employeePoolLoading = false;
		let employeePoolError = "";
		let employeePoolAssigned = new Set();
		let employeePoolForced = new Set();
		let employeePoolHidden = new Set();

		function handleConflictCellClick(cell) {
			const empId = cell.dataset.emp;
			const dateISO = cell.dataset.date;
			const conflictType = cell.dataset.conflictType || "";
			const entryType = cell.dataset.entryType || "";
			let shiftIds = (cell.dataset.conflictShifts || "")
				.split(",")
				.map((id) => id.trim())
				.filter(Boolean);
			let dayoffId = cell.dataset.conflictDayoffId || "";
			const partialLeaveIds = (cell.dataset.conflictPartialLeaves || "")
				.split(",")
				.map((id) => id.trim())
				.filter(Boolean);
			let resolveMode = "shifts";

			if (conflictType === "dayoff") {
				const isDayoffCell = entryType === "dayoff" || entryType === "lockday";
				if (!shiftIds.length) {
					resolveMode = "dayoff";
				} else if (isDayoffCell) {
					dayoffId = "";
				} else {
					resolveMode = "dayoff";
					shiftIds = [];
				}
			}

			// Check if there's anything to remove (shifts, day-off, or partial leaves)
			if (
				!empId ||
				!dateISO ||
				(!shiftIds.length && !dayoffId && !partialLeaveIds.length)
			) {
				alert(
					tLabel(
						"conflictNoRemovableData",
						"Não existem turnos para remover neste conflito."
					)
				);
				return;
			}
			openConflictResolutionDialog({
				empId,
				dateISO,
				shiftIds,
				dayoffId,
				partialLeaveIds,
				resolveMode,
			});
		}

		function openConflictResolutionDialog({
			empId,
			dateISO,
			shiftIds,
			dayoffId,
			partialLeaveIds,
			resolveMode = "shifts",
		}) {
			const overlay = document.createElement("div");
			overlay.className = "shift-overlay conflict-overlay";
			const dialog = document.createElement("div");
			dialog.className = "shift-dialog conflict-dialog";
			const title = escapeHtml(
				tLabel("conflictDialogTitle", "Conflito detectado")
			);
			const removingDayoff = resolveMode === "dayoff";
			const description = escapeHtml(
				removingDayoff
					? tLabel(
							"conflictDialogDayoffDescription",
							"Existe um conflito entre o turno e a folga. Pretende remover a folga para manter os turnos?"
					  )
					: tLabel(
							"conflictDialogDescription",
							"Existe um conflito entre o turno e a ausência. Pretende remover os turnos deste dia para manter a ausência?"
					  )
			);
			const resolveLabel = escapeHtml(
				removingDayoff
					? tLabel("conflictDialogResolveDayoff", "Remover folga")
					: tLabel("conflictDialogResolve", "Remover turnos")
			);
			const errorLabel = removingDayoff
				? tLabel(
						"conflictDialogDayoffError",
						"Falha ao remover folga em conflito."
				  )
				: tLabel(
						"conflictDialogError",
						"Falha ao remover turnos em conflito."
				  );
			dialog.innerHTML = `
				<h3>${title}</h3>
				<p>${description}</p>
				<div class="conflict-dialog-actions">
					<button type="button" class="btn-secondary conflict-cancel">${escapeHtml(
						tLabel("conflictDialogCancel", "Cancelar")
					)}</button>
					<button type="button" class="btn-primary conflict-resolve">${resolveLabel}</button>
				</div>
			`;
			overlay.appendChild(dialog);
			document.body.appendChild(overlay);

			const cleanup = () => {
				if (overlay.parentNode) {
					overlay.parentNode.removeChild(overlay);
				}
			};

			const cancelBtn = dialog.querySelector(".conflict-cancel");
			if (cancelBtn) {
				cancelBtn.addEventListener("click", cleanup);
			}
			overlay.addEventListener("click", (evt) => {
				if (evt.target === overlay) {
					cleanup();
				}
			});
			const resolveBtn = dialog.querySelector(".conflict-resolve");
			if (resolveBtn) {
				resolveBtn.addEventListener("click", async () => {
					resolveBtn.disabled = true;
					resolveBtn.textContent = tLabel(
						"conflictDialogResolving",
						"A remover..."
					);
					try {
						await resolveConflictRecords({
							shiftIds,
							dayoffId,
							partialLeaveIds,
						});
						cleanup();
						loadData();
					} catch (error) {
						alert(
							error && error.message
								? error.message
								: errorLabel
						);
						resolveBtn.disabled = false;
						resolveBtn.textContent = resolveLabel;
					}
				});
			}
		}

		async function deleteShiftsForConflict(shiftIds) {
			for (const id of shiftIds) {
				try {
					const resp = await fetch(apiUrl, {
						method: "POST",
						headers: { "Content-Type": "application/x-www-form-urlencoded" },
						body: new URLSearchParams({
							action: "delete",
							id: id,
						}),
					});
					const result = await resp.json();
					if (!result.success) {
						throw new Error(
							translateApiError(result.error, "Erro ao eliminar turno")
						);
					}
				} catch (error) {
					console.error("Conflict removal failed for shift", id, error);
					throw error;
				}
			}
		}

		if (employeeManagerCancelBtn) {
			employeeManagerCancelBtn.addEventListener("click", (e) => {
				e.preventDefault();
				closeEmployeeManager();
			});
		}
		if (
			employeeManagerBackdropTriggers &&
			employeeManagerBackdropTriggers.length
		) {
			employeeManagerBackdropTriggers.forEach((trigger) => {
				trigger.addEventListener("click", (e) => {
					e.preventDefault();
					closeEmployeeManager();
				});
			});
		}
		if (employeeManagerSearchInput) {
			employeeManagerSearchInput.addEventListener("input", () => {
				renderEmployeeManagerList();
			});
		}
		if (employeeManagerList) {
			employeeManagerList.addEventListener(
				"change",
				handleEmployeeManagerSelectionChange
			);
			employeeManagerList.addEventListener(
				"click",
				handleEmployeeStatusToggleClick
			);
		}
		if (employeeManagerSaveBtn) {
			employeeManagerSaveBtn.addEventListener(
				"click",
				handleEmployeeManagerSave
			);
		}

		function tLabel(key, fallback = "") {
			if (
				shiftLabels &&
				typeof shiftLabels[key] === "string" &&
				shiftLabels[key].length
			) {
				return shiftLabels[key];
			}
			return fallback;
		}

		function normalizeSearchText(value) {
			if (!value) return "";
			return value
				.toString()
				.normalize("NFD")
				.replace(/[\u0300-\u036f]/g, "")
				.toLowerCase();
		}

			function formatLabelValue(template, value) {
				if (!template) return value;
				if (template.indexOf("%s") !== -1) {
					return template.replace("%s", value);
				}
				if (template.indexOf("__VALUE__") !== -1) {
					return template.replace("__VALUE__", value);
				}
				return `${template} ${value}`;
			}

			function getMissedAbsenceStatusLabel(statusValue) {
				const normalized = normalizeShiftStatusValue(statusValue);
				if (normalized === SHIFT_STATUS_MISSED_UNJUSTIFIED) {
					return tLabel(
						"shiftStatusMissedUnjustified",
						"Unjustified absence"
					);
				}
				if (normalized === SHIFT_STATUS_MISSED_JUSTIFIED) {
					return tLabel("shiftStatusMissedJustified", "Justified absence");
				}
				return tLabel("shiftStatusMissed", "Missed");
			}

			function resolveMissedAbsenceLeaveType(statusValue) {
				const normalized = normalizeShiftStatusValue(statusValue);
				const isUnjustified = normalized === SHIFT_STATUS_MISSED_UNJUSTIFIED;
				const isJustified = normalized === SHIFT_STATUS_MISSED_JUSTIFIED;
				if (!isUnjustified && !isJustified) {
					return null;
				}
				if (!Array.isArray(leaveTypes) || !leaveTypes.length) {
					return null;
				}

				const codeCandidates = isUnjustified
					? ["FALTA_INJUS", "FALTA_INJUSTIFICADA"]
					: ["FALTA_JUS", "FALTA_JUSTIFICADA"];
				const normalizedCodes = new Set(
					codeCandidates.map((code) => normalizeLeaveKey(code))
				);

				for (const type of leaveTypes) {
					if (!type) continue;
					const codeNorm = normalizeLeaveKey(
						type.code || type.code_normalized || ""
					);
					if (codeNorm && normalizedCodes.has(codeNorm)) {
						return type;
					}
				}

				for (const type of leaveTypes) {
					if (!type) continue;
					const labelNorm = normalizeLeaveKey(type.label || "");
					if (!labelNorm) continue;
					if (
						isUnjustified &&
						(labelNorm.includes("FALTA_INJUS") ||
							labelNorm.includes("FALTA_INJUSTIFICADA"))
					) {
						return type;
					}
					if (
						isJustified &&
						!labelNorm.includes("FALTA_INJUS") &&
						(labelNorm.includes("FALTA_JUS") ||
							labelNorm.includes("FALTA_JUSTIFICADA"))
					) {
						return type;
					}
				}

				return null;
			}

			function resolveMissedAbsenceStatusFromCodeOrLabel(codeValue, labelValue) {
				const codeNorm = normalizeLeaveKey(codeValue || "");
				if (codeNorm === "FALTA_INJUS" || codeNorm === "FALTA_INJUSTIFICADA") {
					return SHIFT_STATUS_MISSED_UNJUSTIFIED;
				}
				if (codeNorm === "FALTA_JUS" || codeNorm === "FALTA_JUSTIFICADA") {
					return SHIFT_STATUS_MISSED_JUSTIFIED;
				}

				const labelNorm = normalizeLeaveKey(labelValue || "");
				if (!labelNorm) return "";
				if (
					labelNorm.includes("FALTA_INJUS") ||
					labelNorm.includes("FALTA_INJUSTIFICADA")
				) {
					return SHIFT_STATUS_MISSED_UNJUSTIFIED;
				}
				if (
					!labelNorm.includes("FALTA_INJUS") &&
					(labelNorm.includes("FALTA_JUS") ||
						labelNorm.includes("FALTA_JUSTIFICADA"))
				) {
					return SHIFT_STATUS_MISSED_JUSTIFIED;
				}

				return "";
			}

			function resolveMissedAbsenceStatusFromLeave(leaveInfo) {
				if (!leaveInfo || typeof leaveInfo !== "object") return "";
				const directStatus = resolveMissedAbsenceStatusFromCodeOrLabel(
					leaveInfo.code || leaveInfo.type_code || "",
					leaveInfo.type || leaveInfo.type_label || leaveInfo.label || ""
				);
				if (directStatus) {
					return directStatus;
				}

				const typeIdRaw =
					leaveInfo.type_id !== undefined && leaveInfo.type_id !== null
						? leaveInfo.type_id
						: leaveInfo.fk_type;
				const typeId = typeIdRaw !== undefined && typeIdRaw !== null
					? String(typeIdRaw).trim()
					: "";
				if (!typeId || !Array.isArray(leaveTypes) || !leaveTypes.length) {
					return "";
				}

				const matchedType = leaveTypes.find(
					(type) => type && String(type.id || "").trim() === typeId
				);
				if (!matchedType) {
					return "";
				}

				return resolveMissedAbsenceStatusFromCodeOrLabel(
					matchedType.code || matchedType.code_normalized || "",
					matchedType.label || ""
				);
			}

			function buildMissedAbsenceLeavePayload(
				statusValue,
				empId,
				dateISO,
				startDateTime,
				endDateTime,
				descriptionText = ""
			) {
				const normalizedStatus = normalizeShiftStatusValue(statusValue);
				if (!isMissedAbsenceStatus(normalizedStatus)) {
					return { error: "" };
				}
				const leaveType = resolveMissedAbsenceLeaveType(normalizedStatus);
				if (!leaveType || !leaveType.id) {
					const typeLabel = getMissedAbsenceStatusLabel(normalizedStatus);
					const template = tLabel(
						"missedAbsenceTypeMissing",
						"Leave type for %s is not configured. Create/activate it first in leave types."
					);
					return { error: formatLabelValue(template, typeLabel) };
				}
				const durationMinutes = Math.round(
					getShiftDurationMinutes(startDateTime, endDateTime)
				);
				if (!Number.isFinite(durationMinutes) || durationMinutes <= 0) {
					return {
						error: tLabel(
							"leaveMinutesRequired",
							"Indicate the total absence minutes."
						),
					};
				}

				const startDateIso = (startDateTime || "").slice(0, 10);
				const endDateIso = (endDateTime || "").slice(0, 10);
				const startTime = extractTimeFromISO(startDateTime);
				const endTime = extractTimeFromISO(endDateTime);
				const canUseHoursRange =
					startDateIso &&
					endDateIso &&
					startDateIso === endDateIso &&
					startTime &&
					endTime &&
					compareTimeStrings(endTime, startTime) > 0;

					return {
						payload: {
							user_id: empId,
							leave_type: String(leaveType.id),
							leave_start: startDateIso || dateISO,
							leave_end: startDateIso || dateISO,
							leave_halfday: canUseHoursRange ? "hours_range" : "minutes_total",
							leave_description: String(descriptionText || "").trim(),
							leave_hours_start: canUseHoursRange ? startTime : "",
							leave_hours_end: canUseHoursRange ? endTime : "",
							leave_minutes_total: canUseHoursRange ? "" : String(durationMinutes),
						},
				};
			}

			function confirmMissedAbsenceConversion(statusValue) {
				const statusLabel = getMissedAbsenceStatusLabel(statusValue);
				const template = tLabel(
					"confirmMissedAbsenceConversion",
					"Convert this shift to %s? The shift will be replaced by an absence."
				);
				return confirm(formatLabelValue(template, statusLabel));
			}

			function confirmReconvertToShift(statusValue) {
				const statusLabel = getMissedAbsenceStatusLabel(statusValue);
				const template = tLabel(
					"confirmReconvertToShift",
					"Reconvert this %s to a shift? The absence will be replaced by a shift."
				);
				return confirm(formatLabelValue(template, statusLabel));
			}

			function isoToDateObj(isoString) {
				if (!isoString) return null;
				const date = new Date(`${isoString}T00:00:00`);
				return Number.isNaN(date.getTime()) ? null : date;
			}

			function resolveLeaveId(leaveValues) {
				if (!leaveValues || typeof leaveValues !== "object") return null;
				const candidates = [
					leaveValues.id,
					leaveValues.rowid,
					leaveValues.leave_id,
					leaveValues.fk_leave,
					leaveValues.leaveid,
					leaveValues.id_leave,
					leaveValues.fk_conge,
				];
				for (const val of candidates) {
					if (val === undefined || val === null) continue;
					const str = String(val).trim();
					if (str) return str;
				}
				return null;
			}

			function resolveLeaveCardUrl(leaveValues) {
				if (!leaveValues) return null;
				const id = resolveLeaveId(leaveValues);
				const baseUrl =
					(window.DOL_MAIN_URL_ROOT || "") +
					"/custom/kreaflow/leaves/card.php";
				if (id) return `${baseUrl}?id=${encodeURIComponent(id)}`;
				if (leaveValues.card_url) return leaveValues.card_url;
				return null;
			}

		function getEmploymentDateISO(empId) {
			const emp = employeeMap[empId];
			return emp && emp.employmentDate ? emp.employmentDate : "";
		}

		function getEmploymentEndDateISO(empId) {
			const emp = employeeMap[empId];
			return emp && emp.employmentEndDate ? emp.employmentEndDate : "";
		}

		function isBeforeEmployment(empId, dateISO) {
			const employmentIso = getEmploymentDateISO(empId);
			if (!employmentIso || !dateISO) return false;
			return dateISO < employmentIso;
		}

		function isDayBeforeEmployment(empId, dateISO) {
			const employmentIso = getEmploymentDateISO(empId);
			if (!employmentIso || !dateISO) return false;
			const employmentDate = isoToDateObj(employmentIso);
			const currentDate = isoToDateObj(dateISO);
			if (!employmentDate || !currentDate) return false;
			const diff = (employmentDate - currentDate) / (1000 * 60 * 60 * 24);
			return diff === 1;
		}

		function isAfterEmploymentEnd(empId, dateISO) {
			const employmentEndIso = getEmploymentEndDateISO(empId);
			if (!employmentEndIso || !dateISO) return false;
			return dateISO > employmentEndIso;
		}

		function isDayAfterEmploymentEnd(empId, dateISO) {
			const employmentEndIso = getEmploymentEndDateISO(empId);
			if (!employmentEndIso || !dateISO) return false;
			const endDate = isoToDateObj(employmentEndIso);
			const currentDate = isoToDateObj(dateISO);
			if (!endDate || !currentDate) return false;
			const diff = (currentDate - endDate) / (1000 * 60 * 60 * 24);
			return diff === 1;
		}

		// Initialize week controls
		document
			.getElementById("btn-prev-week")
			.addEventListener("click", () => changeWeek(-1));
		document
			.getElementById("btn-next-week")
			.addEventListener("click", () => changeWeek(1));
		const btnToday = document.getElementById("btn-today");
		if (btnToday) {
			btnToday.addEventListener("click", () => goToToday());
		}
		const weekPicker = document.getElementById("week-picker");
		const weekPickerTrigger = document.getElementById("week-picker-trigger");
		let weekPickerWidget = null;
		const weekRangeLabel = document.getElementById("week-range");
		if (weekNotesSaveBtn && weekNotesTextarea) {
			weekNotesSaveBtn.addEventListener("click", (e) => {
				e.preventDefault();
				const weekIso = formatDateISO(currentWeekStart);
				weekNotesSaveBtn.disabled = true;
				saveWeekNotesToServer(weekIso, weekNotesTextarea.value).finally(() => {
					weekNotesSaveBtn.disabled = false;
				});
			});
		}
		if (weekPicker) {
			weekPickerWidget = createWeekPicker(weekPicker, {
				language: window.SHIFT_WEEK_PICKER_LANG,
				thisWeekLabel: window.SHIFT_WEEK_PICKER_THIS_WEEK || "This week",
				onSelect: (selectedDate) => {
					currentWeekStart = getMonday(selectedDate);
					syncWeekState();
					loadData();
				},
			});
			if (!weekPickerWidget) {
				weekPicker.addEventListener("change", handleWeekPickerChange);
			}
			weekPicker.classList.add("with-range-text");
			if (weekPickerTrigger) {
				weekPickerTrigger.addEventListener("click", (e) => {
					e.preventDefault();
					if (weekPickerWidget) {
						window.jQuery(weekPicker).datepicker("show");
					} else if (typeof weekPicker.showPicker === "function") {
						weekPicker.showPicker();
					} else {
						weekPicker.focus();
					}
				});
			}
		}
		const weekCopyBtn = document.getElementById("btn-copy-week");
		const weekPrintBtn = document.getElementById("btn-print-week");
		const weekCopyModal = document.getElementById("week-copy-modal");
		const weekCopySourceInput = document.getElementById("week-copy-source");
		const weekCopyTargetInput = document.getElementById("week-copy-target");
		const weekCopySourceTrigger = document.getElementById(
			"week-copy-source-trigger"
		);
		const weekCopyTargetTrigger = document.getElementById(
			"week-copy-target-trigger"
		);
		const weekCopyConfirmBtn = document.getElementById("week-copy-confirm");
		const weekCopyCancelBtn = document.getElementById("week-copy-cancel");
		const weekCopyBackdrop = document.querySelector("[data-week-copy-close]");
		const weekCopyModeInputs = Array.from(
			document.querySelectorAll('input[name="week-copy-mode"]')
		);
		const weekCopyReplaceWarning = document.getElementById(
			"week-copy-replace-warning"
		);
		const replaceModeWarningText =
			"Replace mode deletes existing shifts/dayoffs in target week before copying.";
		const getSelectedWeekCopyMode = () => {
			if (!weekCopyModeInputs.length) return "merge";
			const selected = weekCopyModeInputs.find((input) => input && input.checked);
			const value =
				selected && selected.value
					? String(selected.value).toLowerCase()
					: "merge";
			return value === "replace" ? "replace" : "merge";
		};
		const updateWeekCopyModeUI = () => {
			if (!weekCopyReplaceWarning) return;
			weekCopyReplaceWarning.style.display =
				getSelectedWeekCopyMode() === "replace" ? "block" : "none";
		};
		weekCopyModeInputs.forEach((input) => {
			input.addEventListener("change", updateWeekCopyModeUI);
		});
		let weekCopySourceWidget = null;
		let weekCopyTargetWidget = null;
		const quotaModal = document.getElementById("quota-modal");
		const quotaNameEl = document.getElementById("quota-emp-name");
		const quotaEmpTaskEl = document.getElementById("quota-emp-task");
		const quotaInput = document.getElementById("quota-input");
		const quotaSaveBtn = document.getElementById("quota-save");
		const quotaCancelBtn = document.getElementById("quota-cancel");
		const quotaBackdrop = document.querySelector("[data-quota-close]");
		const quotaTaskSelect = document.getElementById("quota-task-select");
		const quotaTaskReadonly = document.getElementById("quota-task-readonly");
		const quotaHoursField = document.getElementById("quota-hours-field");
		const quotaTaskField = document.getElementById("quota-task-field");
		const printUrl =
			window.SCHEDULE_PRINT_URL || "/custom/kreaflow/schedule/schedule_print.php";
		let isCopyingWeek = false;
		let isCopyingEmployeeWeek = false;
		let quotaTargetEmpId = null;
		let quotaTaskOptionsReady = false;
		const switchLink = document.querySelector(".btn-switch");
		if (switchLink && !switchLink.dataset.baseHref) {
			switchLink.dataset.baseHref = switchLink.href;
		}
		const legendManageBtn = document.getElementById("legend-manage-tasks");
		if (legendManageBtn) {
			legendManageBtn.addEventListener("click", () => {
				openTaskManager(() => {
					renderLegend();
				});
			});
		}

		if (weekCopySourceInput) {
			const handleSourceSelect = (date) => {
				const monday = getMonday(date);
				if (monday) {
					setWeekInputDisplay(weekCopySourceInput, monday);
				}
			};
			weekCopySourceWidget = createWeekPicker(weekCopySourceInput, {
				language: window.SHIFT_WEEK_PICKER_LANG,
				thisWeekLabel: window.SHIFT_WEEK_PICKER_THIS_WEEK || "This week",
				onSelect: handleSourceSelect,
			});
			weekCopySourceInput.addEventListener("change", () => {
				const parsed = getWeekInputDateValue(weekCopySourceInput);
				if (parsed) {
					setWeekInputDisplay(weekCopySourceInput, parsed);
				}
			});
			const openSourcePicker = (e) => {
				e.preventDefault();
				if (weekCopySourceWidget && weekCopySourceWidget.show) {
					weekCopySourceWidget.show();
				} else if (
					weekCopySourceInput.showPicker &&
					typeof weekCopySourceInput.showPicker === "function"
				) {
					weekCopySourceInput.showPicker();
				} else {
					weekCopySourceInput.focus();
				}
			};
			if (weekCopySourceTrigger) {
				weekCopySourceTrigger.addEventListener("click", openSourcePicker);
			}
		}

		if (weekCopyTargetInput) {
			const handleTargetSelect = (date) => {
				const monday = getMonday(date);
				if (monday) {
					setWeekInputDisplay(weekCopyTargetInput, monday);
				}
			};
			weekCopyTargetWidget = createWeekPicker(weekCopyTargetInput, {
				language: window.SHIFT_WEEK_PICKER_LANG,
				thisWeekLabel: window.SHIFT_WEEK_PICKER_THIS_WEEK || "This week",
				onSelect: handleTargetSelect,
			});
			weekCopyTargetInput.addEventListener("change", () => {
				const parsed = getWeekInputDateValue(weekCopyTargetInput);
				if (parsed) {
					setWeekInputDisplay(weekCopyTargetInput, parsed);
				}
			});
			const openTargetPicker = (e) => {
				e.preventDefault();
				if (weekCopyTargetWidget && weekCopyTargetWidget.show) {
					weekCopyTargetWidget.show();
				} else if (
					weekCopyTargetInput.showPicker &&
					typeof weekCopyTargetInput.showPicker === "function"
				) {
					weekCopyTargetInput.showPicker();
				} else {
					weekCopyTargetInput.focus();
				}
			};
			if (weekCopyTargetTrigger) {
				weekCopyTargetTrigger.addEventListener("click", openTargetPicker);
			}
			}
			if (quotaSaveBtn) quotaSaveBtn.addEventListener("click", handleQuotaSave);
			if (quotaCancelBtn)
				quotaCancelBtn.addEventListener("click", closeQuotaModal);
			if (quotaBackdrop) quotaBackdrop.addEventListener("click", closeQuotaModal);

			syncWeekState();
			// Run initial load after all employee-view helpers are initialized.
			setTimeout(() => {
				loadData();
			}, 0);

		function formatTimeRange(startIso, endIso) {
			const start = startIso ? startIso.slice(11, 16) : "";
			const end = endIso ? endIso.slice(11, 16) : "";
			if (start && end) {
				return `${start} - ${end}`;
			}
			return start || end || "";
		}

		function getTaskColor(taskId, fallbackColor = "") {
			const task = taskId ? taskMap[taskId] : null;
			return (task && task.color) || fallbackColor || fallbackLeaveColor || "#3788d8";
		}

		function getTaskTextColor(taskId) {
			const task = taskId ? taskMap[taskId] : null;
			return (task && task.textColor) || DEFAULT_TASK_TEXT_COLOR;
		}

		function colorWithAlpha(hex, alpha) {
			if (!hex) {
				return `rgba(55,136,216,${alpha})`;
			}
			let value = hex.replace("#", "");
			if (value.length === 3) {
				value = value
					.split("")
					.map((ch) => ch + ch)
					.join("");
			}
			const r = parseInt(value.substring(0, 2), 16);
			const g = parseInt(value.substring(2, 4), 16);
			const b = parseInt(value.substring(4, 6), 16);
			if ([r, g, b].some((n) => Number.isNaN(n))) {
				return `rgba(55,136,216,${alpha})`;
			}
			return `rgba(${r}, ${g}, ${b}, ${alpha})`;
		}

		function renderLegend() {
			const tasksContainer = document.getElementById("legend-tasks");
			const employeesContainer = document.getElementById("legend-employees");
			if (!tasksContainer) return;
			const items = tasks
				.map((task) => {
					const color = task.color || "#3788d8";
					return `<span class="legend-task"><span class="legend-task-color" style="background:${color}"></span>${escapeHtml(
						task.label
					)}</span>`;
				})
				.join(" ");
			const label = tasks.length ? items : "Sem tarefas definidas";
			tasksContainer.innerHTML = `<span class="legend-group legend-group-tasks">${label}</span>`;
			if (employeesContainer) {
				const employeesHtml = buildEmployeeLegend();
				employeesContainer.innerHTML = employeesHtml || "Sem funcionários";
			}
		}

		function populateMainTaskSelect() {
			if (!quotaTaskSelect) return;
			quotaTaskOptionsReady = false;
			const placeholder = escapeHtml(
				tLabel("mainTaskPlaceholder", "Selecionar tarefa")
			);
			const options = [`<option value="">${placeholder}</option>`];
			const sortedTasks = tasks.slice().sort((a, b) =>
				(a.label || "").localeCompare(b.label || "", undefined, {
					sensitivity: "base",
				})
			);
			sortedTasks.forEach((task) => {
				const label = escapeHtml(task.label || `#${task.id || ""}`);
				options.push(`<option value="${task.id}">${label}</option>`);
			});
			quotaTaskSelect.innerHTML = options.join("");
			quotaTaskOptionsReady = true;
		}

		function buildEmployeeLegend() {
			const items = employees
				.map((emp) => {
					const initials = getInitials(emp.title);
					return `<strong>${initials}</strong>=${escapeHtml(emp.title)}`;
				})
				.join(" &nbsp;|&nbsp; ");

			return items;
		}

		function populateTaskSelect(selectEl, selectedTaskId, fallbackEmployeeId) {
			if (!selectEl) return;
			let options = '<option value="">Sem tarefa</option>';
			tasks.forEach((task) => {
				const selected =
					selectedTaskId && String(task.id) === String(selectedTaskId)
						? " selected"
						: "";
				options += `<option value="${task.id}"${selected}>${escapeHtml(
					task.label
				)}</option>`;
			});
			selectEl.innerHTML = options;
			let effectiveTaskId = selectedTaskId;
			if (!effectiveTaskId && fallbackEmployeeId) {
				const emp = employeeMap[fallbackEmployeeId];
				if (emp) {
					const raw =
						emp.mainTaskId !== undefined && emp.mainTaskId !== null
							? emp.mainTaskId
							: emp.main_task_id;
					if (raw !== undefined && raw !== null) {
						const normalized = String(raw).trim();
						if (normalized && normalized !== "0") {
							effectiveTaskId = normalized;
						}
					}
				}
			}
			if (effectiveTaskId) {
				selectEl.value = String(effectiveTaskId);
			}
		}

		function formatLeaveCodeDisplay(code, fallback) {
			const base =
				(code && String(code).trim()) ||
				(fallback && String(fallback).trim()) ||
				"";
			if (!base) return "";
			const hasUnderscore = base.includes("_");
			const pieces = (
				hasUnderscore ? base.split("_") : base.split(/\s+/)
			).filter(Boolean);
			if (!pieces.length) {
				return base;
			}
			const formatted = pieces.map((part) => {
				const lower = part.toLowerCase();
				return lower.charAt(0).toUpperCase() + lower.slice(1);
			});
			return formatted.join(hasUnderscore ? "_" : " ");
		}

		function renderLeaveLegend() {
			const container = document.getElementById("leave-code-legend");
			if (!container) return;
			if (!Array.isArray(leaveTypes) || !leaveTypes.length) {
				container.style.display = "none";
				container.innerHTML = "";
				return;
			}
			const pills = leaveTypes
				.filter(
					(type) =>
						type &&
						typeof type.code === "string" &&
						type.code.trim() !== "" &&
						activeLeaveCodes.has(type.code.trim().toUpperCase())
				)
				.map((type) => {
					const formattedCode = formatLeaveCodeDisplay(
						type.code,
						type.label || ""
					);
					return `<span class="leave-pill"><span class="leave-pill-code">${escapeHtml(
						formattedCode
					)}</span><span class="leave-pill-label">${escapeHtml(
						type.label || ""
					)}</span></span>`;
				})
				.join("");
			if (!pills) {
				container.style.display = "none";
				container.innerHTML = "";
				return;
			}
			const title = escapeHtml(
				tLabel("leaveLegendTitle", "Legenda de ausências")
			);
			container.innerHTML = `<div class="leave-legend-title">${title}</div><div class="legend-row legend-row-leaves">${pills}</div>`;
			container.style.display = "flex";
		}
		renderLeaveLegend();

		function buildLeaveDateLookup(source) {
			const map = {};
			if (!source || typeof source !== "object") {
				return map;
			}
			Object.keys(source).forEach((empKey) => {
				const entries = source[empKey];
				if (!Array.isArray(entries)) return;
				if (!map[empKey]) {
					map[empKey] = {};
				}
				const empMap = map[empKey];
				entries.forEach((entry) => {
					if (!entry) return;
					const dates = expandLeaveDates(entry);
					dates.forEach((dateISO) => {
						if (!empMap[dateISO]) {
							empMap[dateISO] = [];
						}
						const clone = Object.assign({}, entry, { day_iso: dateISO });
						empMap[dateISO].push(clone);
					});
				});
			});
			return map;
		}

		function collectLeaveCodesFromLookup(lookup) {
			const codes = new Set();
			if (!lookup || typeof lookup !== "object") {
				return codes;
			}
			Object.keys(lookup).forEach((empKey) => {
				const byDate = lookup[empKey];
				if (!byDate || typeof byDate !== "object") return;
				Object.keys(byDate).forEach((dateKey) => {
					const entries = byDate[dateKey];
					if (!entries) return;
					const list = Array.isArray(entries) ? entries : [entries];
					list.forEach((entry) => {
						if (!entry || !entry.code) return;
						codes.add(String(entry.code).trim().toUpperCase());
					});
				});
			});
			return codes;
		}

		function expandLeaveDates(entry) {
			const dates = [];
			const startIso = normalizeIsoDate(
				entry.start_iso,
				entry.start_ts || entry.start
			);
			const endIso =
				normalizeIsoDate(entry.end_iso, entry.end_ts || entry.end) || startIso;
			if (!startIso) {
				return dates;
			}
			const startDate = parseIsoDate(startIso);
			const endDate = parseIsoDate(endIso) || startDate;
			if (!startDate) {
				return dates;
			}
			let cursor = new Date(startDate);
			while (cursor <= endDate) {
				dates.push(formatDateISO(cursor));
				cursor.setDate(cursor.getDate() + 1);
			}
			return dates;
		}

		function normalizeIsoDate(value, fallbackTs) {
			if (
				value &&
				typeof value === "string" &&
				/^\d{4}-\d{2}-\d{2}$/.test(value.trim())
			) {
				return value.trim();
			}
			if (fallbackTs !== undefined && fallbackTs !== null) {
				const numeric = Number(fallbackTs);
				if (Number.isFinite(numeric)) {
					const dateObj = new Date(numeric * 1000);
					if (!Number.isNaN(dateObj.getTime())) {
						return dateObj.toISOString().slice(0, 10);
					}
				}
			}
			return "";
		}

		function parseIsoDate(isoString) {
			if (!isoString || !/^\d{4}-\d{2}-\d{2}$/.test(isoString)) return null;
			const date = new Date(`${isoString}T00:00:00`);
			return Number.isNaN(date.getTime()) ? null : date;
		}

		function isEmployeeActiveInWeek(emp, weekStartDate, weekEndDate) {
			if (!emp) return false;
			if (
				!(weekStartDate instanceof Date) ||
				Number.isNaN(weekStartDate.getTime())
			) {
				return true;
			}
			if (
				!(weekEndDate instanceof Date) ||
				Number.isNaN(weekEndDate.getTime())
			) {
				return true;
			}
			const startIso = emp.employmentDate || emp.dateEmployment || "";
			const endIso = emp.employmentEndDate || emp.dateEmploymentEnd || "";
			const startDate = parseIsoDate(startIso);
			const endDate = parseIsoDate(endIso);
			if (!startDate && !endDate) return true;
			if (startDate && weekEndDate < startDate) return false;
			if (endDate && weekStartDate > endDate) return false;
			return true;
		}

		function getDayName(dayIndex) {
			const days = ["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb"];
			return days[dayIndex];
		}

		function getInitials(name) {
			const parts = String(name || "")
				.trim()
				.split(/\s+/)
				.filter(Boolean);
			if (!parts.length) return "";
			if (parts.length === 1) {
				return parts[0].slice(0, 2).toUpperCase();
			}
			return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
		}

		function getShortName(name) {
			const parts = String(name || "")
				.trim()
				.split(/\s+/)
				.filter(Boolean);
			if (!parts.length) return "";
			if (parts.length === 1) return parts[0];
			return `${parts[0]} ${parts[parts.length - 1]}`;
		}

		function changeWeek(direction) {
			currentWeekStart.setDate(currentWeekStart.getDate() + direction * 7);
			syncWeekState();
			loadData();
		}

		function goToToday() {
			currentWeekStart = getMonday(new Date());
			syncWeekState();
			loadData();
		}

		function updateWeekDisplay() {
			const endDate = new Date(currentWeekStart);
			endDate.setDate(endDate.getDate() + 6);

			const rangeText = formatDetailedWeekRange(currentWeekStart);
			if (weekRangeLabel) {
				weekRangeLabel.textContent = rangeText;
			}
			if (weekPicker) {
				weekPicker.setAttribute("title", rangeText);
				weekPicker.setAttribute(
					"aria-label",
					`Selecionar semana (${rangeText})`
				);
			}
			updateWeekPicker();
			updateWeekNotesUI();
		}

		async function loadData() {
			try {
				updateWeekDisplay();
				updateSwitchLink();
				updateWeekNotesUI();

				await fetchTasks();
				renderLegend();
				populateMainTaskSelect();

				const weekIso = formatDateISO(currentWeekStart);
				const overrides = await fetchWeekEmployeeOverrides(weekIso);
				const forcedIds = overrides.forced;
				const hiddenIds = overrides.hidden;
				const resourceStart = formatDateISO(currentWeekStart);
				const resourceEndDate = new Date(currentWeekStart);
				resourceEndDate.setDate(resourceEndDate.getDate() + 6);
				const resourceEnd = formatDateISO(resourceEndDate);
				const resourceParams = new URLSearchParams({
					action: "resources",
					start: resourceStart,
					end: resourceEnd,
				});
				if (forcedIds.length) {
					resourceParams.set("force_user_ids", forcedIds.join(","));
				}
				if (hiddenIds.length) {
					resourceParams.set("hide_user_ids", hiddenIds.join(","));
				}
				const employeesResp = await fetch(apiUrl + "?" + resourceParams);
				const employeesPayload = await employeesResp.json();
				if (!Array.isArray(employeesPayload)) {
					console.warn(
						"Employee payload is not an array; defaulting to empty list",
						employeesPayload
					);
					employees = [];
				} else {
					employees = employeesPayload;
				}
				const hiddenSet = new Set(hiddenIds.map(String));
				if (hiddenSet.size) {
					employees = employees.filter((emp) => {
						const empId = emp ? emp.id ?? emp.rowid ?? emp.fk_user : null;
						const empKey = normalizeEmployeeKey(empId);
						if (empKey === null) return true;
						return !hiddenSet.has(empKey);
					});
				}

				employees.forEach((emp) => {
					const normalizedExpected = parseExpectedHours(emp.expectedHours);
					if (normalizedExpected !== null) {
						emp.expectedHours = normalizedExpected;
					} else {
						delete emp.expectedHours;
					}
					if (emp.mainTaskId === undefined && emp.main_task_id !== undefined) {
						emp.mainTaskId = normalizeMainTaskId(emp.main_task_id);
					} else {
						emp.mainTaskId = normalizeMainTaskId(emp.mainTaskId);
					}
					const employmentIso = emp.dateEmployment || emp.employmentDate || "";
					if (employmentIso) {
						emp.employmentDate = String(employmentIso).slice(0, 10);
					} else {
						delete emp.employmentDate;
					}
					const employmentEndIso =
						emp.dateEmploymentEnd || emp.employmentEndDate || "";
					if (employmentEndIso) {
						emp.employmentEndDate = String(employmentEndIso).slice(0, 10);
					} else {
						delete emp.employmentEndDate;
					}
				});

				const filterWeekStart = new Date(currentWeekStart);
				filterWeekStart.setHours(0, 0, 0, 0);
				const filterWeekEnd = new Date(filterWeekStart);
				filterWeekEnd.setDate(filterWeekEnd.getDate() + 6);
				employees = employees.filter((emp) =>
					isEmployeeActiveInWeek(emp, filterWeekStart, filterWeekEnd)
				);

				// Sort employees by name for consistent row ordering
				employees.sort(compareEmployeeTitles);

				employeeMap = {};
				employees.forEach((emp) => {
					const empKey = normalizeEmployeeKey(emp.id);
					if (empKey !== null) {
						employeeMap[empKey] = emp;
						const numericKey = Number(empKey);
						if (!Number.isNaN(numericKey)) {
							employeeMap[numericKey] = emp;
						}
					}
				});

				const weekEnd = new Date(currentWeekStart);
				weekEnd.setDate(weekEnd.getDate() + 7);
				const startIso = weekIso;
				const endIso = formatDateISO(weekEnd);

				const availabilityParams = new URLSearchParams({
					action: "availability",
					start: startIso,
					end: endIso,
				});
				if (INCLUDE_FOREIGN_SHIFTS) {
					availabilityParams.set("include_foreign", "1");
				}
				const availabilityResp = await fetch(apiUrl + "?" + availabilityParams);
				if (!availabilityResp.ok) {
					throw new Error("Falha ao carregar disponibilidade");
				}
				const availabilityData = await availabilityResp.json();
				if (availabilityData && availabilityData.error) {
					throw new Error(availabilityData.error);
				}
				employeeDayOffs =
					availabilityData && availabilityData.dayoffs
						? availabilityData.dayoffs
						: {};
				employeeLeaves =
					availabilityData && availabilityData.leaves
						? availabilityData.leaves
						: {};
				const availabilityPublicHolidays = Array.isArray(
					availabilityData && availabilityData.public_holidays
				)
					? availabilityData.public_holidays
					: [];
				syncPublicHolidayMap(availabilityPublicHolidays);
				leaveDateLookup = buildLeaveDateLookup(employeeLeaves);
				activeLeaveCodes = collectLeaveCodesFromLookup(leaveDateLookup);
				renderLeaveLegend();
				if (typeof window !== "undefined") {
					window.__SHIFT_LEAVE_DEBUG = {
						employeeLeaves,
						leaveDateLookup,
					};
				}
				leaveById.clear();
				if (employeeLeaves && typeof employeeLeaves === "object") {
					Object.keys(employeeLeaves).forEach((empKey) => {
						const entries = employeeLeaves[empKey];
						if (!Array.isArray(entries)) return;
						entries.forEach((entry) => {
							if (!entry || entry.id === undefined || entry.id === null) return;
							if (!entry.empId) {
								entry.empId = parseInt(empKey, 10);
							}
							leaveById.set(String(entry.id), entry);
						});
					});
				}

				const listParams = new URLSearchParams({
					action: "list",
					start: startIso,
					end: endIso,
				});
				if (INCLUDE_FOREIGN_SHIFTS) {
					listParams.set("include_foreign", "1");
				}
				if (forcedIds.length) {
					listParams.set("force_user_ids", forcedIds.join(","));
				}
				if (hiddenIds.length) {
					listParams.set("hide_user_ids", hiddenIds.join(","));
				}
				const shiftsResp = await fetch(apiUrl + "?" + listParams);
				const shiftsPayload = await shiftsResp.json();
				let shiftsArray = normalizeShiftArray(shiftsPayload);
				if (!Array.isArray(shiftsArray)) {
					console.warn(
						"Shift payload not an array, coercing to empty list:",
						shiftsPayload
					);
					shiftsArray = [];
				}
				shiftsArray = shiftsArray
					.filter(isShiftRecord)
					.filter((shift) => shiftBelongsToCurrentEntity(shift));

				shiftMap = new Map();
				shiftsByEmployee = {};
				weeklyMinutesByEmployee = {};
				weeklyMinutesByEmployeeNormal = {};
				dailyMinutesByDate = {};

					shiftsArray.forEach((shift) => {
						const empIdRaw = shift.resourceId || shift.fk_user;
						const empKey =
							empIdRaw !== undefined && empIdRaw !== null ? String(empIdRaw) : "";
						const empKeyNum = Number(empKey);
						if (!empKey || !employeeMap[empIdRaw]) return;
						const dateISO = shiftplannerGetDisplayDate(shift.start);
						if (!dateISO) return;
						const shiftStatus = normalizeShiftStatusValue(
							shift.shift_status !== undefined && shift.shift_status !== null
								? shift.shift_status
								: shift.shiftStatus
						);
						const stypeValRaw =
							shift.shift_type !== undefined && shift.shift_type !== null
								? shift.shift_type
							: shift.shiftType !== undefined && shift.shiftType !== null
							? shift.shiftType
							: 1;
						const stypeValNum = Number.isFinite(Number(stypeValRaw))
							? Number(stypeValRaw)
							: 1;
						const minutes = getShiftDurationMinutes(shift.start, shift.end);
						if (minutes > 0 && !isMissedStatusValue(shiftStatus)) {
							dailyMinutesByDate[dateISO] =
								(dailyMinutesByDate[dateISO] || 0) + minutes;
						}

							const normalized = {
								id: shift.id,
								empId: empKey,
								resourceId: empKey,
								fk_user: empKey,
							date: dateISO,
							start: shift.start,
								end: shift.end,
								label: shift.title || "",
								note: shift.note || "",
								taskId: shift.fk_task || shift.taskId || null,
									taskColor:
									(taskMap[shift.fk_task || shift.taskId] &&
										taskMap[shift.fk_task || shift.taskId].color) ||
									shift.color ||
									shift.taskColor ||
									null,
								shift_type: stypeValNum,
								shiftType: stypeValNum,
								shift_status: shiftStatus,
								shiftStatus: shiftStatus,
								entity: shift.entity,
								fk_entity: shift.fk_entity,
								entity_label: shift.entity_label,
							foreign_entity: shift.foreign_entity,
						};

					shiftMap.set(String(normalized.id), normalized);

					if (!shiftsByEmployee[empKey]) {
						shiftsByEmployee[empKey] = {};
					}
					if (!shiftsByEmployee[empKey][dateISO]) {
						shiftsByEmployee[empKey][dateISO] = [];
					}
					shiftsByEmployee[empKey][dateISO].push(normalized);
				});

				// Persist normalized shifts for calculations
				shifts = Array.from(shiftMap.values());

				recalculateWeeklyMinutes();
				renderTable();
			} catch (error) {
				console.error("Failed to load data:", error);
				alert("Erro ao carregar dados");
			}
		}

		function persistWeekStart(date) {
			try {
				localStorage.setItem(WEEK_STORAGE_KEY, formatDateISO(date));
			} catch (err) {
				// ignore storage errors
			}
		}

		function loadStoredWeekStart() {
			try {
				const raw = localStorage.getItem(WEEK_STORAGE_KEY);
				if (!raw) return null;
				const parsed = new Date(`${raw}T00:00:00`);
				if (Number.isNaN(parsed.getTime())) return null;
				return getMonday(parsed);
			} catch (err) {
				return null;
			}
		}

		function normalizeEmployeeIdList(ids) {
			if (ids === null || ids === undefined) return [];
			let values = [];
			if (Array.isArray(ids)) {
				values = ids;
			} else if (ids instanceof Set) {
				values = Array.from(ids);
			} else if (typeof ids === "string") {
				const raw = ids.trim();
				if (!raw) return [];
				values = raw.split(/[\s,]+/);
			} else if (typeof ids === "number") {
				values = [ids];
			} else if (typeof ids === "object") {
				values = Object.values(ids);
			}
			const seen = new Set();
			const result = [];
			values.forEach((id) => {
				const num = Number(id);
				if (!Number.isFinite(num) || num <= 0) return;
				const key = String(Math.trunc(num));
				if (seen.has(key)) return;
				seen.add(key);
				result.push(key);
			});
			return result;
		}

		async function fetchWeekEmployeeOverrides(weekIso) {
			if (!weekIso) {
				return { forced: [], hidden: [] };
			}
			try {
				const resp = await fetch(
					`${apiUrl}?action=week_employee_overrides_get&week_start=${encodeURIComponent(
						weekIso
					)}`
				);
				if (!resp.ok) {
					throw new Error(`HTTP ${resp.status}`);
				}
				const data = await resp.json();
				const forced = normalizeEmployeeIdList(
					data && data.forced_user_ids ? data.forced_user_ids : []
				);
				const hidden = normalizeEmployeeIdList(
					data && data.hidden_user_ids ? data.hidden_user_ids : []
				);
				return { forced, hidden };
			} catch (err) {
				return { forced: [], hidden: [] };
			}
		}

		async function saveWeekEmployeeOverrides(weekIso, forcedIds, hiddenIds) {
			if (!weekIso) return false;
			const forced = normalizeEmployeeIdList(forcedIds);
			const hidden = normalizeEmployeeIdList(hiddenIds);
			try {
				const resp = await fetch(`${apiUrl}?action=week_employee_overrides_save`, {
					method: "POST",
					headers: {
						"Content-Type": "application/json",
					},
					credentials: "same-origin",
					body: JSON.stringify({
						week_start: weekIso,
						forced_user_ids: forced,
						hidden_user_ids: hidden,
					}),
				});
				const data = await resp.json().catch(() => null);
				if (!resp.ok || !data || !data.success) {
					throw new Error((data && data.error) || `HTTP ${resp.status}`);
				}
				return true;
			} catch (err) {
				return false;
			}
		}

		function persistEmployeeSortMode(mode) {
			try {
				localStorage.setItem(EMPLOYEE_SORT_STORAGE_KEY, mode);
			} catch (err) {
				// ignore persistence errors
			}
		}

		function loadStoredEmployeeSortMode() {
			try {
				const raw = localStorage.getItem(EMPLOYEE_SORT_STORAGE_KEY);
				if (raw === SORT_MODE_NAME) {
					return SORT_MODE_NAME;
				}
				return SORT_MODE_TASK;
			} catch (err) {
				return SORT_MODE_TASK;
			}
		}

		function parseIsoWeek(value) {
			if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
			const parsed = new Date(`${value}T00:00:00`);
			if (Number.isNaN(parsed.getTime())) return null;
			return getMonday(parsed);
		}

		function updateSwitchLink() {
			if (!switchLink) return;
			const base = switchLink.dataset.baseHref || switchLink.href;
			try {
				const url = new URL(base, window.location.origin);
				url.searchParams.set("week", formatDateISO(currentWeekStart));
				switchLink.href = url.pathname + url.search + url.hash;
			} catch (err) {
				// fallback: append query manually
				switchLink.href = `${base.split("?")[0]}?week=${formatDateISO(
					currentWeekStart
				)}`;
			}
		}

		function syncWeekState() {
			persistWeekStart(currentWeekStart);
			updateSwitchLink();
			updateWeekPicker();
		}

		async function fetchTasks() {
			const resp = await fetch(
				apiUrl + "?" + new URLSearchParams({ action: "task_list" })
			);
			const data = await resp.json();
			tasks = Array.isArray(data)
				? data.map((task) => shiftplannerNormalizeTaskRecord(task))
				: [];
			taskMap = {};
			tasks.forEach((task) => {
				taskMap[task.id] = task;
			});
		}

		function renderTable() {
			const header = document.getElementById("table-header");
			const body = document.getElementById("table-body");

			const employeeColumnTitle = escapeHtml(
				tLabel("employeeColumnLabel", "Funcionário")
			);
			const headerTooltip = escapeHtml(
				tLabel(
					"employeeHeaderTooltip",
					"Clique para gerir funcionários ou ordenar."
				)
			);
			const employeeHeaderContent = canManageEmployees
				? `<button type="button" id="manage-employees-btn" class="employee-manage-btn" title="${headerTooltip}">${employeeColumnTitle}</button>`
				: `<span class="employee-header-label" title="${headerTooltip}">${employeeColumnTitle}</span>`;
			const headerCellContent = `
				<div class="employee-col-header">
					<div class="employee-col-title">${employeeHeaderContent}</div>
					${buildEmployeeSortToggleHtml()}
				</div>`;
			const weekNoteColumnLabel = escapeHtml(
				tLabel("employeeWeekNoteColumnLabel", "Notes")
			);
			let headerHTML = `<tr><th class="employee-col">${headerCellContent}</th>`;

			const weekDates = [];
			for (let i = 0; i < 7; i++) {
				const date = new Date(currentWeekStart);
				date.setDate(currentWeekStart.getDate() + i);
				weekDates.push(date);

				const dayName = getDayName(date.getDay());
				const dateStr = formatDate(date);
				const dateISO = formatDateISO(date);
				const holidayInfo = getHolidayInfoByDate(dateISO);
				const holidayName = holidayInfo ? holidayInfo.name || "" : "";
				const holidayLabel = holidayName
					? `<br><small class="holiday-name">${escapeHtml(holidayName)}</small>`
					: "";
				const headerClasses = ["day-header"];
				if (date.getDay() === 0 || date.getDay() === 6) {
					headerClasses.push("is-weekend");
				}
				if (holidayInfo) {
					headerClasses.push("is-holiday");
				}
				let dayInner = `${dayName}<br>${dateStr}${holidayLabel}`;
				const dayViewBase =
					window.SHIFT_DAY_VIEW_BASE ||
					(window.DOL_MAIN_URL_ROOT
						? `${window.DOL_MAIN_URL_ROOT}/custom/kreaflow/schedule/schedule_bars_horizontal.php`
						: "/custom/kreaflow/schedule/schedule_bars_horizontal.php");
				if (dayViewBase) {
					dayInner = `<a class="day-link" href="${dayViewBase}?day=${encodeURIComponent(
						dateISO
					)}">${dayInner}</a>`;
				}
				headerHTML += `<th class="${headerClasses.join(" ")}" data-date="${dateISO}">${dayInner}</th>`;
			}
			if (employeeWeekNotesEnabled) {
				headerHTML += `<th class="day-header week-note-header">${weekNoteColumnLabel}</th>`;
			}
			headerHTML += "</tr>";
			headerHTML += buildDayTotalsRow(weekDates);
			header.innerHTML = headerHTML;
			adjustDayTotalsOffset();
			attachEmployeeSortToggleHandlers();

			let bodyHTML = "";
			const employeesForRender = getEmployeesForRender();
			employeesForRender.forEach((emp) => {
				const displayName = escapeHtml(getShortName(emp.title || ""));
				const hoursLabel = getEmployeeHoursLabel(emp.id);
				const hoursText = hoursLabel ? hoursLabel.trim() : "";
				const employmentIso = emp.dateEmployment || emp.employmentDate || "";
				if (employmentIso) {
					emp.employmentDate = String(employmentIso).slice(0, 10);
				} else {
					delete emp.employmentDate;
				}
				const employmentEndIso =
					emp.dateEmploymentEnd || emp.employmentEndDate || "";
				if (employmentEndIso) {
					emp.employmentEndDate = String(employmentEndIso).slice(0, 10);
				} else {
					delete emp.employmentEndDate;
				}
				const headingAttrs = `data-emp-id="${
					emp.id
				}" data-emp-name="${displayName}" data-emp-hours="${
					emp.expectedHours ?? ""
				}"`;
				const nameClass = canEditEmployeeHours
					? "employee-name editable"
					: "employee-name";
				const empCardUrl = `${
					window.DOL_MAIN_URL_ROOT || ""
				}/user/card.php?id=${encodeURIComponent(emp.id)}`;
				const displayNameLinked = `<a href="${empCardUrl}" target="_blank" rel="noopener noreferrer" class="employee-link">${displayName}</a>`;
				const mainTaskLabel = escapeHtml(getEmployeeMainTaskLabel(emp.id));
				const mainTaskClass = canEditEmployeeHours
					? "employee-main-task editable"
					: "employee-main-task";
				const nameTooltip = tLabel(
					"employeeNameTooltip",
					"Clique para editar horas e tarefa principal."
				);
				const hoursTooltip = tLabel(
					"employeeHoursTooltip",
					"Clique para editar horas previstas."
				);
				const mainTaskTooltip = tLabel(
					"employeeMainTaskTooltip",
					"Clique para alterar a tarefa principal."
				);

				const nameTitleAttr = nameTooltip
					? ` title="${escapeHtml(nameTooltip)}"`
					: "";
				const hoursTitleAttr = hoursTooltip
					? ` title="${escapeHtml(hoursTooltip)}"`
					: "";
				const taskTitleAttr = mainTaskTooltip
					? ` title="${escapeHtml(mainTaskTooltip)}"`
					: "";

				const copyTitle = escapeHtml(
					tLabel("copyRowNextWeek", "Copiar para próxima semana")
				);
				const copyIcon = `<svg class="employee-copy-week-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M16 1H4a2 2 0 0 0-2 2v12h2V3h12V1zm4 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H8V7h12v14z"/></svg>`;
				const copyButtonHtml = canWrite
					? `<button type="button" class="employee-copy-week-btn" data-emp-id="${emp.id}" title="${copyTitle}" aria-label="${copyTitle}">${copyIcon}</button>`
					: "";
				bodyHTML += `<tr><th><div class="employee-heading" ${headingAttrs}>${copyButtonHtml}<span class="${nameClass}"${nameTitleAttr}>${displayNameLinked}</span><span class="employee-hours"${hoursTitleAttr}>${hoursText}</span><span class="${mainTaskClass}"${taskTitleAttr}>${mainTaskLabel}</span></div></th>`;
				weekDates.forEach((date) => {
					const dateISO = formatDateISO(date);
					const cellShifts =
						shiftsByEmployee[emp.id] && shiftsByEmployee[emp.id][dateISO]
							? [...shiftsByEmployee[emp.id][dateISO]]
							: [];
					cellShifts.sort((a, b) => a.start.localeCompare(b.start));
					bodyHTML += renderCell(emp.id, dateISO, cellShifts);
				});
				if (employeeWeekNotesEnabled) {
					bodyHTML += renderEmployeeWeekNoteCell(emp.id);
				}
				bodyHTML += "</tr>";
			});

			body.innerHTML = bodyHTML;

			document.querySelectorAll(".shift-cell").forEach((cell) => {
				cell.addEventListener("click", handleCellClick);
				cell.addEventListener("dragover", handleCellDragOver);
				cell.addEventListener("drop", handleCellDrop);
			});
			document.querySelectorAll(".shift-pill").forEach((pill) => {
				if (pill.classList.contains("leave-pill")) {
					pill.addEventListener("click", handleLeavePillClick);
					return;
				}
				pill.addEventListener("click", handleShiftPillClick);
				pill.addEventListener("dragstart", handleShiftDragStart);
				pill.addEventListener("dragend", handleShiftDragEnd);
			});
			document.querySelectorAll(".shift-add-hint").forEach((btn) => {
				btn.addEventListener("click", handleAddButtonClick);
			});
			document.querySelectorAll(".shift-copy-hint").forEach((btn) => {
				btn.addEventListener("click", handleCopyCellClick);
			});
			document.querySelectorAll(".employee-copy-week-btn").forEach((btn) => {
				btn.addEventListener("click", handleEmployeeWeekCopy);
			});
			if (employeeWeekNotesEnabled) {
				attachEmployeeWeekNoteHandlers();
			}
			if (canManageEmployees) {
				const manageBtn = document.getElementById("manage-employees-btn");
				if (manageBtn) {
					manageBtn.addEventListener("click", (e) => {
						e.preventDefault();
						openEmployeeManager();
					});
				}
			}
			if (quotaModal) {
				document.querySelectorAll(".employee-heading").forEach((heading) => {
					const triggers = heading.querySelectorAll(
						canEditEmployeeHours
							? ".employee-name, .employee-main-task"
							: ".employee-name"
					);
					triggers.forEach((trigger) => {
						trigger.style.cursor = "pointer";
						trigger.addEventListener("click", (e) => {
							e.preventDefault();
							e.stopPropagation();
							openQuotaModal(heading);
						});
					});
				});
			}
		}

		function buildEmployeeSortToggleHtml() {
			const label = escapeHtml(tLabel("employeeSortLabel", "Ordenar por"));
			const nameLabel = escapeHtml(tLabel("employeeSortName", "Nome"));
			const taskLabel = escapeHtml(tLabel("employeeSortTask", "Tarefa"));
			const nameBtnClass = ["employee-sort-btn"];
			const taskBtnClass = ["employee-sort-btn"];
			if (employeeSortMode === SORT_MODE_NAME) {
				nameBtnClass.push("active");
			} else if (employeeSortMode === SORT_MODE_TASK) {
				taskBtnClass.push("active");
			}
			return `
				<div class="employee-sort-toggle" role="group" aria-label="${label}">
					<div class="employee-sort-buttons">
						<button type="button" class="${nameBtnClass.join(
							" "
						)}" data-sort-mode="${SORT_MODE_NAME}">${nameLabel}</button>
						<button type="button" class="${taskBtnClass.join(
							" "
						)}" data-sort-mode="${SORT_MODE_TASK}">${taskLabel}</button>
					</div>
				</div>`;
		}

		function buildDayTotalsRow(weekDates) {
			if (!Array.isArray(weekDates) || !weekDates.length) return "";
			const label = escapeHtml(tLabel("dayTotalsLabel", "Total do dia"));
			const cells = [
				`<tr class="day-total-row"><th class="employee-col day-total-label">${label}</th>`,
			];
			weekDates.forEach((date) => {
				const dateISO = formatDateISO(date);
				const isWeekend = date && (date.getDay() === 0 || date.getDay() === 6);
				const holidayInfo = getHolidayInfoByDate(dateISO);
				const classes = ["day-total-cell"];
				if (isWeekend) classes.push("is-weekend");
				if (holidayInfo) classes.push("is-holiday");
				const minutes = dailyMinutesByDate[dateISO] || 0;
				const formatted = formatHoursFromMinutes(minutes || 0);
				cells.push(
					`<th class="${classes.join(" ")}" data-date="${dateISO}">${formatted}</th>`
				);
			});
			if (employeeWeekNotesEnabled) {
				cells.push(`<th class="day-total-cell week-note-total-cell">&nbsp;</th>`);
			}
			cells.push("</tr>");
			return cells.join("");
		}

		function adjustDayTotalsOffset() {
			const headerEl = document.getElementById("table-header");
			if (!headerEl) return;
			const firstRow = headerEl.querySelector("tr:first-child");
			const totalsRow = headerEl.querySelector(".day-total-row");
			if (
				!firstRow ||
				!totalsRow ||
				typeof firstRow.getBoundingClientRect !== "function"
			) {
				return;
			}
			const height = firstRow.getBoundingClientRect().height;
			if (!height || height <= 0) return;
			totalsRow.querySelectorAll("th").forEach((cell) => {
				cell.style.top = `${height}px`;
			});
		}

		function attachEmployeeSortToggleHandlers() {
			const buttons = document.querySelectorAll(".employee-sort-btn");
			if (!buttons.length) return;
			buttons.forEach((btn) => {
				btn.addEventListener("click", (e) => {
					e.preventDefault();
					e.stopPropagation();
					const mode = e.currentTarget.dataset.sortMode;
					setEmployeeSortMode(mode);
				});
			});
		}

		function setEmployeeSortMode(mode) {
			const normalized =
				mode === SORT_MODE_TASK ? SORT_MODE_TASK : SORT_MODE_NAME;
			if (normalized === employeeSortMode) return;
			employeeSortMode = normalized;
			persistEmployeeSortMode(normalized);
			renderTable();
		}

		function getEmployeesForRender() {
			const list = employees.slice();
			if (employeeSortMode === SORT_MODE_TASK) {
				return list.sort(compareEmployeesByTask);
			}
			return list.sort(compareEmployeeTitles);
		}

		function compareEmployeesByTask(a, b) {
			const aTaskId = getEmployeeMainTaskId(a && a.id);
			const bTaskId = getEmployeeMainTaskId(b && b.id);
			const hasTaskA = Boolean(aTaskId);
			const hasTaskB = Boolean(bTaskId);
			if (hasTaskA && !hasTaskB) return -1;
			if (!hasTaskA && hasTaskB) return 1;
			if (hasTaskA && hasTaskB) {
				const aLabel = getTaskLabelForSort(aTaskId).toLowerCase();
				const bLabel = getTaskLabelForSort(bTaskId).toLowerCase();
				const labelCompare = aLabel.localeCompare(bLabel);
				if (labelCompare !== 0) {
					return labelCompare;
				}
			}
			return compareEmployeeTitles(a, b);
		}

		function getTaskLabelForSort(taskId) {
			if (!taskId) return "";
			const task = taskMap[taskId];
			return task && task.label ? task.label : "";
		}

		function getEmployeeMainTaskId(empId) {
			const emp = employeeMap[empId];
			if (!emp) return "";
			const raw =
				emp.mainTaskId !== undefined && emp.mainTaskId !== null
					? emp.mainTaskId
					: emp.main_task_id;
			if (raw === undefined || raw === null) return "";
			const value = String(raw).trim();
			return value === "" || value === "0" ? "" : value;
		}

		function getEmployeeMainTaskLabel(empId, options = {}) {
			const { fallback = true } = options;
			const taskId = getEmployeeMainTaskId(empId);
			if (!taskId) {
				return fallback ? tLabel("mainTaskNone", "Sem tarefa principal") : "";
			}
			const label = getTaskLabelForSort(taskId);
			if (label) {
				return label;
			}
			return fallback ? tLabel("mainTaskNone", "Sem tarefa principal") : "";
		}

			function getWeekNotesStorageKey() {
				return WEEK_NOTES_KEY;
			}

			function getCurrentWeekNotesKey() {
				const iso = formatDateISO(currentWeekStart);
				return `${getWeekNotesStorageKey()}:${iso}`;
			}

			function normalizeEmployeeWeekNotesPayload(raw) {
				const normalized = {};
				if (!raw || typeof raw !== "object") return normalized;
				Object.keys(raw).forEach((key) => {
					const employeeId = Number(key);
					if (!Number.isFinite(employeeId) || employeeId <= 0) return;
					const value = raw[key];
					if (value === null || value === undefined) return;
					const text = String(value).trim();
					if (!text) return;
					normalized[String(Math.trunc(employeeId))] = text;
				});
				return normalized;
			}

			function getEmployeeWeekNote(empId) {
				const key = String(empId || "").trim();
				if (!key) return "";
				if (!Object.prototype.hasOwnProperty.call(weekEmployeeNotes, key)) {
					return "";
				}
				return String(weekEmployeeNotes[key] || "");
			}

			function renderEmployeeWeekNoteCell(empId) {
				if (!employeeWeekNotesEnabled) return "";
				const noteText = getEmployeeWeekNote(empId);
				const placeholder = escapeHtml(
					tLabel(
						"employeeWeekNotePlaceholder",
						"Note."
					)
				);
				const saveLabel = escapeHtml(tLabel("employeeWeekNoteSave", "Save"));
				const readOnlyAttrs = canWrite ? "" : " readonly disabled";
				const saveButtonHtml = canWrite
					? `<button type="button" class="employee-week-note-save" data-emp-id="${empId}" disabled>${saveLabel}</button>`
					: "";
				return `<td class="week-note-cell"><div class="employee-week-note-wrapper"><textarea class="employee-week-note-input" data-emp-id="${empId}" data-initial-note="${escapeHtml(
					noteText
				)}" placeholder="${placeholder}"${readOnlyAttrs}>${escapeHtml(
					noteText
				)}</textarea>${saveButtonHtml}</div></td>`;
			}

			function applyEmployeeWeekNotesToTable() {
				if (!employeeWeekNotesEnabled) return;
				const inputs = document.querySelectorAll(".employee-week-note-input");
				if (!inputs.length) return;
				inputs.forEach((input) => {
					const empId = input.dataset.empId || "";
					const noteText = getEmployeeWeekNote(empId);
					input.value = noteText;
					input.dataset.initialNote = noteText;
					const wrapper = input.closest(".employee-week-note-wrapper");
					const saveBtn = wrapper
						? wrapper.querySelector(".employee-week-note-save")
						: null;
					if (saveBtn) {
						saveBtn.disabled = true;
						saveBtn.classList.remove("busy");
					}
				});
			}

			function attachEmployeeWeekNoteHandlers() {
				if (!employeeWeekNotesEnabled) return;
				document.querySelectorAll(".employee-week-note-input").forEach((input) => {
					input.addEventListener("click", (e) => {
						e.stopPropagation();
					});
					input.addEventListener("input", () => {
						const initial = (input.dataset.initialNote || "").trim();
						const current = (input.value || "").trim();
						const wrapper = input.closest(".employee-week-note-wrapper");
						const saveBtn = wrapper
							? wrapper.querySelector(".employee-week-note-save")
							: null;
						if (!saveBtn) return;
						saveBtn.disabled = current === initial;
					});
					input.addEventListener("blur", () => {
						void persistEmployeeWeekNote(input, { showError: false });
					});
				});
				document.querySelectorAll(".employee-week-note-save").forEach((btn) => {
					btn.addEventListener("click", handleEmployeeWeekNoteSave);
				});
			}

			async function persistEmployeeWeekNote(input, options = {}) {
				const { showError = true } = options;
				if (!canWrite || !input) return false;
				const wrapper = input.closest(".employee-week-note-wrapper");
				if (!wrapper) return false;
				const saveBtn = wrapper.querySelector(".employee-week-note-save");
				const empId = Number(input.dataset.empId || 0);
				if (!Number.isFinite(empId) || empId <= 0) return false;
				const initial = (input.dataset.initialNote || "").trim();
				const current = (input.value || "").trim();
				if (current === initial) {
					if (saveBtn) saveBtn.disabled = true;
					return true;
				}
				if (input.dataset.saving === "1") return false;
				input.dataset.saving = "1";
				const weekIso = formatDateISO(currentWeekStart);
				if (saveBtn) {
					saveBtn.disabled = true;
					saveBtn.classList.add("busy");
				}
				const result = await saveEmployeeWeekNoteToServer(
					weekIso,
					empId,
					String(input.value || "")
				);
				input.dataset.saving = "0";
				if (saveBtn) {
					saveBtn.classList.remove("busy");
				}
				if (!result.success) {
					if (saveBtn) saveBtn.disabled = false;
					if (showError) {
						window.alert(
							tLabel(
								"employeeWeekNoteSaveError",
								"Failed to save employee note."
							)
						);
					}
					return false;
				}
				const normalizedNote = String(result.note || "");
				if (normalizedNote) {
					weekEmployeeNotes[String(empId)] = normalizedNote;
				} else {
					delete weekEmployeeNotes[String(empId)];
				}
				input.value = normalizedNote;
				input.dataset.initialNote = normalizedNote;
				if (saveBtn) saveBtn.disabled = true;
				return true;
			}

			async function handleEmployeeWeekNoteSave(e) {
				e.preventDefault();
				e.stopPropagation();
				if (!canWrite) return;
				const btn = e.currentTarget;
				const wrapper = btn.closest(".employee-week-note-wrapper");
				const input = wrapper
					? wrapper.querySelector(".employee-week-note-input")
					: null;
				if (!input) return;
				await persistEmployeeWeekNote(input, { showError: true });
			}

			async function fetchWeekNotesFromServer(weekIso) {
				if (!weekIso) {
					return { note: "", employeeNotes: {} };
				}
				try {
					const resp = await fetch(
						`${apiUrl}?action=week_notes_get&week_start=${encodeURIComponent(
							weekIso
						)}`
					);
					if (!resp.ok) {
						throw new Error("HTTP " + resp.status);
					}
					const data = await resp.json();
					const note =
						typeof data.notes === "string"
							? data.notes
							: typeof data.note === "string"
							? data.note
							: "";
					const employeeNotes = normalizeEmployeeWeekNotesPayload(
						data && data.employee_notes ? data.employee_notes : {}
					);
					return { note, employeeNotes };
				} catch (err) {
					console.warn("Failed to load week notes", err);
					return { note: "", employeeNotes: {} };
				}
			}

			async function saveWeekNotesToServer(weekIso, text) {
				if (!weekIso) return false;
				try {
					const resp = await fetch(apiUrl, {
						method: "POST",
						headers: { "Content-Type": "application/x-www-form-urlencoded" },
						body: new URLSearchParams({
							action: "week_notes_save",
							week_start: weekIso,
							notes: text || "",
						}),
					});
					const data = await resp.json();
					return data && data.success;
				} catch (err) {
					console.warn("Failed to save week notes", err);
					return false;
				}
			}

			async function saveEmployeeWeekNoteToServer(weekIso, employeeId, text) {
				if (!weekIso || !employeeId) {
					return { success: false, note: "" };
				}
				try {
					const resp = await fetch(apiUrl, {
						method: "POST",
						headers: { "Content-Type": "application/x-www-form-urlencoded" },
						body: new URLSearchParams({
							action: "week_notes_save",
							week_start: weekIso,
							employee_id: String(employeeId),
							employee_note: text || "",
						}),
					});
					const data = await resp.json().catch(() => null);
					if (!resp.ok || !data || !data.success) {
						return { success: false, note: "" };
					}
					const notesMap = normalizeEmployeeWeekNotesPayload(
						data && data.employee_notes ? data.employee_notes : {}
					);
					const key = String(employeeId);
					const normalizedNote = Object.prototype.hasOwnProperty.call(
						notesMap,
						key
					)
						? notesMap[key]
						: "";
					return { success: true, note: normalizedNote };
				} catch (err) {
					console.warn("Failed to save employee week note", err);
					return { success: false, note: "" };
				}
			}

			updateWeekNotesUI = async function () {
				if (!weekNotesBox || !weekNotesTextarea) return;
				const startLabel = formatDate(currentWeekStart);
				const endDate = new Date(currentWeekStart);
				endDate.setDate(endDate.getDate() + 6);
				const endLabel = formatDate(endDate);
				if (weekNotesTitle) {
					weekNotesTitle.textContent = `Notas da semana (${startLabel} a ${endLabel})`;
				}
				weekNotesTextarea.disabled = true;
				weekNotesTextarea.value = "";
				weekEmployeeNotes = {};
				applyEmployeeWeekNotesToTable();
				const weekIso = formatDateISO(currentWeekStart);
				const requestToken = ++weekNotesRequestToken;
				const payload = await fetchWeekNotesFromServer(weekIso);
				if (requestToken !== weekNotesRequestToken) return;
				weekNotesTextarea.value = payload.note || "";
				weekEmployeeNotes =
					payload && payload.employeeNotes ? payload.employeeNotes : {};
				applyEmployeeWeekNotesToTable();
				if (canWrite) {
					weekNotesTextarea.disabled = false;
				}
			};

			function buildBlockedCellTaskLabel(empId) {
				const label = getEmployeeMainTaskLabel(empId, { fallback: false });
				if (!label) return "";
				return `<div class="cell-main-task">(${escapeHtml(label)})</div>`;
			}

		function setEmployeeManagerFeedback(message, type = "info") {
			if (!employeeManagerFeedback) return;
			if (!message) {
				employeeManagerFeedback.style.display = "none";
				employeeManagerFeedback.textContent = "";
				employeeManagerFeedback.classList.remove("error", "success");
				return;
			}
			employeeManagerFeedback.style.display = "block";
			employeeManagerFeedback.textContent = message;
			employeeManagerFeedback.classList.remove("error", "success");
			if (type === "error") {
				employeeManagerFeedback.classList.add("error");
			} else if (type === "success") {
				employeeManagerFeedback.classList.add("success");
			}
		}

		function renderEmployeeManagerList() {
			if (!employeeManagerList) return;
			const loadingMessage = tLabel(
				"manageEmployeesLoading",
				"Loading employees..."
			);
			const emptyMessage = tLabel(
				"manageEmployeesEmpty",
				"No employees found."
			);
			if (!employeePoolLoaded) {
				if (employeeManagerEmpty) {
					employeeManagerEmpty.style.display = "block";
					employeeManagerEmpty.textContent = employeePoolLoading
						? loadingMessage
						: emptyMessage;
				}
				employeeManagerList.innerHTML = "";
				return;
			}
			const termRaw =
				employeeManagerSearchInput && employeeManagerSearchInput.value
					? employeeManagerSearchInput.value
					: "";
			const term = normalizeSearchText(termRaw.trim());
			const filtered = employeePool.filter((user) => {
				if (!term) return true;
				const haystack = normalizeSearchText(
					`${user.label || ""} ${user.login || ""} ${user.email || ""}`
				);
				return haystack.includes(term);
			});
			if (employeeManagerEmpty) {
				employeeManagerEmpty.style.display = filtered.length ? "none" : "block";
				if (!filtered.length) {
					employeeManagerEmpty.textContent = emptyMessage;
				}
			}
			if (!filtered.length) {
				employeeManagerList.innerHTML = "";
				return;
			}
			const inactiveLabel = tLabel("employeeInactiveTag", "Inactive");
			const activeLabel = tLabel("employeeActiveTag", "Active");
			const items = filtered
				.map((user) => {
					const checked = employeePoolSelected.has(String(user.id))
						? "checked"
						: "";
					const metaParts = [];
					if (user.login) metaParts.push(user.login);
					if (user.email) metaParts.push(user.email);
					const meta =
						metaParts.length > 0
							? `<span class="employee-manager-meta">${escapeHtml(
									metaParts.join(" • ")
							  )}</span>`
							: "";
					const isActive = Number(user.status) === 1;
					const statusLabel = isActive ? activeLabel : inactiveLabel;
					const statusClass = isActive ? "active" : "inactive";
					const statusButton = `<button type="button" class="employee-status-toggle ${statusClass}" data-user-id="${
						user.id
					}" data-status="${isActive ? 1 : 0}">${escapeHtml(
						statusLabel
					)}</button>`;
					return `<label class="employee-manager-item">
						<input type="checkbox" data-user-id="${user.id}" ${checked}>
						<div class="employee-manager-info">
							<span class="employee-manager-name">${escapeHtml(user.label || "")}</span>
							${meta}
						</div>
						${statusButton}
					</label>`;
				})
				.join("");
			employeeManagerList.innerHTML = items;
		}

		async function openEmployeeManager() {
			if (!canManageEmployees || !employeeManagerModal) return;
			employeeManagerModal.classList.add("open");
			employeeManagerModal.setAttribute("aria-hidden", "false");
			if (employeeManagerSearchInput) {
				employeeManagerSearchInput.value = "";
			}
			setEmployeeManagerFeedback("");
			await loadEmployeePool(true);
		}

		function closeEmployeeManager() {
			if (!employeeManagerModal) return;
			employeeManagerModal.classList.remove("open");
			employeeManagerModal.setAttribute("aria-hidden", "true");
			setEmployeeManagerFeedback("");
			if (employeeManagerSearchInput) {
				employeeManagerSearchInput.value = "";
			}
		}

		async function loadEmployeePool(force = false) {
			if (!canManageEmployees || !employeeManagerModal) return;
			if (employeePoolLoaded && !force) {
				renderEmployeeManagerList();
				return;
			}
			employeePoolLoading = true;
			employeePoolError = "";
			renderEmployeeManagerList();
			try {
				const poolStart = formatDateISO(currentWeekStart);
				const poolEndDate = new Date(currentWeekStart);
				poolEndDate.setDate(poolEndDate.getDate() + 6);
				const poolEnd = formatDateISO(poolEndDate);
				const resp = await fetch(
					apiUrl +
					"?" +
					new URLSearchParams({
						action: "employee_pool",
						start: poolStart,
						end: poolEnd,
					}),
					{ credentials: "same-origin" }
				);
				if (!resp.ok) {
					let message = tLabel(
						"manageEmployeesNoGroup",
						"Configure the default planner group first."
					);
					if (resp.status !== 409) {
						try {
							const payload = await resp.json();
							if (payload && payload.error) {
								message = translateApiError(payload.error, payload.error);
							}
						} catch (err) {
							message = `HTTP ${resp.status}`;
						}
					}
					employeePoolError = message;
					setEmployeeManagerFeedback(employeePoolError, "error");
					if (employeeManagerSaveBtn) {
						employeeManagerSaveBtn.disabled = true;
					}
					return;
				}
				const data = await resp.json();
				const users = Array.isArray(data.users) ? data.users : [];
				employeePool = users;
				employeePoolAssigned = new Set(
					users
						.filter((entry) => entry && entry.isAssigned)
						.map((entry) => String(entry.id))
				);
				const weekIso = formatDateISO(currentWeekStart);
				const overrides = await fetchWeekEmployeeOverrides(weekIso);
				const forcedIds = overrides.forced;
				const hiddenIds = overrides.hidden;
				const forcedSet = new Set(forcedIds);
				hiddenIds.forEach((id) => forcedSet.delete(id));
				employeePoolForced = forcedSet;
				employeePoolHidden = new Set(hiddenIds);
				const selected = new Set([
					...Array.from(employeePoolAssigned),
					...Array.from(forcedSet),
				]);
				hiddenIds.forEach((id) => selected.delete(id));
				employeePoolSelected = selected;
				employeePoolLoaded = true;
				if (employeeManagerSaveBtn) {
					employeeManagerSaveBtn.disabled = false;
				}
				setEmployeeManagerFeedback("");
			} catch (error) {
				employeePoolError = error.message || "Failed to load employees";
				setEmployeeManagerFeedback(employeePoolError, "error");
			} finally {
				employeePoolLoading = false;
				renderEmployeeManagerList();
			}
		}

		function handleEmployeeManagerSelectionChange(e) {
			const target = e.target;
			if (!target || !target.matches('input[type="checkbox"][data-user-id]')) {
				return;
			}
			const userId = target.dataset.userId;
			if (!userId) return;
			if (target.checked) {
				employeePoolSelected.add(String(userId));
			} else {
				employeePoolSelected.delete(String(userId));
			}
		}

		async function handleEmployeeStatusToggleClick(e) {
			const button = e.target.closest(".employee-status-toggle");
			if (!button) return;
			e.preventDefault();
			e.stopPropagation();
			if (!canManageEmployees) {
				return;
			}
			const userId = button.dataset.userId;
			if (!userId) return;
			const currentStatus = Number(button.dataset.status) === 1 ? 1 : 0;
			const nextStatus = currentStatus === 1 ? 0 : 1;
			const successMessage = tLabel(
				"employeeStatusToggleSuccess",
				"Employee status updated."
			);
			const errorMessage = tLabel(
				"employeeStatusToggleError",
				"Failed to update employee status."
			);
			button.disabled = true;
			button.classList.add("busy");
			try {
				const resp = await fetch(`${apiUrl}?action=employee_status_update`, {
					method: "POST",
					headers: {
						"Content-Type": "application/json",
					},
					credentials: "same-origin",
					body: JSON.stringify({
						user_id: Number(userId),
						status: nextStatus,
					}),
				});
				let data = null;
				try {
					data = await resp.json();
				} catch (err) {
					data = null;
				}
				if (!resp.ok || !data || !data.success) {
					const details =
						data && data.error
							? translateApiError(data.error, data.error)
							: "";
					throw new Error(details || `HTTP ${resp.status}`);
				}
				const targetUser = employeePool.find(
					(entry) => entry && String(entry.id) === String(userId)
				);
				if (targetUser) {
					targetUser.status = nextStatus;
				}
				setEmployeeManagerFeedback(successMessage, "success");
				renderEmployeeManagerList();
			} catch (error) {
				console.error("Failed to update employee status:", error);
				setEmployeeManagerFeedback(errorMessage, "error");
			} finally {
				button.disabled = false;
				button.classList.remove("busy");
			}
		}

		async function handleEmployeeManagerSave() {
			if (!canManageEmployees || !employeeManagerSaveBtn) return;
			const weekIso = formatDateISO(currentWeekStart);
			const selectedIds = normalizeEmployeeIdList(
				Array.from(employeePoolSelected)
			);
			const selectedSet = new Set(selectedIds);
			const forcedIds = selectedIds.filter(
				(id) => !employeePoolAssigned.has(id)
			);
			const hiddenIds = Array.from(employeePoolAssigned).filter(
				(id) => !selectedSet.has(id)
			);
			employeeManagerSaveBtn.disabled = true;
			employeeManagerSaveBtn.classList.add("busy");
			setEmployeeManagerFeedback("");
			try {
				const overridesSaved = await saveWeekEmployeeOverrides(
					weekIso,
					forcedIds,
					hiddenIds
				);
				if (!overridesSaved) {
					setEmployeeManagerFeedback(
						tLabel(
							"employeeWeekOverrideSaveError",
							"Falha ao guardar a seleção semanal."
						),
						"error"
					);
					return;
				}
				const forcedSet = new Set(forcedIds);
				hiddenIds.forEach((id) => forcedSet.delete(id));
				employeePoolForced = forcedSet;
				employeePoolHidden = new Set(hiddenIds);
				employeePoolLoaded = false;
				closeEmployeeManager();
				await loadData();
			} catch (error) {
				setEmployeeManagerFeedback(
					error.message || "Failed to save employees",
					"error"
				);
			} finally {
				employeeManagerSaveBtn.disabled = false;
				employeeManagerSaveBtn.classList.remove("busy");
			}
		}

		function isoDateToUnix(dateISO) {
			if (!dateISO) return null;
			const parsed = new Date(`${dateISO}T00:00:00`);
			const time = parsed.getTime();
			return Number.isNaN(time) ? null : Math.floor(time / 1000);
		}

		function secondsToTimeLabel(seconds) {
			if (seconds === undefined || seconds === null) return "";
			const ts = typeof seconds === "string" ? parseInt(seconds, 10) : seconds;
			if (!ts || Number.isNaN(ts)) return "";
			const date = new Date(ts * 1000);
			if (Number.isNaN(date.getTime())) return "";
			const hours = String(date.getHours()).padStart(2, "0");
			const minutes = String(date.getMinutes()).padStart(2, "0");
			return `${hours}:${minutes}`;
		}

		function formatTimeRangeFromSeconds(startSeconds, endSeconds) {
			const startLabel = secondsToTimeLabel(startSeconds);
			const endLabel = secondsToTimeLabel(endSeconds);
			if (!startLabel || !endLabel) return "";
			return `${startLabel} - ${endLabel}`;
		}

		function getDayOffForDate(empId, dateISO) {
			const entries = employeeDayOffs[String(empId)] || employeeDayOffs[empId];
			if (!Array.isArray(entries)) return null;
			for (const entry of entries) {
				if (entry && entry.date_iso === dateISO) {
					return entry;
				}
			}
			return null;
		}

		function getLeaveForDate(empId, dateISO) {
			const empKey = String(empId);
			const byDate = leaveDateLookup[empKey];
			if (byDate) {
				const mapped = byDate[dateISO];
				if (Array.isArray(mapped)) {
					if (mapped.length) return mapped[0];
				} else if (mapped) {
					return mapped;
				}
			}
			const entries = employeeLeaves[empKey];
			if (!Array.isArray(entries)) return null;
			const ts = isoDateToUnix(dateISO);
			if (ts === null) return null;
			for (const entry of entries) {
				if (!entry) continue;
				const startIso = entry.start_iso || "";
				const endIso = entry.end_iso || "";
				if (startIso && endIso) {
					if (dateISO >= startIso && dateISO <= endIso) {
						return entry;
					}
					continue;
				}
				if (typeof entry.start === "number" && typeof entry.end === "number") {
					if (ts >= entry.start && ts <= entry.end) {
						return entry;
					}
				}
			}
			return null;
		}

		function getFullLeaveForDate(empId, dateISO) {
			const entry = getLeaveForDate(empId, dateISO);
			if (!entry) return null;
			return isLeaveEntryPartial(entry) ? null : entry;
		}

		function getLeaveEntriesForDate(empId, dateISO) {
			const empKey = String(empId);
			const byDate = leaveDateLookup[empKey];
			if (byDate) {
				const mapped = byDate[dateISO];
				if (Array.isArray(mapped)) {
					return mapped.slice();
				}
				if (mapped) {
					return [mapped];
				}
			}
			return [];
		}

		function getPartialLeavesForDate(empId, dateISO) {
			const entries = getLeaveEntriesForDate(empId, dateISO);
			if (!entries.length) return [];
			return entries.filter((entry) => isLeaveEntryPartial(entry));
		}

		function buildPartialLeavePayload(empId, leaveInfo, newEndDate) {
			const startIsoRaw =
				leaveInfo.start_iso ||
				leaveInfo.leave_start ||
				leaveInfo.date_start ||
				newEndDate;
			const startIso = (startIsoRaw || "").slice(0, 10) || newEndDate;
			const mode = getPartialLeaveMode(leaveInfo);
			const minutesValue =
				leaveInfo.hours_total_minutes ??
				leaveInfo.minutes_total ??
				leaveInfo.leave_minutes_total ??
				"";
			const payload = {
				user_id: empId,
				leave_type: leaveInfo.type_id || leaveInfo.fk_type || "",
				leave_start: startIso,
				leave_end: newEndDate,
				leave_halfday: mode,
				leave_description: leaveInfo.description || "",
				leave_hours_start:
					leaveInfo.hours_start || leaveInfo.leave_hours_start || "",
				leave_hours_end: leaveInfo.hours_end || leaveInfo.leave_hours_end || "",
				leave_minutes_total: minutesValue !== "" ? String(minutesValue) : "",
			};
			if (mode === "hours_range") {
				if (!payload.leave_hours_start) {
					payload.leave_hours_start = "09:00";
				}
				if (!payload.leave_hours_end) {
					payload.leave_hours_end = "17:00";
				}
			} else {
				payload.leave_hours_start = "";
				payload.leave_hours_end = "";
			}
			return payload;
		}

		function normalizePartialMode(rawMode) {
			if (rawMode === undefined || rawMode === null) return undefined;
			const raw = String(rawMode);
			if (raw === "hours" || raw === "hours_range" || raw === "2") return "hours_range";
			if (raw === "minutes" || raw === "minutes_total" || raw === "3") return "minutes_total";
			if (raw === "1") return "halfday";
			if (raw === "0" || raw === "full") return "0";
			return raw.toLowerCase ? raw.toLowerCase() : raw;
		}

		function getPartialLeaveMode(leaveInfo) {
			if (!leaveInfo) return "0";
			const isHours =
				leaveInfo.is_hours === 1 ||
				leaveInfo.is_hours === "1" ||
				normalizePartialMode(leaveInfo.leave_halfday) === "hours_range";
			if (isHours) return "hours_range";
			const isMinutes =
				leaveInfo.minutes_only === 1 ||
				leaveInfo.minutes_only === "1" ||
				normalizePartialMode(leaveInfo.leave_halfday) === "minutes_total";
			if (isMinutes) return "minutes_total";
			if (
				leaveInfo.leave_halfday !== undefined &&
				leaveInfo.leave_halfday !== null
			) {
				return normalizePartialMode(leaveInfo.leave_halfday);
			}
			if (leaveInfo.halfday !== undefined && leaveInfo.halfday !== null) {
				return normalizePartialMode(leaveInfo.halfday);
			}
			return "0";
		}

		function isLeaveEntryPartial(entry) {
			if (!entry) return false;
			const normalizedHalf = normalizePartialMode(entry.halfday);
			if (
				normalizedHalf === "hours_range" ||
				normalizedHalf === "minutes_total" ||
				normalizedHalf === "halfday"
			) {
				return true;
			}
			const isHoursLeave = entry.is_hours === 1 || entry.is_hours === "1";
			const minutesTotal = parseInt(entry.hours_total_minutes, 10) || 0;
			const hasMinuteOnlyFields =
				(!entry.hours_start || entry.hours_start === "") &&
				(!entry.hours_end || entry.hours_end === "") &&
				(!entry.hours_range || entry.hours_range === "");
			const isMinutesLeave =
				entry.minutes_only === 1 ||
				entry.minutes_only === "1" ||
				(minutesTotal > 0 && hasMinuteOnlyFields);
			return Boolean(isHoursLeave || isMinutesLeave);
		}

		function hasShiftsOnDate(empId, dateISO) {
			if (!empId || !dateISO) return false;
			const byEmp = shiftsByEmployee[empId];
			if (!byEmp) return false;
			const dayShifts = byEmp[dateISO];
			return Array.isArray(dayShifts) && dayShifts.length > 0;
		}

		function findShiftConflictInRange(empId, startISO, endISO) {
			if (!startISO || !endISO) return null;
			const start = new Date(`${startISO}T00:00:00`);
			const end = new Date(`${endISO}T00:00:00`);
			if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
				return null;
			}
			const current = new Date(start);
			while (current <= end) {
				const iso = shiftplannerFormatISODateLocal(current);
				if (hasShiftsOnDate(empId, iso)) {
					return iso;
				}
				current.setDate(current.getDate() + 1);
			}
			return null;
		}

		function isPartialLeaveSelection(value) {
			if (value === null || value === undefined) return false;
			const normalized = normalizePartialMode(value);
			return (
				normalized === "hours_range" ||
				normalized === "minutes_total" ||
				normalized === "halfday" ||
				normalized === "1" ||
				normalized === "-1"
			);
		}

		function isDateWithinCurrentWeek(dateISO) {
			if (!dateISO || !currentWeekStart) return false;
			const startIso = formatDateISO(currentWeekStart);
			const endDate = new Date(currentWeekStart);
			endDate.setDate(endDate.getDate() + 6);
			const endIso = formatDateISO(endDate);
			return dateISO >= startIso && dateISO <= endIso;
		}

			function buildEntityBadge(entry, options = {}) {
			if (!entry) return "";
			const inline = options.inline === true;
			const foreignFlag =
				entry.foreign_entity === 1 ||
				entry.foreign_entity === "1" ||
				entry.foreignEntity === true;
			const entityIdRaw =
				entry.entity ??
				entry.fk_entity ??
				entry.entity_id ??
				entry.leave_entity ??
				entry.dayoff_entity ??
				(entry.extendedProps && entry.extendedProps.entity);
			const entityId = entityIdRaw !== undefined ? Number(entityIdRaw) : NaN;
			let isForeign = foreignFlag;
			if (!isForeign && Number.isFinite(entityId)) {
				if (CURRENT_ENTITY !== null && CURRENT_ENTITY !== undefined) {
					isForeign = entityId !== Number(CURRENT_ENTITY);
				}
			}
			if (!isForeign) return "";
			const entityLabelRaw =
				entry.entity_label ||
				entry.entityLabel ||
				(entry.extendedProps && entry.extendedProps.entity_label) ||
				"";
			const entityFallback = Number.isFinite(entityId) ? `E${entityId}` : "";
			const entityLabel = entityLabelRaw || entityFallback;
			if (!entityLabel) return "";
			const inlineClass = inline ? " shift-type-inline" : "";
				return `<span class="shift-type-badge entity${inlineClass}" title="${escapeHtml(
					entityLabel
				)}">${escapeHtml(entityLabel)}</span>`;
			}

			function normalizeBadgeColor(value) {
				if (value === undefined || value === null) return "";
				const normalized = String(value).trim();
				return /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(normalized)
					? normalized
					: "";
			}

			function buildDescriptionBadge(description, options = {}) {
				if (description === undefined || description === null) return "";
				const inline = options.inline === true;
				const normalized = String(description).replace(/\s+/g, " ").trim();
				if (!normalized) return "";
				const inlineClass = inline ? " shift-type-inline" : "";
				const tooltip = `${tLabel("descriptionBadgeLabel", "Description")}: ${normalized}`;
				const badgeColor = normalizeBadgeColor(options.badgeColor || "");
				const textColor = normalizeBadgeColor(options.textColor || "");
				const styleParts = [];
				if (badgeColor) {
					styleParts.push(`background:${badgeColor}`);
					styleParts.push(`border-color:${badgeColor}`);
				}
				if (textColor) {
					styleParts.push(`color:${textColor}`);
				}
				const styleAttr = styleParts.length
					? ` style="${escapeHtml(styleParts.join(";"))};"`
					: "";
				return `<span class="shift-type-badge info${inlineClass}" title="${escapeHtml(
					tooltip
				)}" aria-label="${escapeHtml(tooltip)}"${styleAttr}>i</span>`;
			}

		async function fetchTargetDaySnapshot(empId, dateISO) {
			const empKey = String(empId);
			if (isDateWithinCurrentWeek(dateISO)) {
				const dayOffInfo = getDayOffForDate(empId, dateISO);
				const leaveEntries = getLeaveEntriesForDate(empId, dateISO);
				const shifts =
					shiftsByEmployee[empId] && shiftsByEmployee[empId][dateISO]
						? [...shiftsByEmployee[empId][dateISO]]
						: [];
				return { dayOffInfo, leaveEntries, shifts };
			}
			const endIso = addDaysToISODate(dateISO, 1);
			if (!endIso) {
				return { dayOffInfo: null, leaveEntries: [], shifts: [] };
			}
			const availabilityParams = new URLSearchParams({
				action: "availability",
				start: dateISO,
				end: endIso,
			});
			if (INCLUDE_FOREIGN_SHIFTS) {
				availabilityParams.set("include_foreign", "1");
			}
			const availabilityResp = await fetch(
				apiUrl + "?" + availabilityParams
			);
			if (!availabilityResp.ok) {
				throw new Error("Falha ao validar disponibilidade");
			}
			const availabilityData = await availabilityResp.json();
			if (availabilityData && availabilityData.error) {
				throw new Error(availabilityData.error);
			}
			const dayoffs =
				availabilityData && availabilityData.dayoffs
					? availabilityData.dayoffs
					: {};
			const leaves =
				availabilityData && availabilityData.leaves
					? availabilityData.leaves
					: {};
			let dayOffInfo = null;
			const dayoffEntries = dayoffs[empKey] || dayoffs[Number(empKey)] || [];
			if (Array.isArray(dayoffEntries)) {
				dayOffInfo =
					dayoffEntries.find(
						(entry) => entry && entry.date_iso === dateISO
					) || null;
			}
			const tempLeaveLookup = buildLeaveDateLookup(leaves);
			let leaveEntries = [];
			const byDate = tempLeaveLookup[empKey];
			if (byDate) {
				const mapped = byDate[dateISO];
				if (Array.isArray(mapped)) {
					leaveEntries = mapped.slice();
				} else if (mapped) {
					leaveEntries = [mapped];
				}
			}
			const listParams = new URLSearchParams({
				action: "list",
				start: dateISO,
				end: endIso,
			});
			if (INCLUDE_FOREIGN_SHIFTS) {
				listParams.set("include_foreign", "1");
			}
			const shiftsResp = await fetch(apiUrl + "?" + listParams);
			if (!shiftsResp.ok) {
				throw new Error("Falha ao validar turnos");
			}
			const shiftsPayload = await shiftsResp.json();
			let shiftsArray = normalizeShiftArray(shiftsPayload);
			if (!Array.isArray(shiftsArray)) {
				shiftsArray = [];
			}
			shiftsArray = shiftsArray
				.filter(isShiftRecord)
				.filter((shift) => shiftBelongsToCurrentEntity(shift));
			const shifts = shiftsArray.filter((shift) => {
				const empIdRaw = shift.resourceId || shift.fk_user || shift.empId;
				const key =
					empIdRaw !== undefined && empIdRaw !== null
						? String(empIdRaw)
						: "";
				if (!key || key !== empKey) return false;
				return shiftplannerGetDisplayDate(shift.start) === dateISO;
			});
			return { dayOffInfo, leaveEntries, shifts };
		}

		function doesShiftOverlapLeave(shiftStartIso, shiftEndIso, leaveEntries) {
			if (!Array.isArray(leaveEntries) || !leaveEntries.length) return false;
			const shiftStartMs = parseIsoToUtcMs(shiftStartIso);
			const shiftEndMs = parseIsoToUtcMs(shiftEndIso);
			if (shiftStartMs === null || shiftEndMs === null) return true;
			for (const entry of leaveEntries) {
				if (!entry) continue;
				if (!isLeaveEntryPartial(entry)) {
					return true;
				}
				const rawStart = entry.start_ts ?? entry.start;
				const rawEnd = entry.end_ts ?? entry.end;
				const startVal = Number(rawStart);
				const endVal = Number(rawEnd);
				if (!Number.isFinite(startVal) || !Number.isFinite(endVal)) {
					return true;
				}
				const leaveStartMs = startVal * 1000;
				const leaveEndMs = endVal * 1000;
				if (leaveEndMs > shiftStartMs && leaveStartMs < shiftEndMs) {
					return true;
				}
			}
			return false;
		}

		function renderCell(empId, dateISO, cellShifts) {
			const employmentIso = getEmploymentDateISO(empId);
			const employmentEndIso = getEmploymentEndDateISO(empId);
			const holidayInfo = getHolidayInfoByDate(dateISO);
			const holidayAttr =
				holidayInfo && holidayInfo.name
					? ` data-holiday-name="${escapeHtml(holidayInfo.name)}"`
					: "";
			const getHolidayClass = (allowHighlight) =>
				holidayInfo && allowHighlight ? " holiday-cell" : "";

			if (employmentIso && dateISO < employmentIso) {
				const futureMessage = escapeHtml(
					tLabel(
						"employeeBeforeHire",
						"Funcionário ainda não iniciou nesta data."
					)
				);
				const isPrev = isDayBeforeEmployment(empId, dateISO);
				const label = isPrev
					? escapeHtml(tLabel("dayBeforeHire", "Admissão para o dia seguinte"))
					: "-";
				const labelHtml = isPrev
					? `<div class="future-label">${label}</div>`
					: `<div class="future-label dash">${label}</div>`;
				return `<td class="shift-cell future-before${getHolidayClass(
					false
				)}" data-emp="${empId}" data-date="${dateISO}" data-entry-type="future" data-future-message="${futureMessage}" data-employment="${employmentIso}"${holidayAttr}>${labelHtml}</td>`;
			}
			if (employmentEndIso && dateISO > employmentEndIso) {
				const afterMessage = escapeHtml(
					tLabel(
						"employeeAfterEnd",
						"Funcionário já não está ativo nesta data."
					)
				);
				const isNext = isDayAfterEmploymentEnd(empId, dateISO);
				const label = isNext
					? escapeHtml(tLabel("dayAfterEnd", "Terminou no dia anterior"))
					: "-";
				const labelHtml = isNext
					? `<div class="future-label">${label}</div>`
					: `<div class="future-label dash">${label}</div>`;
				return `<td class="shift-cell future-after${getHolidayClass(
					false
				)}" data-emp="${empId}" data-date="${dateISO}" data-entry-type="future" data-future-message="${afterMessage}" data-employment="${employmentEndIso}"${holidayAttr}>${labelHtml}</td>`;
			}

			let leaveEntries = getLeaveEntriesForDate(empId, dateISO);
			if (!leaveEntries.length) {
				const fallbackLeave = getLeaveForDate(empId, dateISO);
				if (fallbackLeave) {
					leaveEntries = [fallbackLeave];
				}
			}
			const fullLeaveEntry =
				leaveEntries.find((entry) => !isLeaveEntryPartial(entry)) || null;
			const partialLeaveEntries = leaveEntries.filter((entry) =>
				isLeaveEntryPartial(entry)
			);
			const hasFullLeaveEntries = Boolean(fullLeaveEntry);
			const hasPartialLeaveEntries = partialLeaveEntries.length > 0;
			const hasShiftsForDay = cellShifts.length > 0;
			const dayOffInfo = getDayOffForDate(empId, dateISO);
			const shiftIdsForDay = cellShifts.map((shift) => shift.id);
			const hasDayOffConflict =
				Boolean(dayOffInfo) && (hasShiftsForDay || hasFullLeaveEntries);
			const hasFullLeaveConflict =
				hasFullLeaveEntries &&
				(hasShiftsForDay || Boolean(dayOffInfo) || hasPartialLeaveEntries);
			const showInlineDayOff = Boolean(dayOffInfo) && hasShiftsForDay;
			let dayOffMeta = null;
			if (dayOffInfo) {
				const isLockDay =
					dayOffInfo.is_lock === 1 ||
					dayOffInfo.is_lock === "1" ||
					dayOffInfo.is_lock === true ||
					dayOffInfo.entry_type === "lockday";
				const recurringTitle = tLabel(
					"recurringHint",
					"Recorrente semanalmente"
				);
				const recurringTag = dayOffInfo.is_recurring
					? `<span class="dayoff-recurring" title="${escapeHtml(
							recurringTitle
					  )}">&#8635;</span>`
					: "";
				const dayOffLabel = escapeHtml(
					isLockDay
						? tLabel("lockday", "Dia não laboral")
						: tLabel("dayoff", "Férias")
				);
				const entityBadge = buildEntityBadge(dayOffInfo, { inline: true });
				const note = dayOffInfo.description
					? `<div class="dayoff-note">${escapeHtml(
							dayOffInfo.description
					  )}</div>`
					: "";
				dayOffMeta = {
					isLockDay,
					recurringTag,
					recurringTitle,
					dayOffLabel,
					entityBadge,
					note,
					description: dayOffInfo.description || "",
					isRecurring: Boolean(dayOffInfo.is_recurring),
				};
			}

			if (dayOffInfo && !showInlineDayOff) {
				const isLockDay = dayOffMeta ? dayOffMeta.isLockDay : false;
				const dayOffLabel = dayOffMeta ? dayOffMeta.dayOffLabel : "";
				const recurringTag = dayOffMeta ? dayOffMeta.recurringTag : "";
				const entityBadge = dayOffMeta ? dayOffMeta.entityBadge : "";
				const labelRow = `<div class="dayoff-label-row"><div class="dayoff-label">${dayOffLabel} ${recurringTag}</div>${entityBadge}</div>`;
				const note = dayOffMeta ? dayOffMeta.note : "";
				const mainTaskTag = buildBlockedCellTaskLabel(empId);
				const conflictClass = hasDayOffConflict ? " conflict" : "";
				const conflictAttrs = hasDayOffConflict
					? ` data-conflict="true" data-conflict-type="dayoff" data-conflict-shifts="${shiftIdsForDay.join(
							","
					  )}" data-conflict-dayoff-id="${dayOffInfo.id}"`
					: "";
				const actionButtons = canWrite
					? `
				<div class="shift-cell-actions">
					<button type="button" class="shift-copy-hint shift-action-btn" data-emp="${empId}" data-date="${dateISO}" title="Copiar para dia seguinte" aria-label="Copiar para dia seguinte">&#x21E8;</button>
				</div>
			`
					: "";
				const lockClass =
					dayOffMeta && dayOffMeta.isLockDay ? " lockday" : "";
				return `<td class="shift-cell dayoff${lockClass}${conflictClass}${getHolidayClass(
					false
				)}" data-emp="${empId}" data-date="${dateISO}" data-entry-type="${isLockDay ? "lockday" : "dayoff"}" data-entry-id="${
					dayOffInfo.id
				}" data-entry-desc="${escapeHtml(
					dayOffInfo.description || ""
				)}" data-entry-recurring="${
					dayOffInfo.is_recurring ? "1" : "0"
				}" data-entry-lock="${isLockDay ? "1" : "0"}"${conflictAttrs}${holidayAttr}><div class="dayoff-inner">${labelRow}${mainTaskTag}${note}</div>${actionButtons}</td>`;
			}

			let hasPartialLeave = false;
			const entryPills = [];
			let entryPillSequence = 0;
			const pushOrderedEntryPill = (html, startMs, endMs) => {
				const normalizedStart = Number.isFinite(startMs)
					? startMs
					: Number.MAX_SAFE_INTEGER;
				const normalizedEnd = Number.isFinite(endMs)
					? endMs
					: normalizedStart;
				entryPills.push({
					html,
					startMs: normalizedStart,
					endMs: normalizedEnd,
					sequence: entryPillSequence++,
				});
			};
			if (fullLeaveEntry) {
				const rawLeaveLabel =
					fullLeaveEntry.type || tLabel("leave", "Ausência");
				const rawLeaveCode = (fullLeaveEntry.code || "").trim();
				const leaveLabel = escapeHtml(rawLeaveLabel);
				const formattedLeaveCode = formatLeaveCodeDisplay(
					rawLeaveCode,
					rawLeaveLabel
				);
				const leaveCodeDisplay = escapeHtml(
					formattedLeaveCode || rawLeaveLabel || ""
				);
				const typeKey =
					fullLeaveEntry.type ||
					fullLeaveEntry.type_id ||
					fullLeaveEntry.fk_type ||
					fullLeaveEntry.code ||
					"";
				const typeKeyNorm = normalizeLeaveKey(typeKey);
				const labelNorm = normalizeLeaveKey(
					fullLeaveEntry.label || fullLeaveEntry.type || ""
				);
				const mappedColor =
					(typeKeyNorm && leaveTypeColorMap[typeKeyNorm]
						? leaveTypeColorMap[typeKeyNorm]
						: null) ||
					(labelNorm && leaveTypeColorMap[labelNorm]
						? leaveTypeColorMap[labelNorm]
						: null);
				const leaveColor =
					mappedColor ||
					fullLeaveEntry.color ||
					shiftColors.leave ||
					fallbackLeaveColor;
				const mappedFont =
					(typeKeyNorm && leaveTypeFontMap[typeKeyNorm]
						? leaveTypeFontMap[typeKeyNorm]
						: null) ||
					(labelNorm && leaveTypeFontMap[labelNorm]
						? leaveTypeFontMap[labelNorm]
						: null);
				const leaveFontColor = mappedFont || leaveFontDefault;
				const entityBadge = buildEntityBadge(fullLeaveEntry, { inline: true });
				const descriptionBadge = buildDescriptionBadge(fullLeaveEntry.description, {
					inline: true,
					badgeColor: leaveColor,
					textColor: leaveFontColor,
				});
				const leaveLabelRow = `<div class="leave-label-row"><div class="leave-label">${leaveCodeDisplay}</div>${entityBadge}${descriptionBadge}</div>`;
				const mainTaskTag = buildBlockedCellTaskLabel(empId);
				const halfdayClass =
					fullLeaveEntry.halfday === 1
						? "halfday-morning"
						: "";
				const conflictClass = hasFullLeaveConflict ? " conflict" : "";
				const partialIdsAttr =
					hasPartialLeaveEntries && partialLeaveEntries.length
						? ` data-conflict-partial-leaves="${partialLeaveEntries
								.map((entry) => entry && entry.id)
								.filter(Boolean)
								.join(",")}"`
						: "";
				const conflictAttrs = hasFullLeaveConflict
					? ` data-conflict="true" data-conflict-type="leave" data-conflict-shifts="${shiftIdsForDay.join(
							","
					  )}" data-conflict-leave="${fullLeaveEntry.id}"${partialIdsAttr}`
					: "";
				const leaveActionButtons = canWrite
					? `
				<div class="shift-cell-actions">
					<button type="button" class="shift-copy-hint shift-action-btn" data-emp="${empId}" data-date="${dateISO}" title="Copiar para dia seguinte" aria-label="Copiar ausência para o dia seguinte">&#x21E8;</button>
				</div>
			`
					: "";
				return `<td class="shift-cell leave ${halfdayClass}${conflictClass}${getHolidayClass(
					false
				)}" data-emp="${empId}" data-date="${dateISO}" data-entry-type="leave" data-entry-id="${
					fullLeaveEntry.id
				}" data-entry-label="${leaveLabel}" style="--leave-color:${leaveColor};color:${leaveFontColor}"${conflictAttrs}${holidayAttr}><div class="leave-inner">${leaveLabelRow}${mainTaskTag}</div>${leaveActionButtons}</td>`;
			}
			if (partialLeaveEntries.length) {
				hasPartialLeave = true;
				partialLeaveEntries.forEach((entry) => {
					const rawLeaveLabel = entry.type || tLabel("leave", "Ausência");
					const rawLeaveCode = (entry.code || "").trim();
					const leaveLabel = escapeHtml(rawLeaveLabel);
					const formattedLeaveCode = formatLeaveCodeDisplay(
						rawLeaveCode,
						rawLeaveLabel
					);
					const leaveCodeDisplay = escapeHtml(
						formattedLeaveCode || rawLeaveLabel || ""
					);
					const typeKey =
						entry.type || entry.type_id || entry.fk_type || entry.code || "";
					const typeKeyNorm = normalizeLeaveKey(typeKey);
					const labelNorm = normalizeLeaveKey(entry.label || entry.type || "");
					const mappedColor =
						(typeKeyNorm && leaveTypeColorMap[typeKeyNorm]
							? leaveTypeColorMap[typeKeyNorm]
							: null) ||
						(labelNorm && leaveTypeColorMap[labelNorm]
							? leaveTypeColorMap[labelNorm]
							: null);
					const leaveColor =
						mappedColor ||
						entry.color ||
						shiftColors.leave ||
						fallbackLeaveColor;
					const mappedFont =
						(typeKeyNorm && leaveTypeFontMap[typeKeyNorm]
							? leaveTypeFontMap[typeKeyNorm]
							: null) ||
						(labelNorm && leaveTypeFontMap[labelNorm]
							? leaveTypeFontMap[labelNorm]
							: null);
					const leaveFontColor = mappedFont || leaveFontDefault;
					const entityBadge = buildEntityBadge(entry);
					const descriptionBadge = buildDescriptionBadge(entry.description, {
						badgeColor: leaveColor,
						textColor: leaveFontColor,
					});
					const isHoursLeave =
						entry.is_hours === 1 ||
						entry.is_hours === "1" ||
						normalizePartialMode(entry.halfday) === "hours_range";
					const minutesTotal = parseInt(entry.hours_total_minutes, 10) || 0;
					const hasMinuteOnlyFields =
						(!entry.hours_start || entry.hours_start === "") &&
						(!entry.hours_end || entry.hours_end === "") &&
						(!entry.hours_range || entry.hours_range === "");
					const isMinutesLeave =
						entry.minutes_only === 1 ||
						entry.minutes_only === "1" ||
						normalizePartialMode(entry.halfday) === "minutes_total" ||
						(minutesTotal > 0 && hasMinuteOnlyFields);
					let detailLabel = "";
					const hoursRangeLabel = entry.hours_range
						? entry.hours_range.replace("-", " - ")
						: "";
					const hasMinutesOnly = isMinutesLeave;
					const computedTimeRange = hasMinutesOnly
						? ""
						: formatTimeRangeFromSeconds(entry.start_ts, entry.end_ts);
					if (isHoursLeave) {
						detailLabel = hoursRangeLabel || computedTimeRange;
					} else if (hasMinutesOnly) {
						detailLabel = formatMinutesSummary(minutesTotal);
					} else if (typeof entry.halfday === "number" && entry.halfday !== 0) {
						detailLabel =
							computedTimeRange ||
							(entry.halfday === 1
								? tLabel("leaveHalfMorningLabel", "Manhã")
								: tLabel("leaveHalfAfternoonLabel", "Tarde"));
					}
					if (
						minutesTotal > 0 &&
						(!detailLabel || detailLabel === "00:00 - 00:00")
					) {
						detailLabel = formatMinutesSummary(minutesTotal);
					}
					const leaveBg = colorWithAlpha(leaveColor, 0.2);
					const detailHtml = detailLabel
						? `<span class="fc-event-time">${escapeHtml(detailLabel)}</span>`
						: "";
					const leavePillHtml = `
				<div class="shift-pill fc-event leave-pill" data-leave-id="${entry.id}" data-leave-label="${leaveLabel}" style="border-left-color:${leaveColor}; background:${leaveBg}; color:${leaveFontColor};">
					<div class="fc-event-main">
						<div class="fc-event-title-container">
							<span class="fc-event-title">${leaveCodeDisplay}</span>
							${detailHtml}
						</div>
						</div>
						${entityBadge}${descriptionBadge}
					</div>
				`;
					const leaveStartSeconds = Number(entry.start_ts);
					const leaveEndSeconds = Number(entry.end_ts);
					const leaveStartMs = Number.isFinite(leaveStartSeconds)
						? leaveStartSeconds * 1000
						: parseIsoToUtcMs(
								entry.start || entry.start_iso || entry.date_start || ""
						  );
					const leaveEndMs = Number.isFinite(leaveEndSeconds)
						? leaveEndSeconds * 1000
						: parseIsoToUtcMs(entry.end || entry.end_iso || entry.date_end || "");
					pushOrderedEntryPill(leavePillHtml, leaveStartMs, leaveEndMs);
				});
			}
			// Summary excludes missed shifts from working-hour totals.
			const totalShiftMinutes = cellShifts.reduce((sum, shift) => {
				if (isMissedShift(shift)) {
					return sum;
				}
				const minutes = getShiftDurationMinutes(shift.start, shift.end);
				return sum + (minutes > 0 ? minutes : 0);
			}, 0);
			const totalShiftSummary =
				totalShiftMinutes > 0
					? ` (${formatHoursValue(totalShiftMinutes / 60, false)})`
					: "";
			const empKey = empId !== undefined && empId !== null ? String(empId) : "";
			const empKeyNum = Number(empKey);
			const normalWeeklyMinutes =
				weeklyMinutesByEmployeeNormal[empKey] !== undefined
					? weeklyMinutesByEmployeeNormal[empKey] || 0
					: weeklyMinutesByEmployeeNormal[empKeyNum] || 0;
			const expectedHours =
				getEmployeeExpectedHours(empKey) ??
				getEmployeeExpectedHours(empKey !== "" ? empKeyNum : null);
				const overCapacityNormal =
					Number.isFinite(expectedHours) &&
					expectedHours > 0 &&
					normalWeeklyMinutes / 60 - expectedHours > 0.01;
				cellShifts.forEach((shift, index) => {
					const timeText = formatTimeRange(shift.start, shift.end);
					const isMissed = isMissedShift(shift);
					const baseColor = isMissed
						? fallbackMissedColor
						: getTaskColor(
							shift.taskId,
							shift.taskColor || shift.color || fallbackLeaveColor
						);
					const overCapacity = overCapacityNormal && !isMissed;
					const appliedColor = overCapacity ? "#c62828" : baseColor;
					const bg = isMissed
						? colorWithAlpha(baseColor, 0.22)
						: colorWithAlpha(baseColor, 0.15);
					const pillClass = overCapacity
						? "shift-pill fc-event over-capacity"
						: isMissed
						? "shift-pill fc-event shift-missed"
						: "shift-pill fc-event";
					const showSummary =
						Boolean(totalShiftSummary) && index === cellShifts.length - 1;
					const summaryText = showSummary ? escapeHtml(totalShiftSummary) : "";
					const stype = Number(
						shift.shift_type !== undefined && shift.shift_type !== null
						? shift.shift_type
						: shift.shiftType !== undefined && shift.shiftType !== null
							? shift.shiftType
							: 1
					);
					const stypeVal = Number.isFinite(stype) ? stype : 1;
					const typeBadge =
						isMissed
							? ""
							: stypeVal === 2
							? badgeShowSpecial
								? '<span class="shift-type-badge special" title="Turno Especial">E</span>'
								: ""
							: badgeShowNormal
							? '<span class="shift-type-badge normal" title="Turno Normal">N</span>'
							: "";
					const missedBadge = isMissed
						? `<span class="shift-type-badge missed" title="${escapeHtml(
								tLabel("shiftStatusMissed", "Missed")
						  )}" style="background:${fallbackMissedColor};color:${fallbackMissedFontColor};">${escapeHtml(
								tLabel("missedShiftBadge", "M")
						  )}</span>`
						: "";
					const foreignEntity =
						shift.foreign_entity === 1 ||
						shift.foreignEntity === true ||
						shift.foreign_entity === "1";
					const entityLabelRaw =
						shift.entity_label ||
						shift.entityLabel ||
						(shift.extendedProps && shift.extendedProps.entity_label) ||
						"";
					const entityIdRaw =
						shift.entity ??
						shift.fk_entity ??
						(shift.extendedProps && shift.extendedProps.entity);
					const entityId = entityIdRaw !== undefined ? Number(entityIdRaw) : NaN;
					const entityFallback = Number.isFinite(entityId)
						? `E${entityId}`
						: "";
					const entityLabel = entityLabelRaw || entityFallback;
					const entityBadge =
						foreignEntity && entityLabel
							? `<span class="shift-type-badge entity" title="${escapeHtml(
									entityLabel
							  )}">${escapeHtml(entityLabel)}</span>`
							: "";
					const descriptionBadge = buildDescriptionBadge(
						shift.note || shift.description || "",
						{
							badgeColor: appliedColor,
							textColor: isMissed
								? fallbackMissedFontColor
								: getTaskTextColor(shift.taskId),
						}
					);
					const badgeOverlay = `${entityBadge}${descriptionBadge}${missedBadge}${typeBadge}`;
					const textStyle = isMissed
						? ` color:${fallbackMissedFontColor};`
						: "";
					const shiftPillHtml = `
					<div class="${pillClass}" draggable="true" data-shift-id="${shift.id}" style="border-left-color:${appliedColor}; background:${bg};${textStyle}">
						<div class="fc-event-main">
							<div class="fc-event-title-container">
								<span class="fc-event-time">${timeText}${summaryText}</span>
					</div>
				</div>
				${badgeOverlay}
			</div>
		`;
					const shiftStartMs = parseIsoToUtcMs(shift.start);
					const shiftEndMs = parseIsoToUtcMs(shift.end);
					pushOrderedEntryPill(shiftPillHtml, shiftStartMs, shiftEndMs);
				});
			const sortedEntryPills = entryPills
				.slice()
				.sort((a, b) => {
					if (a.startMs !== b.startMs) {
						return a.startMs - b.startMs;
					}
					if (a.endMs !== b.endMs) {
						return a.endMs - b.endMs;
					}
					return a.sequence - b.sequence;
				})
				.map((pill) => pill.html);
			const pillContent = sortedEntryPills.length
				? '<div class="shift-pill-list">' + sortedEntryPills.join("") + "</div>"
				: '<div class="shift-empty"></div>';
			const cellBodyClass = hasPartialLeave
				? "shift-cell-body has-partial-leave"
				: "shift-cell-body";
			let inlineDayOffBadge = "";
			let inlineDayOffAttrs = "";
			let inlineDayOffClass = "";
			let inlineDayOffConflictClass = "";
			if (showInlineDayOff && dayOffMeta && dayOffInfo) {
				const tooltipParts = [];
				if (dayOffMeta.description) {
					tooltipParts.push(dayOffMeta.description);
				}
				if (dayOffMeta.isRecurring && dayOffMeta.recurringTitle) {
					tooltipParts.push(dayOffMeta.recurringTitle);
				}
				const tooltip =
					tooltipParts.length > 0 ? tooltipParts.join(" - ") : "";
				const tooltipAttr = tooltip
					? ` title="${escapeHtml(tooltip)}"`
					: "";
				const inlineLabel = `<span class="dayoff-badge-label">${dayOffMeta.dayOffLabel} ${dayOffMeta.recurringTag}</span>`;
				const inlineEntityBadge = dayOffMeta.entityBadge || "";
				inlineDayOffBadge = `<div class="dayoff-badge${
					dayOffMeta.isLockDay ? " lockday" : ""
				}"${tooltipAttr}>${inlineLabel}${inlineEntityBadge}</div>`;
				inlineDayOffAttrs = ` data-conflict="true" data-conflict-type="dayoff" data-conflict-shifts="${shiftIdsForDay.join(
					","
				)}" data-conflict-dayoff-id="${dayOffInfo.id}"`;
				inlineDayOffClass = " has-dayoff";
				inlineDayOffConflictClass = " conflict";
			}
			const content = `<div class="${cellBodyClass}">${inlineDayOffBadge}${pillContent}</div>`;

			const actionButtons = canWrite
				? `
			<div class="shift-cell-actions">
				<button type="button" class="shift-copy-hint shift-action-btn" data-emp="${empId}" data-date="${dateISO}" title="Copiar para dia seguinte" aria-label="Copiar turnos para o dia seguinte">&#x21E8;</button>
				<button type="button" class="shift-add-hint shift-action-btn" data-emp="${empId}" data-date="${dateISO}" title="Adicionar turno" aria-label="Adicionar turno">+</button>
			</div>
		`
				: "";
			const partialClass = hasPartialLeave ? " has-partial-leave" : "";
			const allowHolidayHighlight = !hasFullLeaveEntries && !dayOffInfo;
			return `<td class="shift-cell${partialClass}${inlineDayOffClass}${inlineDayOffConflictClass}${getHolidayClass(
				allowHolidayHighlight
			)}" data-emp="${empId}" data-date="${dateISO}"${holidayAttr}${inlineDayOffAttrs}>${content}${actionButtons}</td>`;
		}

		function handleCellClick(e) {
			const cell = e.currentTarget;
			if (
				cell.classList &&
				cell.classList.contains("conflict") &&
				typeof handleConflictCellClick === "function"
			) {
				handleConflictCellClick(cell);
				return;
			}
			const pill = e.target.closest(".shift-pill");
			if (pill) {
				if (pill.classList.contains("leave-pill")) {
					const leaveId = pill.dataset.leaveId;
					if (leaveId) {
						const leaveInfo = leaveById.get(String(leaveId));
						if (leaveInfo) {
							showLeaveEditDialog(leaveInfo);
							return;
						}
					}
				} else {
					return;
				}
			}
			if (cell.classList && cell.classList.contains("conflict")) {
				handleConflictCellClick(cell);
				return;
			}
			if (e.target.closest(".shift-add-hint")) return;
			if (e.target.closest(".shift-copy-hint")) return;
			const entryType = cell.dataset.entryType;
			if (entryType === "dayoff" || entryType === "lockday") {
				const isLock =
					cell.dataset.entryLock === "1" ||
					entryType === "lockday" ||
					cell.dataset.entryType === "lockday";
				let message = isLock
					? tLabel("employeeOnLockDay", "Dia não laboral.")
					: tLabel("employeeOnDayOff", "Funcionário está em Férias.");
				const desc = cell.dataset.entryDesc;
				const notePrefix = tLabel("notePrefix", "Nota:");
				if (desc) {
					message += `\n${notePrefix} ${desc}`;
				}
				if (cell.dataset.entryRecurring === "1") {
					message += `\n${tLabel("recurringHint", "Recorrente semanalmente")}.`;
				}
				const confirmMessage = tLabel(
					isLock ? "confirmRemoveLockDay" : "confirmRemoveDayOff",
					isLock
						? "Pretende remover este dia não laboral?"
						: "Pretende remover estas Férias?"
				);
				if (canWrite) {
					if (confirm(`${message}\n\n${confirmMessage}`)) {
						deleteDayOffEntry(cell.dataset.entryId);
					}
				} else {
					alert(message);
				}
				return;
			}

			if (entryType === "leave") {
				const leaveInfo = getLeaveForDate(cell.dataset.emp, cell.dataset.date);
				if (leaveInfo) {
					showLeaveEditDialog(leaveInfo);
				}
				return;
			}

			if (entryType === "future") {
				const msg =
					cell.dataset.futureMessage ||
					tLabel(
						"employeeBeforeHire",
						"Funcionário ainda não iniciou nesta data."
					);
				alert(msg);
				return;
			}
			if (!canWrite) return;
			const empId = cell.dataset.emp;
			const dateISO = cell.dataset.date;
			showCreateDialog(empId, dateISO);
		}

		function handleLeavePillClick(e) {
			const pill = e.target.closest(".leave-pill");
			if (!pill) return;
			e.preventDefault();
			e.stopPropagation();
			const leaveId = pill.dataset.leaveId;
			if (!leaveId) return;
			const leaveInfo = leaveById.get(String(leaveId));
			if (leaveInfo) {
				showLeaveEditDialog(leaveInfo);
			}
		}

		function handleAddButtonClick(e) {
			e.stopPropagation();
			const btn = e.currentTarget;
			const empId = btn.dataset.emp;
			const dateISO = btn.dataset.date;
			showCreateDialog(empId, dateISO);
		}

		function openConflictResolutionDialog({
			empId,
			dateISO,
			shiftIds,
			dayoffId,
			partialLeaveIds,
			resolveMode = "shifts",
		}) {
			const overlay = document.createElement("div");
			overlay.className = "shift-overlay conflict-overlay";
			const dialog = document.createElement("div");
			dialog.className = "shift-dialog conflict-dialog";
			const title = escapeHtml(
				tLabel("conflictDialogTitle", "Conflito detectado")
			);
			const removingDayoff = resolveMode === "dayoff";
			const description = escapeHtml(
				removingDayoff
					? tLabel(
							"conflictDialogDayoffDescription",
							"Existe um conflito entre o turno e a folga. Pretende remover a folga para manter os turnos?"
					  )
					: tLabel(
							"conflictDialogDescription",
							"Existe um conflito entre o turno e a ausência. Pretende remover os turnos deste dia para manter a ausência?"
					  )
			);
			const resolveLabel = escapeHtml(
				removingDayoff
					? tLabel("conflictDialogResolveDayoff", "Remover folga")
					: tLabel("conflictDialogResolve", "Remover turnos")
			);
			const errorLabel = removingDayoff
				? tLabel(
						"conflictDialogDayoffError",
						"Falha ao remover folga em conflito."
				  )
				: tLabel(
						"conflictDialogError",
						"Falha ao remover turnos em conflito."
				  );
			dialog.innerHTML = `
				<h3>${title}</h3>
				<p>${description}</p>
				<div class="conflict-dialog-actions">
					<button type="button" class="btn-secondary conflict-cancel">${escapeHtml(
						tLabel("conflictDialogCancel", "Cancelar")
					)}</button>
					<button type="button" class="btn-primary conflict-resolve">${resolveLabel}</button>
				</div>
			`;
			overlay.appendChild(dialog);
			document.body.appendChild(overlay);

			const cleanup = () => {
				if (overlay.parentNode) {
					overlay.parentNode.removeChild(overlay);
				}
			};

			const cancelBtn = dialog.querySelector(".conflict-cancel");
			if (cancelBtn) {
				cancelBtn.addEventListener("click", cleanup);
			}
			overlay.addEventListener("click", (evt) => {
				if (evt.target === overlay) {
					cleanup();
				}
			});
			const resolveBtn = dialog.querySelector(".conflict-resolve");
			if (resolveBtn) {
				resolveBtn.addEventListener("click", async () => {
					resolveBtn.disabled = true;
					resolveBtn.textContent = tLabel(
						"conflictDialogResolving",
						"A remover..."
					);
					try {
						await resolveConflictRecords({
							shiftIds,
							dayoffId,
							partialLeaveIds,
						});
						cleanup();
						loadData();
					} catch (error) {
						alert(
							error && error.message
								? error.message
								: errorLabel
						);
						resolveBtn.disabled = false;
						resolveBtn.textContent = resolveLabel;
					}
				});
			}
		}

		async function handleCopyCellClick(e) {
			e.stopPropagation();
			const btn = e.currentTarget;
			const empId = btn.dataset.emp;
			const dateISO = btn.dataset.date;
			if (!empId || !dateISO) return;
			await copyCellToNextDay(parseInt(empId, 10), dateISO, btn);
		}

		async function copyCellToNextDay(empId, dateISO, triggerBtn) {
			if (!canWrite) return;
			const cellShifts =
				shiftsByEmployee[empId] && shiftsByEmployee[empId][dateISO]
					? [...shiftsByEmployee[empId][dateISO]]
					: [];
			const partialLeaves = getPartialLeavesForDate(empId, dateISO);
			const fullLeave = getFullLeaveForDate(empId, dateISO);
			const dayOffInfo = getDayOffForDate(empId, dateISO);
			if (!cellShifts.length && !partialLeaves.length && !dayOffInfo && !fullLeave) {
				alert("Não existem registos para copiar.");
				return;
			}
			const targetDate = addDaysToISODate(dateISO, 1);
			if (!targetDate) {
				alert("Não foi possível identificar o dia de destino.");
				return;
			}

			const conflictReasons = new Set();
			if (dayOffInfo && cellShifts.length) {
				conflictReasons.add(
					tLabel(
						"copyConflictSourceOverlap",
						"O dia de origem contém turnos e folga. Resolva o conflito antes de copiar."
					)
				);
			}
			if (isBeforeEmployment(empId, targetDate) || isAfterEmploymentEnd(empId, targetDate)) {
				conflictReasons.add(
					tLabel(
						"copyConflictEmployment",
						"O funcionário não está ativo no dia de destino."
					)
				);
			}

			let targetSnapshot = null;
			try {
				targetSnapshot = await fetchTargetDaySnapshot(empId, targetDate);
			} catch (error) {
				alert(
					error && error.message
						? error.message
						: "Falha ao validar conflitos no dia de destino."
				);
				return;
			}
			const targetShifts = targetSnapshot && targetSnapshot.shifts ? targetSnapshot.shifts : [];
			const targetDayoff = targetSnapshot ? targetSnapshot.dayOffInfo : null;
			const targetLeaveEntries =
				targetSnapshot && Array.isArray(targetSnapshot.leaveEntries)
					? targetSnapshot.leaveEntries
					: [];
			const targetHasShift = targetShifts.length > 0;
			const targetHasDayoff = Boolean(targetDayoff);
			const targetHasLeave = targetLeaveEntries.length > 0;
			const targetHasFullLeave = targetLeaveEntries.some(
				(entry) => entry && !isLeaveEntryPartial(entry)
			);

			if (cellShifts.length) {
				if (targetHasShift) {
					conflictReasons.add(
						tLabel(
							"copyConflictTargetShift",
							"Já existem turnos no dia de destino."
						)
					);
				}
				if (targetHasDayoff) {
					conflictReasons.add(
						tLabel(
							"copyConflictTargetDayOff",
							"Já existe folga no dia de destino."
						)
					);
				}
				if (targetHasLeave) {
					const overlap = cellShifts.some((shift) => {
						const nextStart = shiftDateTimeByDays(shift.start, 1);
						const nextEnd = shiftDateTimeByDays(shift.end, 1);
						if (!nextStart || !nextEnd) return false;
						return doesShiftOverlapLeave(nextStart, nextEnd, targetLeaveEntries);
					});
					if (overlap) {
						conflictReasons.add(
							tLabel(
								"copyConflictTargetLeave",
								"Existe ausência no dia de destino."
							)
						);
					}
				}
			}

			if (dayOffInfo) {
				if (targetHasShift) {
					conflictReasons.add(
						tLabel(
							"copyConflictTargetShift",
							"Já existem turnos no dia de destino."
						)
					);
				}
				if (targetHasDayoff) {
					conflictReasons.add(
						tLabel(
							"copyConflictTargetDayOff",
							"Já existe folga no dia de destino."
						)
					);
				}
				if (targetHasLeave) {
					conflictReasons.add(
						tLabel(
							"copyConflictTargetLeave",
							"Existe ausência no dia de destino."
						)
					);
				}
			}

			if (fullLeave) {
				if (targetHasShift) {
					conflictReasons.add(
						tLabel(
							"copyConflictTargetShift",
							"Já existem turnos no dia de destino."
						)
					);
				}
				if (targetHasDayoff) {
					conflictReasons.add(
						tLabel(
							"copyConflictTargetDayOff",
							"Já existe folga no dia de destino."
						)
					);
				}
				if (targetHasLeave) {
					const otherLeave = targetLeaveEntries.find(
						(entry) =>
							entry &&
							String(entry.id || entry.rowid || "") !== String(fullLeave.id)
					);
					if (otherLeave) {
						conflictReasons.add(
							tLabel(
								"copyConflictTargetLeave",
								"Existe ausência no dia de destino."
							)
						);
					}
				}
			}

			if (partialLeaves.length) {
				if (targetHasDayoff) {
					conflictReasons.add(
						tLabel(
							"copyConflictTargetDayOff",
							"Já existe folga no dia de destino."
						)
					);
				}
				if (targetHasFullLeave) {
					conflictReasons.add(
						tLabel(
							"copyConflictTargetLeave",
							"Existe ausência no dia de destino."
						)
					);
				}
			}

			if (conflictReasons.size) {
				const header = tLabel(
					"copyBlocked",
					"Não foi possível copiar devido a conflitos."
				);
				const dateLabel = `${tLabel(
					"conflictDateLabel",
					"Data em conflito"
				)}: ${targetDate}`;
				const details = Array.from(conflictReasons)
					.map((reason) => `- ${reason}`)
					.join("\n");
				alert(`${header}\n${dateLabel}\n${details}`);
				return;
			}

			if (triggerBtn) {
				triggerBtn.disabled = true;
				triggerBtn.classList.add("busy");
			}

			try {
				for (const shift of cellShifts) {
					const nextStart = shiftDateTimeByDays(shift.start, 1);
					const nextEnd = shiftDateTimeByDays(shift.end, 1);
					if (!nextStart || !nextEnd) {
						console.warn("Ignorando turno sem datas válidas", shift);
						continue;
					}
					const label =
						shift.label ||
						(employeeMap[empId] ? employeeMap[empId].title : "Turno");
							await createShiftRequest(
								empId,
								label,
								nextStart,
								nextEnd,
								shift.taskId || "",
								shift.shift_type || shift.shiftType || "1",
								normalizeShiftStatusValue(
									shift.shift_status || shift.shiftStatus
								),
								shift.note || ""
							);
					}
				for (const leaveInfo of partialLeaves) {
					const mode = getPartialLeaveMode(leaveInfo);
					const payload = {
						user_id: empId,
						leave_type: leaveInfo.type_id || leaveInfo.fk_type || "",
						leave_start: targetDate,
						leave_end: targetDate,
						leave_halfday: mode,
						leave_description: leaveInfo.description || "",
						leave_hours_start:
							leaveInfo.hours_start || leaveInfo.leave_hours_start || "",
						leave_hours_end:
							leaveInfo.hours_end || leaveInfo.leave_hours_end || "",
						leave_minutes_total:
							leaveInfo.hours_total_minutes ??
							leaveInfo.minutes_total ??
							leaveInfo.leave_minutes_total ??
							"",
					};
					if (mode === "hours_range") {
						if (!payload.leave_hours_start) payload.leave_hours_start = "09:00";
						if (!payload.leave_hours_end) payload.leave_hours_end = "17:00";
					} else {
						payload.leave_hours_start = "";
						payload.leave_hours_end = "";
					}
					if (!payload.leave_type) {
						continue;
					}
					await createLeaveRequest(payload);
				}
				if (fullLeave) {
					const currentEndIsoRaw =
						fullLeave.end_iso ||
						fullLeave.leave_end ||
						fullLeave.date_end ||
						fullLeave.date_fin ||
						"";
					const currentEndIso = (currentEndIsoRaw || "").slice(0, 10);
					const newEndIso = currentEndIso
						? addDaysToISODate(currentEndIso, 1)
						: targetDate;
					const startIsoRaw =
						fullLeave.start_iso ||
						fullLeave.leave_start ||
						fullLeave.date_start ||
						fullLeave.date_debut ||
						targetDate;
					const startIso = (startIsoRaw || "").slice(0, 10) || targetDate;

					const payload = {
						user_id: empId,
						leave_type: fullLeave.type_id || fullLeave.fk_type || "",
						leave_start: startIso,
						leave_end: newEndIso || targetDate,
						leave_halfday: fullLeave.halfday || 0,
						leave_description: fullLeave.description || "",
						leave_hours_start: "",
						leave_hours_end: "",
						leave_minutes_total: "",
					};
					if (payload.leave_type) {
						await updateLeaveEntry(fullLeave.id, payload);
					}
				}
				if (dayOffInfo) {
					const isLockDay =
						dayOffInfo.is_lock === 1 ||
						dayOffInfo.is_lock === "1" ||
						dayOffInfo.is_lock === true ||
						dayOffInfo.entry_type === "lockday";
					await createDayOffEntry(
						empId,
						targetDate,
						Boolean(dayOffInfo.is_recurring),
						dayOffInfo.description || "",
						isLockDay
					);
				}
				await loadData();
			} catch (error) {
				console.error("Falha ao copiar turnos para o dia seguinte:", error);
				alert(error.message || "Falha ao copiar turnos.");
			} finally {
				if (triggerBtn) {
					triggerBtn.disabled = false;
					triggerBtn.classList.remove("busy");
				}
			}
		}

		function handleShiftPillClick(e) {
			e.stopPropagation();
			const pill = e.currentTarget;
			const shiftId = pill.dataset.shiftId;
			const shift = shiftMap.get(String(shiftId));
			if (!shift) return;
			const readOnlyView = !canWrite && !canDelete;
			showEditDialog(shift, readOnlyView);
		}

		function showCreateDialog(empId, dateISO) {
			const defaultStart = "09:00";
			const defaultEnd = "17:00";
			const employeeName = employeeMap[empId]
				? employeeMap[empId].title
				: "Turno";
			openShiftDialog({
				mode: "create",
				empId,
				dateISO,
				shiftId: null,
				taskId: null,
				startValue: defaultStart,
				endValue: defaultEnd,
				labelValue: employeeName,
			});
		}

			function showEditDialog(shift, readOnly = false) {
					openShiftDialog({
						mode: "edit",
						empId: shift.empId,
					dateISO: shift.date,
					shiftId: shift.id,
				taskId: shift.taskId || null,
				startValue: extractTimeFromISO(shift.start),
					endValue: extractTimeFromISO(shift.end),
					labelValue: shift.label,
						preset: {
							shiftType: shift.shift_type || shift.shiftType || "1",
							shiftStatus: shift.shift_status || shift.shiftStatus || "scheduled",
							shiftNote: shift.note || "",
						},
						readOnly,
					});
				}

		function showLeaveEditDialog(leaveInfo) {
			if (!leaveInfo) return;
			const empId = leaveInfo.empId || leaveInfo.fk_user;
			const baseDateISO =
				leaveInfo.start_iso || shiftplannerFormatISODateLocal(new Date());
			const defaultStartTime = leaveInfo.is_hours
				? leaveInfo.hours_start || secondsToTimeLabel(leaveInfo.start_ts)
				: "09:00";
			const defaultEndTime = leaveInfo.is_hours
				? leaveInfo.hours_end || secondsToTimeLabel(leaveInfo.end_ts)
				: "17:00";
			openShiftDialog({
				mode: "edit",
				empId,
				dateISO: baseDateISO,
				shiftId: null,
				taskId: null,
				startValue: defaultStartTime,
				endValue: defaultEndTime,
				labelValue: "",
				preset: {
					recordType: "leave",
					entryType: "leave",
					lockEntryType: true,
					leaveValues: leaveInfo,
				},
			});
		}

		function openShiftDialog({
			mode,
			empId,
			dateISO,
			shiftId,
			taskId,
			startValue,
			endValue,
			labelValue,
			preset = {},
			readOnly = false,
		}) {
			const isEdit = mode === "edit";
			const presetEntryType = preset.entryType || "shift";
			const lockEntryType = !!preset.lockEntryType;
			const presetLeaveValues = preset.leaveValues || null;
				const presetShiftType =
					preset.shiftType !== undefined && preset.shiftType !== null
						? String(preset.shiftType)
						: "1";
				const presetShiftStatus = normalizeShiftStatusValue(
					preset.shiftStatus !== undefined && preset.shiftStatus !== null
						? preset.shiftStatus
						: "scheduled"
				);
				const presetShiftNote =
					preset.shiftNote !== undefined && preset.shiftNote !== null
						? String(preset.shiftNote)
						: "";
			const recordType =
				preset.recordType || (presetLeaveValues ? "leave" : "shift");
			const isLeaveRecord = recordType === "leave";
			const isShiftEdit = isEdit && recordType === "shift";
			const canConvertShift = isShiftEdit && canWrite && canDelete;
			const editingLeaveId = resolveLeaveId(presetLeaveValues);
			const missedStatusFromLeave = isLeaveRecord
				? resolveMissedAbsenceStatusFromLeave(presetLeaveValues)
				: "";
			const canReconvertToShift =
				isLeaveRecord &&
				isEdit &&
				Boolean(editingLeaveId) &&
				canWrite &&
				Boolean(missedStatusFromLeave);
			const leaveCardLinkLabel = escapeHtml(
				tLabel("leaveCardLink", "Abrir ficha da ausência")
			);
			const leaveCardHref = resolveLeaveCardUrl(presetLeaveValues);
			const overlay = document.createElement("div");
			overlay.className = "shift-overlay";

			const dialog = document.createElement("div");
			dialog.className = "shift-dialog";

			const employeeName = employeeMap[empId]
				? employeeMap[empId].title
				: `Funcionário #${empId}`;
			const title = (() => {
				if (isLeaveRecord) {
					const base = isEdit
						? tLabel("editLeaveTitle", "Editar Ausência")
						: tLabel("createLeaveTitle", "Criar Ausência");
					return dateISO ? `${base} - ${dateISO}` : base;
				}
				if (presetEntryType === "dayoff") {
					const base = isEdit
						? tLabel("editDayoffTitle", "Editar Férias")
						: tLabel("createDayoffTitle", "Criar Férias");
					return dateISO ? `${base} - ${dateISO}` : base;
				}
				const base = isEdit
					? tLabel("editShiftTitle", "Editar Turno")
					: tLabel("createShiftTitle", "Criar Registo");
				return dateISO ? `${base} - ${dateISO}` : base;
			})();
			const titleHtml =
				isLeaveRecord && leaveCardHref
					? `<a class="leave-card-link" href="${escapeHtml(
							leaveCardHref
					  )}" target="_blank" rel="noopener" aria-label="${leaveCardLinkLabel}" title="${leaveCardLinkLabel}">${escapeHtml(
							title
					  )}</a>`
					: escapeHtml(title);
			const startInputValue = escapeHtml(startValue || "");
			const endInputValue = escapeHtml(endValue || "");
			const shiftStartPickerId = `shift-dialog-start-${Date.now()}-${Math.random()
				.toString(36)
				.slice(2, 7)}`;
			const shiftEndPickerId = `shift-dialog-end-${Date.now()}-${Math.random()
				.toString(36)
				.slice(2, 7)}`;
				const deleteButtonHtml =
					isEdit &&
					((recordType === "shift" && canDelete) ||
						(recordType === "leave" && canWrite))
						? '<button class="btn-delete">Eliminar</button>'
						: "";
				const reconvertIconTooltip = escapeHtml(
					tLabel(
						"reconvertToShiftTooltip",
						"Reconvert absence to scheduled shift"
					)
				);
				const reconvertButtonHtml = canReconvertToShift
					? `<button type="button" class="btn-reconvert-shift" title="${reconvertIconTooltip}" aria-label="${reconvertIconTooltip}"><i class="fas fa-undo-alt" aria-hidden="true"></i></button>`
					: "";
			const leaveTypeOptions = leaveTypes.length
				? leaveTypes
						.map(
							(type) =>
								`<option value="${type.id}">${escapeHtml(type.label)}</option>`
						)
						.join("")
				: '<option value="">Sem tipos disponíveis</option>';
			const allowEntryTypeSwitch = !lockEntryType && (!isEdit || canConvertShift);
			const entryTypeWrapperStyle = allowEntryTypeSwitch ? "" : 'style="display:none;"';
			const optionShiftLabel = escapeHtml(tLabel("optionShift", "Turno"));
			const optionDayOffLabel = escapeHtml(tLabel("optionDayOff", "Férias"));
			const optionLockDayLabel = escapeHtml(
				tLabel("optionLockDay", "Dia não laboral")
			);
			const optionLeaveLabel = escapeHtml(tLabel("optionLeave", "Ausência"));
			const entryTypeOptions = canConvertShift
				? `<option value="shift">${optionShiftLabel}</option>
						<option value="leave">${optionLeaveLabel}</option>`
				: `<option value="shift">${optionShiftLabel}</option>
						<option value="dayoff">${optionDayOffLabel}</option>
						<option value="lockday">${optionLockDayLabel}</option>
						<option value="leave">${optionLeaveLabel}</option>`;
				dialog.innerHTML = `
					<div class="shift-dialog-header">
						<h3 class="shift-dialog-title">${titleHtml}</h3>
						${reconvertButtonHtml}
					</div>
					<label>Funcionário</label>
					<input type="text" value="${escapeHtml(
						employeeName
					)}" disabled style="background:#f5f5f5;">

				<div class="form-group" ${entryTypeWrapperStyle}>
					<label>Tipo de registo</label>
					<select id="entry_type">
						${entryTypeOptions}
					</select>
				</div>

				<div id="leave_partial_group" style="display:none;">
					<label>Falta parcial</label>
					<select id="leave_halfday">
						<option value="0">Dia completo</option>
						<option value="hours_range">${escapeHtml(
							tLabel("leaveHoursOption", "Período horário (hh:mm a hh:mm)")
						)}</option>
						<option value="minutes_total">${escapeHtml(
							tLabel("leaveMinutesOption", "Tempo (minutos)")
						)}</option>
					</select>
				</div>

				<div id="shift_fields">
					<label>Tarefa</label>
					<div style="display:flex; gap:10px; align-items:center; margin-bottom:4px;">
						<select id="dialog-task" style="flex:1; padding:6px;"></select>
					</div>

						<label>Tipo de turno</label>
						<select id="shift_type" style="padding:6px; margin-bottom:4px;">
							<option value="1">Turno Normal</option>
							<option value="2">Turno Especial</option>
						</select>

						<label>${escapeHtml(
							tLabel("shiftStatusLabel", "Shift status")
						)}</label>
						<select id="shift_status" style="padding:6px; margin-bottom:4px;">
							<option value="scheduled">${escapeHtml(
								tLabel("shiftStatusScheduled", "Scheduled")
							)}</option>
							<option value="missed_unjustified">${escapeHtml(
								tLabel(
									"shiftStatusMissedUnjustified",
									"Unjustified absence"
								)
							)}</option>
							<option value="missed_justified">${escapeHtml(
								tLabel("shiftStatusMissedJustified", "Justified absence")
							)}</option>
						</select>

						<div style="display:flex; gap:10px;">
						<div class="timepicker-column">
							<label>Hora de início</label>
			<div class="input-group input-group-sm date timepicker-control" id="${shiftStartPickerId}" data-target-input="nearest">
				<input type="text" class="form-control form-control-sm datetimepicker-input" id="dialog-start" autocomplete="off" value="${startInputValue}">
							</div>
						</div>
						<div class="timepicker-column">
							<label>Hora de fim</label>
			<div class="input-group input-group-sm date timepicker-control" id="${shiftEndPickerId}" data-target-input="nearest">
				<input type="text" class="form-control form-control-sm datetimepicker-input" id="dialog-end" autocomplete="off" value="${endInputValue}">
							</div>
						</div>
					</div>
						<label>${escapeHtml(
							tLabel("shiftDescriptionLabel", "Description")
						)}</label>
						<textarea id="shift_note" rows="2"></textarea>
				</div>

				<div id="dayoff_fields" style="display:none;">
					<label>Data</label>
					<input type="date" id="dayoff_date" value="${dateISO}">
					<label>Nota (opcional)</label>
					<textarea id="dayoff_description" rows="2"></textarea>
				</div>

				<div id="leave_fields" style="display:none;">
					<label>Tipo de ausência</label>
					<select id="leave_type">${leaveTypeOptions}</select>
					<div id="leave_start_group">
						<label>Data inicial</label>
						<input type="date" id="leave_start" value="${dateISO}">
					</div>
					<div id="leave_end_group">
						<label>Data final</label>
						<input type="date" id="leave_end" value="${dateISO}">
					</div>
					<div id="leave_hours_fields" style="display:none; margin-top:6px;">
						<label>${escapeHtml(tLabel("leaveHoursStart", "Hora inicial"))}</label>
						<input type="time" id="leave_hours_start">
						<label>${escapeHtml(tLabel("leaveHoursEnd", "Hora final"))}</label>
						<input type="time" id="leave_hours_end">
					</div>
					<div id="leave_minutes_fields" style="display:none; margin-top:6px;">
						<label>${escapeHtml(tLabel("leaveMinutesLabel", "Tempo (minutos)"))}</label>
						<input type="number" id="leave_minutes_total" min="1" step="1" placeholder="30">
					</div>
					<label>Descrição</label>
					<textarea id="leave_description" rows="1"></textarea>
				</div>

					<div style="margin-top:15px; text-align:right;">
						<button class="btn-save">Guardar</button>
						${deleteButtonHtml}
						<button class="btn-cancel">Cancelar</button>
					</div>
				`;

			document.body.appendChild(overlay);
			document.body.appendChild(dialog);

			const taskSelect = dialog.querySelector("#dialog-task");
			const startInput = dialog.querySelector("#dialog-start");
			const endInput = dialog.querySelector("#dialog-end");
			const entryTypeSelect = dialog.querySelector("#entry_type");
			const shiftFields = dialog.querySelector("#shift_fields");
			const dayoffFields = dialog.querySelector("#dayoff_fields");
			const leaveFields = dialog.querySelector("#leave_fields");
			const dayoffDateInput = dialog.querySelector("#dayoff_date");
			const dayoffDescriptionInput = dialog.querySelector(
				"#dayoff_description"
			);
			const leaveTypeSelect = dialog.querySelector("#leave_type");
			const leavePartialGroup = dialog.querySelector("#leave_partial_group");
			const leaveStartInput = dialog.querySelector("#leave_start");
			const leaveEndInput = dialog.querySelector("#leave_end");
			const leaveStartGroup = dialog.querySelector("#leave_start_group");
			const leaveEndGroup = dialog.querySelector("#leave_end_group");
			const leaveHalfdaySelect = dialog.querySelector("#leave_halfday");
			const leaveDescriptionInput = dialog.querySelector("#leave_description");
			const leaveHoursFields = dialog.querySelector("#leave_hours_fields");
			const leaveMinutesFields = dialog.querySelector("#leave_minutes_fields");
				const leaveHoursStartInput = dialog.querySelector("#leave_hours_start");
				const leaveHoursEndInput = dialog.querySelector("#leave_hours_end");
				const leaveMinutesInput = dialog.querySelector("#leave_minutes_total");
				const shiftTypeSelect = dialog.querySelector("#shift_type");
				const shiftStatusSelect = dialog.querySelector("#shift_status");
				const shiftNoteInput = dialog.querySelector("#shift_note");
				const btnSave = dialog.querySelector(".btn-save");
				const btnReconvertShift = dialog.querySelector(".btn-reconvert-shift");
			const btnDelete = dialog.querySelector(".btn-delete");
			const btnCancel = dialog.querySelector(".btn-cancel");
			const existingShift = shiftId ? shiftMap.get(String(shiftId)) : null;
			let hasPrefilledLeaveFromShift = false;
			initializeTimePicker(shiftStartPickerId, startValue || "09:00", dialog);
			initializeTimePicker(shiftEndPickerId, endValue || "17:00", dialog);

			populateTaskSelect(taskSelect, taskId, empId);

				if (readOnly) {
					// Disable all form fields for view-only mode
					dialog
						.querySelectorAll(
							"input, select, textarea, button.btn-delete, button.btn-reconvert-shift"
						)
						.forEach((el) => {
							if (el.classList.contains("btn-cancel")) return;
							el.disabled = true;
						});
					if (btnSave) {
						btnSave.style.display = "none";
					}
					if (btnReconvertShift) {
						btnReconvertShift.style.display = "none";
					}
					if (btnDelete) {
						btnDelete.style.display = "none";
					}
				}

			function setEntryVisibility(type) {
				if (shiftFields)
					shiftFields.style.display = type === "shift" ? "block" : "none";
				if (dayoffFields)
					dayoffFields.style.display =
						type === "dayoff" || type === "lockday" ? "block" : "none";
				if (leaveFields)
					leaveFields.style.display = type === "leave" ? "block" : "none";
				if (leavePartialGroup)
					leavePartialGroup.style.display = type === "leave" ? "block" : "none";
			}

			function isPartialModeValue(mode) {
				return (
					mode === "hours_range" ||
					mode === "minutes_total" ||
					mode === "halfday" ||
					mode === "1" ||
					mode === "-1"
				);
			}

			function updateLeaveHoursVisibility() {
				if (!leaveHalfdaySelect) return;
				const mode = normalizePartialMode(leaveHalfdaySelect.value);
				if (leaveHoursFields) {
					leaveHoursFields.style.display =
						mode === "hours_range" ? "block" : "none";
				}
				if (leaveMinutesFields) {
					leaveMinutesFields.style.display =
						mode === "minutes_total" ? "block" : "none";
				}
				updatePartialLeaveVisibility();
			}

			function syncPartialLeaveDate() {
				if (!leaveStartInput || !leaveEndInput || !leaveHalfdaySelect) return;
				const mode = normalizePartialMode(leaveHalfdaySelect.value);
				const isPartial = isPartialModeValue(mode);
				if (isPartial) {
					leaveEndInput.value = leaveStartInput.value;
				}
			}

			function updatePartialLeaveVisibility() {
				if (!leaveHalfdaySelect) return;
				const mode = normalizePartialMode(leaveHalfdaySelect.value);
				const isPartial = isPartialModeValue(mode);
				if (leaveEndGroup) {
					leaveEndGroup.style.display = isPartial ? "none" : "block";
				}
				if (isPartial) {
					syncPartialLeaveDate();
				}
			}

			function prefillLeaveFromShift() {
				if (
					!isShiftEdit ||
					hasPrefilledLeaveFromShift ||
					!leaveStartInput ||
					!leaveEndInput ||
					!leaveHalfdaySelect
				) {
					return;
				}
				leaveStartInput.value = dateISO || leaveStartInput.value;
				leaveEndInput.value = dateISO || leaveEndInput.value;
				const startTimeValue = startInput
					? normalizeTimeString(startInput.value)
					: "";
				const endTimeValue = endInput ? normalizeTimeString(endInput.value) : "";
				if (startTimeValue && endTimeValue) {
					if (compareTimeStrings(endTimeValue, startTimeValue) > 0) {
						leaveHalfdaySelect.value = "hours_range";
						if (leaveHoursStartInput)
							leaveHoursStartInput.value = startTimeValue;
						if (leaveHoursEndInput) leaveHoursEndInput.value = endTimeValue;
						if (leaveMinutesInput) leaveMinutesInput.value = "";
					} else {
						const { startDateTime, endDateTime } = buildShiftDateTimes(
							dateISO,
							startTimeValue,
							endTimeValue
						);
						const minutes = getShiftDurationMinutes(startDateTime, endDateTime);
						leaveHalfdaySelect.value = "minutes_total";
						if (leaveMinutesInput && minutes > 0) {
							leaveMinutesInput.value = String(Math.round(minutes));
						}
						if (leaveHoursStartInput) leaveHoursStartInput.value = "";
						if (leaveHoursEndInput) leaveHoursEndInput.value = "";
					}
				}
				hasPrefilledLeaveFromShift = true;
				updateLeaveHoursVisibility();
				updatePartialLeaveVisibility();
			}

			function collectLeaveFormData() {
				if (
					!leaveTypeSelect ||
					!leaveStartInput ||
					!leaveEndInput ||
					!leaveHalfdaySelect
				) {
					alert(tLabel("selectLeaveType", "Selecione o tipo de ausência."));
					return null;
				}
				const leaveTypeValue = leaveTypeSelect.value;
				const leaveStartValue = leaveStartInput.value;
				let leaveEndValue = leaveEndInput.value;
				const leaveHalfdayValue = leaveHalfdaySelect.value || "0";
				let leaveHoursStartValue = leaveHoursStartInput
					? leaveHoursStartInput.value
					: "";
				let leaveHoursEndValue = leaveHoursEndInput
					? leaveHoursEndInput.value
					: "";
				let leaveMinutesTotalValue = leaveMinutesInput
					? leaveMinutesInput.value
					: "";

				if (!leaveTypeValue) {
					alert(tLabel("selectLeaveType", "Selecione o tipo de ausência."));
					return null;
				}
				if (!leaveStartValue || !leaveEndValue) {
					alert(tLabel("fillLeaveDates", "Preencha as datas de início e fim."));
					return null;
				}
				const startDateObj = new Date(`${leaveStartValue}T00:00:00`);
				const endDateObj = new Date(`${leaveEndValue}T00:00:00`);
				if (endDateObj < startDateObj) {
					alert(
						tLabel(
							"invalidLeaveRange",
							"A data final deve ser posterior ou igual à data inicial."
						)
					);
					return null;
				}
				const normalizedHalfday =
					normalizePartialMode(leaveHalfdayValue) || "0";
				const isPartialMode = isPartialModeValue(normalizedHalfday);
				if (isPartialMode) {
					leaveEndValue = leaveStartValue;
					if (leaveEndInput) leaveEndInput.value = leaveStartValue;
				}
				if (normalizedHalfday === "hours_range") {
					if (!leaveHoursStartValue || !leaveHoursEndValue) {
						alert(
							tLabel(
								"leaveHoursRequired",
								"Indique o intervalo de horas da ausência."
							)
						);
						return null;
					}
					leaveMinutesTotalValue = "";
				} else if (normalizedHalfday === "minutes_total") {
					leaveHoursStartValue = "";
					leaveHoursEndValue = "";
					const parsedMinutes = parseInt(leaveMinutesTotalValue, 10);
					if (!Number.isFinite(parsedMinutes) || parsedMinutes <= 0) {
						alert(
							tLabel(
								"leaveMinutesRequired",
								"Indique o total de minutos da ausência."
							)
						);
						return null;
					}
					leaveMinutesTotalValue = String(parsedMinutes);
				} else {
					leaveHoursStartValue = "";
					leaveHoursEndValue = "";
					leaveMinutesTotalValue = "";
				}

				return {
					leave_type: leaveTypeValue,
					leave_start: leaveStartValue,
					leave_end: leaveEndValue,
					leave_halfday: normalizedHalfday,
					leave_description: leaveDescriptionInput
						? leaveDescriptionInput.value.trim()
						: "",
					leave_hours_start: leaveHoursStartValue,
					leave_hours_end: leaveHoursEndValue,
					leave_minutes_total: leaveMinutesTotalValue,
				};
			}

			if (entryTypeSelect) {
				entryTypeSelect.value = presetEntryType;
				if (!allowEntryTypeSwitch) {
					entryTypeSelect.disabled = true;
				}
				entryTypeSelect.addEventListener("change", () => {
					setEntryVisibility(entryTypeSelect.value);
					if (entryTypeSelect.value === "leave") {
						prefillLeaveFromShift();
					}
				});
				setEntryVisibility(entryTypeSelect.value);
			} else {
				setEntryVisibility(presetEntryType);
			}

			if (leaveHalfdaySelect) {
				leaveHalfdaySelect.addEventListener("change", () => {
					const normalized = normalizePartialMode(leaveHalfdaySelect.value);
					leaveHalfdaySelect.value = normalized || "0";
					updateLeaveHoursVisibility();
					updatePartialLeaveVisibility();
				});
				updateLeaveHoursVisibility();
			}
			if (leaveStartInput) {
				leaveStartInput.addEventListener("change", () => {
					syncPartialLeaveDate();
				});
			}

			if (presetLeaveValues) {
				const presetMinutesTotal =
					parseInt(presetLeaveValues.hours_total_minutes, 10) || 0;
				const hasMinutesOnly =
					(!presetLeaveValues.hours_range ||
						presetLeaveValues.hours_range === "") &&
					presetMinutesTotal > 0;
				if (leaveTypeSelect) {
					leaveTypeSelect.value = String(presetLeaveValues.type_id || "");
				}
				if (leaveStartInput) {
					leaveStartInput.value = presetLeaveValues.start_iso || dateISO;
				}
				if (leaveEndInput) {
					leaveEndInput.value = presetLeaveValues.end_iso || dateISO;
				}
				if (leaveHalfdaySelect) {
					let targetHalfdayValue = "0";
					if (hasMinutesOnly) {
						targetHalfdayValue = "minutes_total";
					} else if (presetLeaveValues.is_hours) {
						targetHalfdayValue = "hours_range";
					} else {
						targetHalfdayValue =
							normalizePartialMode(presetLeaveValues.halfday || 0) || "0";
					}
					const optionExists = Array.from(leaveHalfdaySelect.options).some(
						(opt) => opt.value === targetHalfdayValue
					);
					leaveHalfdaySelect.value = optionExists ? targetHalfdayValue : "0";
				}
				if (leaveDescriptionInput) {
					leaveDescriptionInput.value = presetLeaveValues.description || "";
				}
				if (leaveHoursStartInput) {
					leaveHoursStartInput.value =
						presetLeaveValues.hours_start ||
						secondsToTimeLabel(presetLeaveValues.start_ts) ||
						"";
				}
				if (leaveHoursEndInput) {
					leaveHoursEndInput.value =
						presetLeaveValues.hours_end ||
						secondsToTimeLabel(presetLeaveValues.end_ts) ||
						"";
				}
				if (leaveMinutesInput) {
					leaveMinutesInput.value = hasMinutesOnly
						? String(presetMinutesTotal)
						: "";
				}
				updateLeaveHoursVisibility();
				updatePartialLeaveVisibility();
			}

				if (shiftTypeSelect) {
					shiftTypeSelect.value = presetShiftType || "1";
				}
				if (shiftStatusSelect) {
					shiftStatusSelect.value = presetShiftStatus;
				}
				if (shiftNoteInput) {
					shiftNoteInput.value = presetShiftNote;
				}

			function cleanup() {
				document.body.removeChild(dialog);
				document.body.removeChild(overlay);
			}

			async function convertShiftToLeave(leavePayload, onDone) {
				if (!shiftId || !canConvertShift) {
					alert(
						tLabel(
							"convertLeaveNoDelete",
							"Não tem permissão para converter este turno."
						)
					);
					return;
				}
				if (!existingShift || !existingShift.start || !existingShift.end) {
					alert(
						tLabel(
							"convertLeaveMissingShift",
							"Não foi possível localizar o turno para converter."
						)
					);
					return;
				}
				let shiftDeleted = false;
				if (btnSave) {
					btnSave.disabled = true;
				}
				try {
					await deleteShiftRequest(shiftId);
					shiftDeleted = true;
					await createLeaveEntry(empId, leavePayload);
					if (typeof onDone === "function") {
						onDone();
					}
				} catch (error) {
					if (shiftDeleted) {
						const restoreShiftType = normalizeShiftTypeValue(
							existingShift.shift_type || existingShift.shiftType
						);
						try {
								await createShiftRequest(
									empId,
									existingShift.label || "",
									existingShift.start,
									existingShift.end,
									existingShift.taskId || "",
									String(restoreShiftType || 1),
									normalizeShiftStatusValue(
										existingShift.shift_status || existingShift.shiftStatus
									),
									existingShift.note || ""
								);
						} catch (restoreError) {
							console.error(
								"Falha ao restaurar turno após conversão:",
								restoreError
							);
						}
					}
					alert(
						error && error.message
							? error.message
							: tLabel(
									"convertLeaveError",
									"Falha ao converter turno em ausência."
							  )
					);
					if (shiftDeleted) {
						loadData();
					}
				} finally {
					if (btnSave) {
						btnSave.disabled = false;
					}
				}
			}

			btnSave.addEventListener("click", async () => {
				const entryType = editingLeaveId
					? "leave"
					: entryTypeSelect
					? entryTypeSelect.value
					: "shift";
				const onDone = () => {
					cleanup();
					loadData();
				};

				if ((entryType === "dayoff" || entryType === "lockday") && !editingLeaveId) {
					const dayoffDateValue = dayoffDateInput ? dayoffDateInput.value : "";
					if (!dayoffDateValue) {
						alert(tLabel("selectDayOffDate", "Selecione a data das Férias."));
						return;
					}
					if (hasShiftsOnDate(empId, dayoffDateValue)) {
						alert(
							tLabel(
								"dayoffShiftConflict",
								"Já existe um turno planeado nesta data. Remova o turno antes de marcar férias."
							)
						);
						return;
					}
					try {
						await createDayOffEntry(
							empId,
							dayoffDateValue,
							false,
							dayoffDescriptionInput ? dayoffDescriptionInput.value.trim() : "",
							entryType === "lockday"
						);
						onDone();
					} catch (error) {
						alert(error.message);
					}
					return;
				}

				if (entryType === "leave") {
					const leavePayload = collectLeaveFormData();
					if (!leavePayload) return;
					leavePayload.user_id = empId;
					if (canConvertShift && isShiftEdit && !editingLeaveId) {
						await convertShiftToLeave(leavePayload, onDone);
						return;
					}
					const isPartialLeave = isPartialLeaveSelection(
						leavePayload.leave_halfday
					);
					if (!isPartialLeave) {
						const conflictDate = findShiftConflictInRange(
							empId,
							leavePayload.leave_start,
							leavePayload.leave_end
						);
						if (conflictDate) {
							const conflictMessage = tLabel(
								"leaveShiftConflict",
								"Já existe um turno planeado nas datas selecionadas. Remova o turno antes de criar a ausência."
							);
							alert(
								`${conflictMessage}\n${tLabel(
									"conflictDateLabel",
									"Data em conflito"
								)}: ${conflictDate}`
							);
							return;
						}
					}
					try {
						if (editingLeaveId) {
							await updateLeaveEntry(editingLeaveId, leavePayload);
						} else {
							await createLeaveEntry(empId, leavePayload);
						}
						onDone();
					} catch (error) {
						alert(error.message);
					}
					return;
				}

				const startTimeValue = startInput
					? normalizeTimeString(startInput.value)
					: "";
					const endTimeValue = endInput
						? normalizeTimeString(endInput.value)
						: "";
					const taskIdValue = taskSelect ? taskSelect.value : "";
					const shiftTypeValue = shiftTypeSelect ? shiftTypeSelect.value : "1";
					const shiftStatusValue = shiftStatusSelect
						? normalizeShiftStatusValue(shiftStatusSelect.value)
						: "scheduled";
					const shiftNoteValue = shiftNoteInput
						? shiftNoteInput.value.trim()
						: "";

					if (!startTimeValue || !endTimeValue) {
						alert("Preencha as horas de início e fim.");
						return;
					}
					if (!taskIdValue && !isMissedAbsenceStatus(shiftStatusValue)) {
						alert("Selecione uma tarefa.");
						return;
					}

					let startDateTime = "";
					let endDateTime = "";
					const existingStartRaw =
						isEdit && existingShift && existingShift.start
							? String(existingShift.start).replace(" ", "T")
							: "";
					const existingEndRaw =
						isEdit && existingShift && existingShift.end
							? String(existingShift.end).replace(" ", "T")
							: "";
					const existingStartTime = existingStartRaw
						? normalizeTimeString(extractTimeFromISO(existingStartRaw))
						: "";
					const existingEndTime = existingEndRaw
						? normalizeTimeString(extractTimeFromISO(existingEndRaw))
						: "";
					const hasUnchangedTimes =
						isEdit &&
						existingStartRaw &&
						existingEndRaw &&
						startTimeValue === existingStartTime &&
						endTimeValue === existingEndTime;
					if (hasUnchangedTimes) {
						startDateTime =
							existingStartRaw.length === 16
								? `${existingStartRaw}:00`
								: existingStartRaw;
						endDateTime =
							existingEndRaw.length === 16 ? `${existingEndRaw}:00` : existingEndRaw;
					} else {
						const shiftBaseDateISO =
							isEdit && existingStartRaw
								? existingStartRaw.slice(0, 10)
								: dateISO;
						const builtDateTimes = buildShiftDateTimes(
							shiftBaseDateISO,
							startTimeValue,
							endTimeValue
						);
						startDateTime = builtDateTimes.startDateTime;
						endDateTime = builtDateTimes.endDateTime;
					}
					if (!startDateTime || !endDateTime) {
						alert("Horário inválido.");
						return;
					}
				if (
					!shiftplannerMatchesMinuteStep(startDateTime) ||
					!shiftplannerMatchesMinuteStep(endDateTime)
				) {
					alert(
						`Os horários devem respeitar o intervalo configurado de ${SHIFT_MINUTE_STEP} minutos.`
					);
					return;
				}
					if (startDateTime >= endDateTime) {
						alert("A hora de fim deve ser posterior ao início.");
						return;
					}

					if (isMissedAbsenceStatus(shiftStatusValue)) {
						if (!confirmMissedAbsenceConversion(shiftStatusValue)) {
							return;
						}
						const leaveConversion = buildMissedAbsenceLeavePayload(
							shiftStatusValue,
							empId,
							dateISO,
							startDateTime,
							endDateTime,
							shiftNoteValue
						);
						if (!leaveConversion || !leaveConversion.payload) {
							alert(
								(leaveConversion && leaveConversion.error) ||
									tLabel(
										"convertLeaveError",
										"Falha ao converter turno em ausência."
									)
							);
							return;
						}
						if (isEdit && shiftId) {
							try {
								await convertShiftToLeaveRequest(
									shiftId,
									leaveConversion.payload
								);
								onDone();
							} catch (error) {
								alert(
									error && error.message
										? error.message
										: tLabel(
												"convertLeaveError",
												"Falha ao converter turno em ausência."
										  )
								);
							}
							return;
						}
						try {
							await createLeaveEntry(empId, leaveConversion.payload);
							onDone();
						} catch (error) {
							alert(
								error && error.message
									? error.message
									: tLabel(
											"errorCreateLeave",
											"Erro ao criar ausência."
									  )
							);
						}
						return;
					}

						if (isEdit && shiftId) {
								updateShift(
									shiftId,
								empId,
								"",
								startDateTime,
								endDateTime,
								taskIdValue,
								shiftTypeValue,
								shiftStatusValue,
								onDone,
								shiftNoteValue
							);
						} else {
							createShift(
								empId,
								"",
							startDateTime,
							endDateTime,
								taskIdValue,
								shiftTypeValue,
								shiftStatusValue,
								onDone,
								shiftNoteValue
							);
						}
					});

				if (btnReconvertShift) {
					btnReconvertShift.addEventListener("click", async () => {
						if (!editingLeaveId || !missedStatusFromLeave) {
							return;
						}
						if (!confirmReconvertToShift(missedStatusFromLeave)) {
							return;
						}
						if (btnReconvertShift) {
							btnReconvertShift.disabled = true;
						}
						if (btnSave) {
							btnSave.disabled = true;
						}
						if (btnDelete) {
							btnDelete.disabled = true;
						}
							try {
								const employeeMainTaskId = normalizeMainTaskId(
									employeeMap[empId] &&
										(employeeMap[empId].mainTaskId !== undefined
											? employeeMap[empId].mainTaskId
											: employeeMap[empId].main_task_id)
								);
								await convertLeaveToShiftRequest(editingLeaveId, {
									shift_type: "1",
									fk_task: employeeMainTaskId || "",
									shift_note: leaveDescriptionInput
										? leaveDescriptionInput.value.trim()
										: "",
								});
							cleanup();
							loadData();
						} catch (error) {
							alert(
								error && error.message
									? error.message
									: tLabel(
											"reconvertToShiftError",
											"Failed to reconvert absence to shift."
									  )
							);
						} finally {
							if (btnReconvertShift) {
								btnReconvertShift.disabled = false;
							}
							if (btnSave) {
								btnSave.disabled = false;
							}
							if (btnDelete) {
								btnDelete.disabled = false;
							}
						}
					});
				}

				if (btnDelete) {
					if (editingLeaveId) {
						btnDelete.addEventListener("click", async () => {
						if (!confirm("Eliminar esta ausência?")) return;
						try {
							await deleteLeaveEntry(editingLeaveId);
							cleanup();
							loadData();
						} catch (error) {
							alert(error.message || "Erro ao eliminar ausência.");
						}
					});
				} else if (shiftId) {
					btnDelete.addEventListener("click", () => {
						if (!confirm("Eliminar este turno?")) return;
						deleteShift(shiftId, () => {
							cleanup();
							loadData();
						});
					});
				}
			}

			btnCancel.addEventListener("click", cleanup);
			overlay.addEventListener("click", cleanup);
			if (
				startInput &&
				(!entryTypeSelect || entryTypeSelect.value === "shift")
			) {
				startInput.focus();
			} else if (
				dayoffDateInput &&
				entryTypeSelect &&
				(entryTypeSelect.value === "dayoff" ||
					entryTypeSelect.value === "lockday")
			) {
				dayoffDateInput.focus();
			} else if (
				leaveTypeSelect &&
				entryTypeSelect &&
				entryTypeSelect.value === "leave"
			) {
				leaveTypeSelect.focus();
			}
		}

		function openTaskManager(onClose) {
			const existing = document.getElementById("task-manager-overlay");
			if (existing) existing.remove();

			const overlay = document.createElement("div");
			overlay.id = "task-manager-overlay";
			overlay.style.position = "fixed";
			overlay.style.top = "0";
			overlay.style.left = "0";
			overlay.style.width = "100%";
			overlay.style.height = "100%";
			overlay.style.background = "rgba(0,0,0,0.5)";
			overlay.style.zIndex = "20000";
			overlay.style.display = "flex";
			overlay.style.alignItems = "center";
			overlay.style.justifyContent = "center";

			const dialog = document.createElement("div");
			dialog.style.background = "#fff";
			dialog.style.padding = "20px";
			dialog.style.borderRadius = "8px";
			dialog.style.minWidth = "420px";
			dialog.style.maxWidth = "520px";
			dialog.style.maxHeight = "80vh";
			dialog.style.overflowY = "auto";
			dialog.style.zIndex = "20001";

			dialog.innerHTML = `
    			<h3>Gerir Tarefas</h3>
    			<div id="task-manager-list"></div>
    			<div style="margin-top: 15px;">
    				<h4>Nova tarefa</h4>
    				<div style="display: flex; gap: 8px; align-items: center;">
    					<input type="text" id="task-new-name" placeholder="Nome da tarefa" style="flex: 1; padding: 6px;">
    					<input type="color" id="task-new-color" value="#3788d8" style="width: 60px; height: 34px; border: none;">
    					<input type="color" id="task-new-text-color" value="#ffffff" style="width: 60px; height: 34px; border: none;" title="Cor do texto">
    					<div id="task-new-preview" class="task-color-preview">Aa</div>
    					<button id="task-add-btn" style="padding: 8px 12px;">Adicionar</button>
    				</div>
    			</div>
    			<div style="text-align: right; margin-top: 16px;">
    				<button id="task-close-btn" style="padding: 8px 16px;">Fechar</button>
    			</div>
    		`;

			overlay.appendChild(dialog);
			document.body.appendChild(overlay);

			const taskList = dialog.querySelector("#task-manager-list");
			const addBtn = dialog.querySelector("#task-add-btn");
			const newNameInput = dialog.querySelector("#task-new-name");
			const newColorInput = dialog.querySelector("#task-new-color");
			const newTextColorInput = dialog.querySelector("#task-new-text-color");
			const newPreview = dialog.querySelector("#task-new-preview");
			const closeBtn = dialog.querySelector("#task-close-btn");

			const refreshTables = () => {
				renderLegend();
				renderTable();
			};

			const updateNewPreview = () => {
				if (!newPreview) return;
				const bg = newColorInput ? newColorInput.value || "#3788d8" : "#3788d8";
				const textColor = newTextColorInput
					? newTextColorInput.value || DEFAULT_TASK_TEXT_COLOR
					: DEFAULT_TASK_TEXT_COLOR;
				newPreview.style.backgroundColor = bg;
				newPreview.style.color = textColor;
			};
			updateNewPreview();
			if (newColorInput) {
				newColorInput.addEventListener("input", updateNewPreview);
			}
			if (newTextColorInput) {
				newTextColorInput.addEventListener("input", updateNewPreview);
			}

			const renderRows = () => {
				if (!tasks.length) {
					taskList.innerHTML =
						'<div style="padding: 8px; font-style: italic; color: #555;">Nenhuma tarefa definida.</div>';
					return;
				}
				taskList.innerHTML = tasks
					.map(
						(task) => `
    				<div class="task-row" data-id="${
							task.id
						}" style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
    					<input type="text" class="task-name" value="${escapeHtml(
								task.label
							)}" style="flex:1; padding:6px;">
    					<input type="color" class="task-color" value="${
								task.color || "#3788d8"
							}" style="width:60px; height:34px; border:none;">
    					<input type="color" class="task-text-color" value="${
								task.textColor || DEFAULT_TASK_TEXT_COLOR
							}" style="width:60px; height:34px; border:none;" title="Cor do texto">
    					<div class="task-color-preview" style="opacity:0.55;">Aa</div>
    					<button class="task-save" style="padding:6px 10px;">Guardar</button>
    					<button class="task-delete" style="padding:6px 10px; background:#d9534f; color:#fff; border:none;">Eliminar</button>
    				</div>
    			`
					)
					.join("");

				taskList.querySelectorAll(".task-save").forEach((btn) => {
					btn.addEventListener("click", async () => {
						const row = btn.closest(".task-row");
						const id = row.dataset.id;
						const name = row.querySelector(".task-name").value.trim();
						const color = row.querySelector(".task-color").value;
						const textColorInput = row.querySelector(".task-text-color");
						const textColor = textColorInput
							? textColorInput.value
							: DEFAULT_TASK_TEXT_COLOR;
						if (!name) {
							alert("Nome da tarefa obrigatório.");
							return;
						}
						try {
							await updateTaskApi(id, name, color, textColor);
							await fetchTasks();
							renderRows();
							refreshTables();
						} catch (error) {
							alert(error.message);
						}
					});
				});

				taskList.querySelectorAll(".task-delete").forEach((btn) => {
					btn.addEventListener("click", async () => {
						const row = btn.closest(".task-row");
						const id = row.dataset.id;
						if (!confirm("Eliminar esta tarefa?")) return;
						try {
							await deleteTaskApi(id);
							await fetchTasks();
							renderRows();
							refreshTables();
						} catch (error) {
							alert(error.message);
						}
					});
				});
			};

			renderRows();

			addBtn.addEventListener("click", async () => {
				const name = newNameInput.value.trim();
				const color = newColorInput.value;
				const textColor = newTextColorInput
					? newTextColorInput.value
					: DEFAULT_TASK_TEXT_COLOR;
				if (!name) {
					alert("Introduza o nome da tarefa.");
					return;
				}
				try {
					await createTaskApi(name, color, textColor);
					newNameInput.value = "";
					if (newTextColorInput) {
						newTextColorInput.value = DEFAULT_TASK_TEXT_COLOR;
					}
					updateNewPreview();
					await fetchTasks();
					renderRows();
					refreshTables();
				} catch (error) {
					alert(error.message);
				}
			});

			const closeManager = () => {
				overlay.remove();
				if (typeof onClose === "function") {
					onClose();
				}
			};

			closeBtn.addEventListener("click", closeManager);
			overlay.addEventListener("click", (e) => {
				if (e.target === overlay) {
					closeManager();
				}
			});
		}

		async function createTaskApi(label, color, textColor) {
			const resp = await fetch(apiUrl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "task_create",
					label: label,
					color: color,
					text_color: textColor || DEFAULT_TASK_TEXT_COLOR,
				}),
			});
			const result = await resp.json();
			if (!result.success) {
				throw new Error(
					translateApiError(result.error, "Erro ao criar tarefa")
				);
			}
		}

		async function updateTaskApi(id, label, color, textColor) {
			const resp = await fetch(apiUrl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "task_update",
					id: id,
					label: label,
					color: color,
					text_color: textColor || DEFAULT_TASK_TEXT_COLOR,
				}),
			});
			const result = await resp.json();
			if (!result.success) {
				throw new Error(
					translateApiError(result.error, "Erro ao atualizar tarefa")
				);
			}
		}

		async function deleteTaskApi(id) {
			const resp = await fetch(apiUrl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "task_delete",
					id: id,
				}),
			});
			const result = await resp.json();
			if (!result.success) {
				throw new Error(
					translateApiError(result.error, "Erro ao eliminar tarefa")
				);
			}
		}

		function isoToDateTimeLocal(value) {
			if (!value) return "";
			return value.slice(0, 16);
		}

		function shiftDateTimeByDays(isoString, days) {
			if (!isoString) return "";
			let normalized = isoString.trim();
			if (normalized.indexOf("T") === -1 && normalized.indexOf(" ") !== -1) {
				normalized = normalized.replace(" ", "T");
			}
			normalized = normalized.length === 16 ? `${normalized}:00` : normalized;
			const date = new Date(normalized);
			if (Number.isNaN(date.getTime())) {
				return "";
			}
			date.setDate(date.getDate() + days);
			return formatDateTimeWithSeconds(date);
		}

		function formatDateTimeWithSeconds(date) {
			const year = date.getFullYear();
			const month = String(date.getMonth() + 1).padStart(2, "0");
			const day = String(date.getDate()).padStart(2, "0");
			const hours = String(date.getHours()).padStart(2, "0");
			const minutes = String(date.getMinutes()).padStart(2, "0");
			const seconds = String(date.getSeconds()).padStart(2, "0");
			return `${year}-${month}-${day}T${hours}:${minutes}:${seconds}`;
		}

		function extractTimeFromISO(isoString) {
			if (!isoString) return "";
			const match = isoString.match(/T(\d{2}):(\d{2})/);
			if (!match) return "";
			return `${match[1]}:${match[2]}`;
		}

		function combineDateAndTime(dateISO, timeValue) {
			if (!dateISO || !timeValue) return "";
			const normalizedTime =
				timeValue.length === 5 ? `${timeValue}:00` : timeValue;
			return `${dateISO}T${normalizedTime}`;
		}

		function compareTimeStrings(a, b) {
			if (!a || !b) return 0;
			const [ah, am] = a.split(":").map(Number);
			const [bh, bm] = b.split(":").map(Number);
			if (
				Number.isNaN(ah) ||
				Number.isNaN(am) ||
				Number.isNaN(bh) ||
				Number.isNaN(bm)
			)
				return 0;
			return ah * 60 + am - (bh * 60 + bm);
		}

		function addDaysToISODate(dateISO, days) {
			if (!dateISO) return dateISO;
			const date = new Date(`${dateISO}T00:00:00`);
			if (Number.isNaN(date.getTime())) return dateISO;
			date.setDate(date.getDate() + days);
			return shiftplannerFormatISODateLocal(date);
		}

		function buildShiftDateTimes(baseDateISO, startTimeValue, endTimeValue) {
			if (!baseDateISO) {
				return { startDateTime: "", endDateTime: "" };
			}
			let endDateISO = baseDateISO;
			if (compareTimeStrings(endTimeValue, startTimeValue) <= 0) {
				endDateISO = addDaysToISODate(baseDateISO, 1);
			}
			return {
				startDateTime: combineDateAndTime(baseDateISO, startTimeValue),
				endDateTime: combineDateAndTime(endDateISO, endTimeValue),
			};
		}

		function escapeHtml(str) {
			if (str === undefined || str === null) return "";
			return String(str)
				.replace(/&/g, "&amp;")
				.replace(/</g, "&lt;")
				.replace(/>/g, "&gt;")
				.replace(/"/g, "&quot;")
				.replace(/'/g, "&#39;");
		}

		async function createShift(
			empId,
			label,
			start,
			end,
			taskId,
			shiftType,
			shiftStatus,
			callback,
			note = ""
		) {
			if (!isShiftDurationAllowed(start, end)) {
				alert(getShiftDurationLimitMessage());
				return;
			}
			if (wouldExceedNormalCap(empId, start, end, shiftType, shiftStatus)) {
				alertNormalCapExceeded();
				return;
			}
			try {
				await createShiftRequest(
					empId,
					label,
					start,
					end,
					taskId,
					shiftType,
					shiftStatus,
					note
				);
				if (typeof callback === "function") {
					callback();
				}
			} catch (error) {
				alert(error.message);
			}
		}

		async function createShiftRequest(
			empId,
			label,
			start,
			end,
			taskId,
			shiftType,
			shiftStatus = "scheduled",
			note = ""
		) {
			const resp = await fetch(apiUrl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "create",
					fk_user: empId,
					fk_task: taskId || "",
						label: label,
						start: start,
						end: end,
						note: note || "",
						shift_type: shiftType || "1",
						shift_status: normalizeShiftStatusValue(shiftStatus),
					}),
			});

			const text = await resp.text();
			let result;
			try {
				result = JSON.parse(text);
			} catch (parseError) {
				throw new Error(text || "Erro ao criar turno");
			}

			if (!resp.ok || !result.success) {
				const detail =
					result && result.details ? " (" + result.details + ")" : "";
				const message = translateApiError(
					result && result.error,
					"Erro ao criar turno"
				);
				throw new Error(message + detail);
			}

			return result;
		}

		async function deleteShiftRequest(id) {
			const resp = await fetch(apiUrl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "delete",
					id: id,
				}),
			});

			const text = await resp.text();
			let result;
			try {
				result = JSON.parse(text);
			} catch (err) {
				throw new Error(text || err.message || "Erro ao apagar turno");
			}
			if (!resp.ok || !result.success) {
				throw new Error(
					translateApiError(result.error, "Erro ao apagar turno")
				);
			}
			return result;
		}

		async function createDayOffEntry(
			empId,
			dateValue,
			isRecurring,
			description,
			isLock = false
		) {
			const resp = await fetch(apiUrl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "create_dayoff",
					user_id: empId,
					dayoff_date: dateValue,
					dayoff_recurring: isRecurring ? 1 : 0,
					dayoff_description: description || "",
					dayoff_is_lock: isLock ? 1 : 0,
				}),
			});
			const result = await resp.json();
			if (!resp.ok || !result.success) {
				throw new Error(
					translateApiError(
						result && result.error,
						tLabel("errorCreateDayOff", "Erro ao criar Férias")
					)
				);
			}
		}

		async function createLeaveEntry(empId, payload) {
			const resp = await fetch(apiUrl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "create_leave",
					user_id: empId,
					leave_type: payload.leave_type,
					leave_start: payload.leave_start,
					leave_end: payload.leave_end,
					leave_halfday: payload.leave_halfday,
					leave_description: payload.leave_description || "",
					leave_hours_start: payload.leave_hours_start || "",
					leave_hours_end: payload.leave_hours_end || "",
					leave_minutes_total: payload.leave_minutes_total || "",
				}),
			});
			const text = await resp.text();
			let result;
			try {
				result = JSON.parse(text);
			} catch (err) {
				throw new Error(text || err.message || "Erro ao criar ausência");
			}
			if (!resp.ok || !result.success) {
				const msg = translateApiError(
					result && result.error,
					tLabel("errorCreateLeave", "Erro ao criar ausência")
				);
				const detail = result && result.details ? " (" + result.details + ")" : "";
				throw new Error(msg + detail);
			}
		}

		async function createLeaveRequest(payload) {
			if (!payload || !payload.user_id) {
				throw new Error("Parâmetros inválidos para ausência");
			}
			return createLeaveEntry(payload.user_id, payload);
		}

			async function convertShiftToLeaveRequest(shiftId, payload) {
				if (!shiftId) {
					throw new Error("Missing shift id for conversion");
				}
			if (!payload || !payload.leave_type) {
				throw new Error("Missing leave type for conversion");
			}
			const resp = await fetch(apiUrl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "convert_shift_to_leave",
					shift_id: String(shiftId),
					leave_type: String(payload.leave_type),
					leave_description: payload.leave_description || "",
				}),
			});
			const text = await resp.text();
			let result;
			try {
				result = JSON.parse(text);
			} catch (err) {
				throw new Error(text || err.message || "Erro ao converter turno em ausência");
			}
			if (!resp.ok || !result.success) {
				throw new Error(
					translateApiError(
						result && result.error,
						tLabel("convertLeaveError", "Falha ao converter turno em ausência.")
					)
				);
				}
				return result;
			}

			async function convertLeaveToShiftRequest(leaveId, payload) {
				if (!leaveId) {
					throw new Error("Missing leave id for conversion");
				}
				const params = new URLSearchParams({
					action: "convert_leave_to_shift",
					leave_id: String(leaveId),
					shift_type: payload && payload.shift_type ? String(payload.shift_type) : "1",
				});
					if (payload && payload.fk_task) {
						params.set("fk_task", String(payload.fk_task));
					}
					if (payload && payload.shift_note !== undefined) {
						params.set("shift_note", String(payload.shift_note || ""));
					}
					const resp = await fetch(apiUrl, {
					method: "POST",
					headers: { "Content-Type": "application/x-www-form-urlencoded" },
					body: params,
				});
				const text = await resp.text();
				let result;
				try {
					result = JSON.parse(text);
				} catch (err) {
					throw new Error(text || err.message || "Failed to reconvert absence to shift");
				}
				if (!resp.ok || !result.success) {
					const detail = result && result.details ? " (" + result.details + ")" : "";
					throw new Error(
						translateApiError(
							result && result.error,
							tLabel("reconvertToShiftError", "Failed to reconvert absence to shift.")
						) + detail
					);
				}
				return result;
			}

			async function updateLeaveEntry(leaveId, payload) {
				const resp = await fetch(apiUrl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "update_leave",
					leave_id: leaveId,
					leave_type: payload.leave_type,
					leave_start: payload.leave_start,
					leave_end: payload.leave_end,
					leave_halfday: payload.leave_halfday,
					leave_description: payload.leave_description || "",
					leave_hours_start: payload.leave_hours_start || "",
					leave_hours_end: payload.leave_hours_end || "",
					leave_minutes_total: payload.leave_minutes_total || "",
					user_id: payload.user_id || "",
				}),
			});
			const text = await resp.text();
			let result;
			try {
				result = JSON.parse(text);
			} catch (err) {
				throw new Error(text || err.message || "Erro ao criar ausência");
			}
			if (!resp.ok || !result.success) {
				const msg = translateApiError(
					result && result.error,
					tLabel("errorCreateLeave", "Erro ao criar ausência")
				);
				const detail = result && result.details ? " (" + result.details + ")" : "";
				throw new Error(msg + detail);
			}
		}

		async function deleteDayOffEntry(dayoffId) {
			const resp = await fetch(apiUrl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "delete_dayoff",
					dayoff_id: dayoffId,
				}),
			});
			const result = await resp.json();
			if (!resp.ok || !result.success) {
				alert(
					translateApiError(
						result && result.error,
						tLabel("errorDeleteDayOff", "Erro ao remover Férias")
					)
				);
				return;
			}
			await loadData();
		}

		async function deleteLeaveEntry(leaveId) {
			const resp = await fetch(apiUrl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "delete_leave",
					leave_id: leaveId,
				}),
			});
			const result = await resp.json();
			if (!resp.ok || !result.success) {
				alert(
					translateApiError(
						result && result.error,
						tLabel("errorDeleteLeave", "Erro ao remover ausência")
					)
				);
				return;
			}
			await loadData();
		}

		async function updateShift(
			id,
			empId,
			label,
			start,
			end,
			taskId,
			shiftType,
			shiftStatus,
			callback,
			note = ""
		) {
			if (start && end && !isShiftDurationAllowed(start, end)) {
				alert(getShiftDurationLimitMessage());
				return;
			}
			if (
				start &&
				end &&
				wouldExceedNormalCap(empId, start, end, shiftType, shiftStatus, {
					existingShiftId: id,
				})
			) {
				alertNormalCapExceeded();
				return;
			}
			try {
				const resp = await fetch(apiUrl, {
					method: "POST",
					headers: { "Content-Type": "application/x-www-form-urlencoded" },
					body: new URLSearchParams({
						action: "update",
						id: id,
						fk_user: empId,
						fk_task: taskId !== undefined ? taskId : "",
							label: label,
							start: start,
							end: end,
							note: note || "",
							shift_type: shiftType || "1",
							shift_status: normalizeShiftStatusValue(shiftStatus),
						}),
					});

				const text = await resp.text();
				let result;
				try {
					result = JSON.parse(text);
				} catch (err) {
					throw new Error(text || "Erro ao atualizar turno");
				}
				if (!result.success) {
					const detail = result && result.details ? " (" + result.details + ")" : "";
					const message = translateApiError(
						result.error,
						"Erro ao atualizar turno"
					);
					throw new Error(message + detail);
				}

				callback();
			} catch (error) {
				alert(error.message);
			}
		}

		async function deleteShift(id, callback) {
			try {
				const resp = await fetch(apiUrl, {
					method: "POST",
					headers: { "Content-Type": "application/x-www-form-urlencoded" },
					body: new URLSearchParams({
						action: "delete",
						id: id,
					}),
				});

				const result = await resp.json();
				if (!result.success) {
					throw new Error(
						translateApiError(result.error, "Erro ao apagar turno")
					);
				}

				callback();
			} catch (error) {
				alert(error.message);
			}
		}

		function updateWeekPicker() {
			if (!weekPicker) return;
			if (weekPickerWidget && typeof weekPickerWidget.update === "function") {
				weekPickerWidget.update(currentWeekStart);
			} else {
				setWeekInputDisplay(weekPicker, currentWeekStart);
			}
		}

		function handleWeekPickerChange(e) {
			const value =
				(e.target.dataset && e.target.dataset.isoValue) || e.target.value;
			const parsed = parseWeekInput(value);
			if (!parsed) {
				updateWeekPicker();
				return;
			}
			currentWeekStart = parsed;
			syncWeekState();
			loadData();
		}

		function openWeekCopyDialog() {
			if (!weekCopyModal) return;
			if (weekCopySourceInput) {
				setWeekInputDisplay(weekCopySourceInput, currentWeekStart);
				if (
					weekCopySourceWidget &&
					typeof weekCopySourceWidget.update === "function"
				) {
					weekCopySourceWidget.update(currentWeekStart);
				}
			}
			if (weekCopyTargetInput) {
				setWeekInputDisplay(weekCopyTargetInput, currentWeekStart);
				if (
					weekCopyTargetWidget &&
					typeof weekCopyTargetWidget.update === "function"
				) {
					weekCopyTargetWidget.update(currentWeekStart);
				}
			}
			const mergeModeInput = weekCopyModeInputs.find(
				(input) => input && String(input.value).toLowerCase() === "merge"
			);
			if (mergeModeInput) {
				mergeModeInput.checked = true;
			}
			updateWeekCopyModeUI();
			weekCopyModal.classList.add("open");
			weekCopyModal.style.display = "flex";
			weekCopyModal.setAttribute("aria-hidden", "false");
		}

		function closeWeekCopyDialog() {
			if (!weekCopyModal) return;
			weekCopyModal.classList.remove("open");
			weekCopyModal.style.display = "none";
			weekCopyModal.setAttribute("aria-hidden", "true");
			if (weekCopyConfirmBtn) {
				weekCopyConfirmBtn.disabled = false;
			}
			isCopyingWeek = false;
		}

		const formatWeekCopyConflicts = (conflicts) => {
			if (!Array.isArray(conflicts) || !conflicts.length) return "";
			const header = getShiftLabel(
				"copyWeekConflictHeader",
				"Conflitos encontrados:"
			);
			const typeLabels = {
				source_overlap: getShiftLabel(
					"copyWeekConflictSourceOverlap",
					"Origem: turno e folga no mesmo dia"
				),
				target_shift: getShiftLabel(
					"copyWeekConflictTargetShift",
					"Destino já tem turno"
				),
				target_dayoff: getShiftLabel(
					"copyWeekConflictTargetDayOff",
					"Destino já tem folga"
				),
				target_leave: getShiftLabel(
					"copyWeekConflictTargetLeave",
					"Destino já tem ausência"
				),
			};
			const lines = conflicts.map((conflict) => {
				const empId = conflict && conflict.employee_id ? conflict.employee_id : "";
				const emp = empId && employeeMap ? employeeMap[empId] : null;
				const empLabel = emp && emp.title ? emp.title : empId ? `ID ${empId}` : "";
				const dateLabel = conflict && conflict.date ? conflict.date : "";
				const typeLabel = typeLabels[conflict.type] || conflict.type || "";
				const base = empLabel || empId ? `${empLabel || "ID " + empId}` : "";
				if (base && dateLabel) {
					return `${base} - ${dateLabel}: ${typeLabel}`;
				}
				if (dateLabel) {
					return `${dateLabel}: ${typeLabel}`;
				}
				return typeLabel;
			});
			return [header].concat(lines).join("\n");
		};

		const formatWeekCopySkippedSummary = (result) => {
			const skippedTotal = Number(result && result.skipped ? result.skipped : 0);
			if (!Number.isFinite(skippedTotal) || skippedTotal <= 0) return "";
			const base = tLabel(
				"copyWeekSkippedInfo",
				"Alguns registos não foram copiados devido a conflitos."
			);
			const byType =
				result &&
				result.skipped_conflicts &&
				typeof result.skipped_conflicts === "object"
					? result.skipped_conflicts
					: null;
			if (!byType) {
				return `${base} (${skippedTotal})`;
			}
			const typeLabels = {
				source_overlap: getShiftLabel(
					"copyWeekConflictSourceOverlap",
					"Origem: turno e folga no mesmo dia"
				),
				target_shift: getShiftLabel(
					"copyWeekConflictTargetShift",
					"Destino já tem turno"
				),
				target_dayoff: getShiftLabel(
					"copyWeekConflictTargetDayOff",
					"Destino já tem folga"
				),
				target_leave: getShiftLabel(
					"copyWeekConflictTargetLeave",
					"Destino já tem ausência"
				),
			};
			const details = [];
			Object.keys(typeLabels).forEach((type) => {
				const count = Number(byType[type] || 0);
				if (Number.isFinite(count) && count > 0) {
					details.push(`- ${typeLabels[type]}: ${count}`);
				}
			});
			if (!details.length) {
				return `${base} (${skippedTotal})`;
			}
			return `${base} (${skippedTotal})\n${details.join("\n")}`;
		};

		async function handleEmployeeWeekCopy(event) {
			if (!canWrite || !apiUrl) return;
			if (event) {
				event.preventDefault();
				event.stopPropagation();
			}
			const trigger = event && event.currentTarget ? event.currentTarget : null;
			const empIdRaw = trigger && trigger.dataset ? trigger.dataset.empId : "";
			const empId = parseInt(empIdRaw, 10);
			if (!empId) return;
			const emp = employeeMap ? employeeMap[empId] : null;
			const empLabel =
				emp && emp.title ? emp.title : empId ? `ID ${empId}` : "";
			const sourceDate = new Date(currentWeekStart);
			const targetDate = new Date(currentWeekStart);
			targetDate.setDate(targetDate.getDate() + 7);
			const sourceIso = formatDateISO(sourceDate);
			const targetIso = formatDateISO(targetDate);
			if (!sourceIso || !targetIso) {
				alert("Não foi possível identificar as semanas.");
				return;
			}
			const sourceLabel = formatDetailedWeekRange(sourceDate);
			const targetLabel = formatDetailedWeekRange(targetDate);
			const confirmTemplate = tLabel(
				"copyRowConfirm",
				"Copiar a semana de {employee} ({source}) para {target}?"
			);
			const confirmMessage = confirmTemplate
				.replace("{employee}", empLabel)
				.replace("{source}", sourceLabel)
				.replace("{target}", targetLabel);
			if (!window.confirm(confirmMessage)) return;
			if (isCopyingEmployeeWeek) return;
			isCopyingEmployeeWeek = true;
			if (trigger) trigger.disabled = true;
				try {
					const resp = await fetch(apiUrl, {
						method: "POST",
						headers: { "Content-Type": "application/x-www-form-urlencoded" },
						body: new URLSearchParams({
							action: "copy_week",
							source_week: sourceIso,
							target_week: targetIso,
							employee_id: empId,
							copy_mode: "merge",
						}),
					});
					const result = await resp.json();
				if (!resp.ok || !result.success) {
					const conflictMessage = formatWeekCopyConflicts(result.conflicts);
					if (conflictMessage) {
						throw new Error(conflictMessage);
					}
					throw new Error(
						translateApiError(
							result.error,
							"Falha ao copiar semana."
						)
					);
					}
					loadData();
					const skippedMessage = formatWeekCopySkippedSummary(result);
					if (skippedMessage) {
						alert(skippedMessage);
					}
				} catch (error) {
					alert(error.message || "Falha ao copiar semana.");
				} finally {
				isCopyingEmployeeWeek = false;
				if (trigger) trigger.disabled = false;
			}
		}

		async function handleWeekCopyConfirm() {
			if (!weekCopySourceInput || !weekCopyTargetInput || !weekCopyConfirmBtn)
				return;
			const sourceDate = getWeekInputDateValue(weekCopySourceInput);
			const targetDate = getWeekInputDateValue(weekCopyTargetInput);
			if (!sourceDate || !targetDate) {
				alert("Selecione semanas válidas.");
				return;
			}
			const sourceIso = formatDateISO(sourceDate);
			const targetIso = formatDateISO(targetDate);
			if (!sourceIso || !targetIso) {
				alert("Não foi possível identificar as semanas.");
				return;
			}
			const sourceLabel = formatDetailedWeekRange(sourceDate);
			const targetLabel = formatDetailedWeekRange(targetDate);
			if (sourceIso === targetIso) {
				alert("A semana de origem e destino precisam ser diferentes.");
				return;
			}
			const copyMode = getSelectedWeekCopyMode();
			let confirmMessage = `Copiar ${sourceLabel} para ${targetLabel}?`;
			if (copyMode === "replace") {
				confirmMessage += `\n\n${replaceModeWarningText}`;
			}
			if (!window.confirm(confirmMessage)) {
				return;
			}
			if (isCopyingWeek) return;
			isCopyingWeek = true;
			weekCopyConfirmBtn.disabled = true;
			try {
				const resp = await fetch(apiUrl, {
					method: "POST",
					headers: { "Content-Type": "application/x-www-form-urlencoded" },
						body: new URLSearchParams({
							action: "copy_week",
							source_week: sourceIso,
							target_week: targetIso,
							copy_mode: copyMode,
						}),
					});
					const result = await resp.json();
				if (!resp.ok || !result.success) {
					const conflictMessage = formatWeekCopyConflicts(result.conflicts);
					if (conflictMessage) {
						throw new Error(conflictMessage);
					}
					throw new Error(
						translateApiError(result.error, "Falha ao copiar semana.")
					);
					}
					closeWeekCopyDialog();
					loadData();
					const skippedMessage = formatWeekCopySkippedSummary(result);
					if (skippedMessage) {
						alert(skippedMessage);
					}
				} catch (error) {
					alert(error.message || "Falha ao copiar semana.");
				} finally {
				isCopyingWeek = false;
				if (weekCopyConfirmBtn) weekCopyConfirmBtn.disabled = false;
			}
		}

		function handlePrintTimetable() {
			const weekIso = formatDateISO(currentWeekStart);
			const url = `${printUrl}?view=employees&week=${encodeURIComponent(
				weekIso
			)}`;
			window.open(url, "_blank");
		}

		function openQuotaModal(headingEl) {
			if (!quotaModal) return;
			const empId = headingEl.dataset.empId;
			if (!empId) return;
			quotaTargetEmpId = parseInt(empId, 10);
			if (!quotaTargetEmpId) return;
			if (quotaNameEl) {
				const emp = employeeMap[quotaTargetEmpId];
				const fullName =
					emp && emp.title ? emp.title : headingEl.dataset.empName || "";
					const empCardUrl = `${
						window.DOL_MAIN_URL_ROOT || ""
					}/user/card.php?id=${encodeURIComponent(quotaTargetEmpId)}`;
				quotaNameEl.innerHTML = `<a href="${empCardUrl}" target="_blank" rel="noopener noreferrer">${escapeHtml(
					fullName
				)}</a>`;
			}
			if (quotaEmpTaskEl) {
				quotaEmpTaskEl.textContent = getEmployeeMainTaskLabel(quotaTargetEmpId);
			}
			if (quotaInput) {
				quotaInput.value = headingEl.dataset.empHours || "";
			}
			if (quotaHoursField) {
				quotaHoursField.style.display = canEditEmployeeHours ? "" : "none";
			}
			if (canAssignMainTask) {
				if (!quotaTaskOptionsReady) {
					populateMainTaskSelect();
				}
				if (quotaTaskField) {
					quotaTaskField.style.display = "";
				}
				if (quotaTaskSelect) {
					quotaTaskSelect.style.display = "";
					quotaTaskSelect.value = getEmployeeMainTaskId(quotaTargetEmpId);
				}
				if (quotaTaskReadonly) {
					quotaTaskReadonly.style.display = "none";
				}
			} else {
				if (quotaTaskSelect) {
					quotaTaskSelect.style.display = "none";
				}
				if (quotaTaskField) {
					quotaTaskField.style.display = "";
				}
				if (quotaTaskReadonly) {
					quotaTaskReadonly.style.display = "block";
					quotaTaskReadonly.textContent =
						getEmployeeMainTaskLabel(quotaTargetEmpId);
				}
			}
			quotaModal.classList.add("open");
			quotaModal.setAttribute("aria-hidden", "false");
			setTimeout(() => {
				if (canEditEmployeeHours && quotaInput && quotaHoursField) {
					quotaInput.focus();
				} else if (canAssignMainTask && quotaTaskSelect) {
					quotaTaskSelect.focus();
				}
			}, 60);
		}

		function closeQuotaModal() {
			if (!quotaModal) return;
			quotaModal.classList.remove("open");
			quotaModal.setAttribute("aria-hidden", "true");
			if (quotaSaveBtn) quotaSaveBtn.disabled = false;
			quotaTargetEmpId = null;
		}

		async function handleQuotaSave() {
			if (!quotaModal || !quotaTargetEmpId || !quotaSaveBtn) return;
			const params = new URLSearchParams({
				action: "update_quota",
				emp_id: quotaTargetEmpId,
			});
			let hasPayload = false;
			if (canEditEmployeeHours && quotaInput && quotaHoursField) {
				params.append("hours", quotaInput.value.trim());
				hasPayload = true;
			}
			if (canAssignMainTask && quotaTaskSelect) {
				params.append("main_task_id", quotaTaskSelect.value || "");
				hasPayload = true;
			}
			if (!hasPayload) {
				alert(
					tLabel(
						"mainTaskSaveError",
						"Não tem permissão para atualizar estes dados."
					)
				);
				return;
			}
			quotaSaveBtn.disabled = true;
			try {
				const resp = await fetch(apiUrl, {
					method: "POST",
					headers: { "Content-Type": "application/x-www-form-urlencoded" },
					body: params,
				});
				const result = await resp.json();
				if (!result.success) {
					throw new Error(
						translateApiError(
							result.error,
							tLabel("mainTaskSaveError", "Falha ao atualizar.")
						)
					);
				}
				closeQuotaModal();
				loadData();
			} catch (error) {
				alert(
					error.message || tLabel("mainTaskSaveError", "Falha ao atualizar.")
				);
				if (quotaSaveBtn) quotaSaveBtn.disabled = false;
			}
		}

		function getEmployeeExpectedHours(empId) {
			const emp = employeeMap[empId];
			if (
				!emp ||
				emp.expectedHours === null ||
				emp.expectedHours === undefined
			) {
				return null;
			}
			const value = parseFloat(emp.expectedHours);
			return Number.isFinite(value) && value > 0 ? value : null;
		}

		function getEmployeeWeeklyMinutes(empId) {
			const keyStr = empId !== undefined && empId !== null ? String(empId) : "";
			const keyNum = Number(keyStr);
			return (
				weeklyMinutesByEmployee[keyStr] || weeklyMinutesByEmployee[keyNum] || 0
			);
		}

		function getEmployeeWeeklyMinutesNormal(empId) {
			const keyStr = empId !== undefined && empId !== null ? String(empId) : "";
			const keyNum = Number(keyStr);
			if (weeklyMinutesByEmployeeNormal[keyStr] !== undefined) {
				return weeklyMinutesByEmployeeNormal[keyStr] || 0;
			}
			if (weeklyMinutesByEmployeeNormal[keyNum] !== undefined) {
				return weeklyMinutesByEmployeeNormal[keyNum] || 0;
			}
			return 0;
		}

		function formatHoursCompact(hours) {
			return formatHoursValue(hours, true).replace(/h$/, "");
		}

		function formatMinutesCompact(minutes) {
			return formatHoursCompact(minutes / 60);
		}

		function isEmployeeOverCapacity(empId) {
			const expected = getEmployeeExpectedHours(empId);
			if (!expected) return false;
			const actualHours = getEmployeeWeeklyMinutes(empId) / 60;
			return actualHours - expected > 0.01;
		}

		function getEmployeeHoursLabel(empId) {
			const normalMinutes = getEmployeeWeeklyMinutesNormal(empId);
			const totalMinutes = getEmployeeWeeklyMinutes(empId);
			const specialMinutes = Math.max(totalMinutes - normalMinutes, 0);
			const contractedHours = getEmployeeExpectedHours(empId);
			const contractedMinutes =
				Number.isFinite(contractedHours) && contractedHours > 0
					? contractedHours * 60
					: null;
			const cappedNormalMinutes =
				capNormalToContract && contractedMinutes !== null
					? Math.min(normalMinutes, contractedMinutes)
					: normalMinutes;
			const parts = [];
			const hasMinutes = (value) =>
				Number.isFinite(value) && Math.abs(value) > 0.01;

			if (hasMinutes(cappedNormalMinutes)) {
				parts.push(`N ${formatMinutesCompact(cappedNormalMinutes)}`);
			}
			if (hasMinutes(specialMinutes)) {
				parts.push(`E ${formatMinutesCompact(specialMinutes)}`);
			}
			if (hasMinutes(totalMinutes)) {
				parts.push(`T ${formatMinutesCompact(totalMinutes)}`);
			}
			if (contractedMinutes !== null && contractedMinutes > 0.6) {
				parts.push(`C ${formatHoursCompact(contractedMinutes / 60)}`);
			}
			return parts.join(" / ");
		}

		function normalizeShiftTypeValue(value) {
			const num = Number(value);
			return Number.isFinite(num) ? num : 1;
		}

			function wouldExceedNormalCap(
				empId,
				startIso,
				endIso,
				shiftType,
				shiftStatus,
				options = {}
			) {
				if (!capNormalToContract) return false;
				const contractedHours = getEmployeeExpectedHours(empId);
				if (!Number.isFinite(contractedHours) || contractedHours <= 0) return false;
				const contractedMinutes = contractedHours * 60;
				if (isMissedStatusValue(shiftStatus)) return false;
				const typeVal = normalizeShiftTypeValue(shiftType);
				if (typeVal !== 1) return false;
				const newMinutes = getShiftDurationMinutes(startIso, endIso);
			if (!Number.isFinite(newMinutes) || newMinutes <= 0) return false;

			let currentNormalMinutes = getEmployeeWeeklyMinutesNormal(empId);
			const existingShiftId = options.existingShiftId;
			if (existingShiftId) {
				const existing = shiftMap.get(String(existingShiftId));
					if (existing && String(existing.empId) === String(empId)) {
						const existingType = normalizeShiftTypeValue(
							existing.shift_type || existing.shiftType
						);
						const existingStatus = normalizeShiftStatusValue(
							existing.shift_status || existing.shiftStatus
						);
						if (existingType === 1 && !isMissedStatusValue(existingStatus)) {
							const existingMinutes = getShiftDurationMinutes(
								existing.start,
								existing.end
						);
						if (Number.isFinite(existingMinutes) && existingMinutes > 0) {
							currentNormalMinutes -= existingMinutes;
						}
					}
				}
			}

			const projected = currentNormalMinutes + newMinutes;
			return projected - contractedMinutes > 0.01;
		}

		function alertNormalCapExceeded() {
			alert(
				tLabel(
					"normalHoursCapExceeded",
					"Horas normais excedem o limite contratado."
				)
			);
		}

		function formatHoursFromMinutes(minutes) {
			return formatHoursValue(minutes / 60, true);
		}

		function parseExpectedHours(value) {
			if (value === null || value === undefined || value === "") return null;
			const num = parseFloat(value);
			if (!Number.isFinite(num) || num <= 0) return null;
			return num;
		}

		function getShiftDurationMinutes(startIso, endIso) {
			const startMs = parseIsoToUtcMs(startIso);
			const endMs = parseIsoToUtcMs(endIso);
			if (startMs === null || endMs === null || endMs <= startMs) return 0;
			return (endMs - startMs) / 60000;
		}

		function parseIsoToUtcMs(value) {
			const match =
				value && value.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
			if (!match) return null;
			const [, year, month, day, hour, minute] = match;
			return Date.UTC(
				parseInt(year, 10),
				parseInt(month, 10) - 1,
				parseInt(day, 10),
				parseInt(hour, 10),
				parseInt(minute, 10)
			);
		}

		function handleShiftDragStart(e) {
			if (!canWrite) return;
			const shiftId = e.currentTarget.dataset.shiftId;
			if (!shiftId) return;
			draggedShiftId = shiftId;
			if (e.dataTransfer) {
				e.dataTransfer.effectAllowed = "move";
				e.dataTransfer.setData(SHIFT_DRAG_TYPE, shiftId);
				e.dataTransfer.setData("text/plain", shiftId);
			}
		}

		function handleShiftDragEnd() {
			draggedShiftId = null;
		}

		function handleCellDragOver(e) {
			if (!canWrite || !e.dataTransfer) return;
			if (e.currentTarget.dataset.entryType) return;
			const types = Array.from(e.dataTransfer.types || []);
			const hasShiftData =
				types.includes(SHIFT_DRAG_TYPE) || types.includes("text/plain");
			if (!hasShiftData) return;
			const shiftId =
				draggedShiftId ||
				e.dataTransfer.getData(SHIFT_DRAG_TYPE) ||
				e.dataTransfer.getData("text/plain");
			const shift = shiftMap.get(String(shiftId));
			if (!shift || String(e.currentTarget.dataset.emp) !== String(shift.empId))
				return;
			e.preventDefault();
			e.dataTransfer.dropEffect = "move";
		}

		function handleCellDrop(e) {
			if (!canWrite || !e.dataTransfer) return;
			if (e.currentTarget.dataset.entryType) return;
			const types = Array.from(e.dataTransfer.types || []);
			const hasShiftData =
				types.includes(SHIFT_DRAG_TYPE) || types.includes("text/plain");
			if (!hasShiftData) return;
			const shiftId =
				e.dataTransfer.getData(SHIFT_DRAG_TYPE) ||
				e.dataTransfer.getData("text/plain") ||
				draggedShiftId;
			const shift = shiftMap.get(String(shiftId));
			if (!shift || String(e.currentTarget.dataset.emp) !== String(shift.empId))
				return;
			e.preventDefault();
			const targetDate = e.currentTarget.dataset.date;
			if (targetDate && targetDate !== shift.date) {
				moveShiftToDate(shift, targetDate);
			}
			draggedShiftId = null;
		}

		function moveShiftToDate(shift, targetDateISO) {
			if (isBeforeEmployment(shift.empId, targetDateISO)) {
				alert(
					tLabel(
						"employeeBeforeHire",
						"Funcionário ainda não iniciou nesta data."
					)
				);
				return;
			}
			if (isAfterEmploymentEnd(shift.empId, targetDateISO)) {
				alert(
					tLabel(
						"employeeAfterEnd",
						"Funcionário já não está ativo nesta data."
					)
				);
				return;
			}
			if (getDayOffForDate(shift.empId, targetDateISO)) {
				alert(
					tLabel(
						"cannotMoveDayOff",
						"Não é possível mover o turno para um dia com Férias."
					)
				);
				return;
			}
			if (getLeaveForDate(shift.empId, targetDateISO)) {
				alert(
					tLabel(
						"cannotMoveLeave",
						"Não é possível mover o turno para um dia com ausência."
					)
				);
				return;
			}
			const startMinutesRaw = shiftplannerExtractMinutes(shift.start);
			const endMinutesRaw = shiftplannerExtractMinutes(shift.end);
			if (startMinutesRaw === null || endMinutesRaw === null) return;
			let normalizedStart = shiftplannerNormalizeMinutes(startMinutesRaw);
			let normalizedEnd = shiftplannerNormalizeMinutes(endMinutesRaw);
			if (normalizedStart === null || normalizedEnd === null) return;
			if (normalizedEnd <= normalizedStart) {
				normalizedEnd += MINUTES_PER_DAY;
			}
			const newStart = shiftplannerMinutesToIso(targetDateISO, normalizedStart);
			const newEnd = shiftplannerMinutesToIso(targetDateISO, normalizedEnd);
			const onDone = () => {
				loadData();
			};
			updateShift(
				shift.id,
				shift.empId,
				shift.label,
				newStart,
				newEnd,
				shift.taskId || null,
				shift.shift_type || shift.shiftType || "1",
				normalizeShiftStatusValue(shift.shift_status || shift.shiftStatus),
				onDone
			);
		}

		if (weekCopyBtn && weekCopyModal) {
			weekCopyBtn.addEventListener("click", openWeekCopyDialog);
			if (weekCopyCancelBtn)
				weekCopyCancelBtn.addEventListener("click", closeWeekCopyDialog);
			if (weekCopyBackdrop)
				weekCopyBackdrop.addEventListener("click", closeWeekCopyDialog);
			if (weekCopyConfirmBtn)
				weekCopyConfirmBtn.addEventListener("click", handleWeekCopyConfirm);
		}
		if (weekPrintBtn) {
			weekPrintBtn.addEventListener("click", handlePrintTimetable);
		}
	}
})();
function formatMinutesSummary(totalMinutes) {
	if (!totalMinutes || !Number.isFinite(totalMinutes)) return "";
	const hoursPart = Math.floor(totalMinutes / 60);
	const minsPart = totalMinutes % 60;
	const formattedDuration = `${String(hoursPart).padStart(2, "0")}:${String(
		minsPart
	).padStart(2, "0")}`;
	const template =
		typeof tLabel === "function"
			? tLabel("leaveMinutesSummary", "Faltou %s")
			: "Faltou %s";
	return template.replace("%s", formattedDuration);
}
