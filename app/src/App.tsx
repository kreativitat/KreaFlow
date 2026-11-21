import { Navigation, UiIcon } from './Navigation';
import { KitchenPanel } from './KitchenPanel';
import { EatsWeeklyPanel } from './EatsWeeklyPanel';
import { ForecastAccuracyPanel } from './ForecastAccuracyPanel';
import {
  type ChangeEvent,
  type ClipboardEvent,
  type FormEvent,
  type KeyboardEvent,
  useEffect,
  useMemo,
  useState
} from 'react';
import Picker from 'react-mobile-picker';
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis
} from 'recharts';
import {
  ApiError,
  createFecho,
  EmployeeSale,
  FechoFinanceiroDetail,
  FechoFinanceiroItem,
  FechoFinanceiroList,
  FechoFinanceiroMeta,
  FechoFinanceiroMetrics,
  FechoFinanceiroSavePayload,
  FechoFinanceiroValues,
  getBootstrapSession,
  getDashboard,
  getFechoDetail,
  getFechoFinanceiroList,
  getFechoMeta,
  getLoginMeta,
  getProductDetail,
  getProductCategories,
  getSalesForecast,
  getStockForecast,
  getWorkforceBoard,
  getWhoAmI,
  loginWithCredentials,
  LoginMeta,
  logoutSession,
  PaymentSale,
  ProductCategory,
  ProductDetail,
  ProductItem,
  SalesDashboard,
  SalesForecast,
  StockForecast,
  searchProducts,
  SessionData,
  TopProduct,
  updateFecho,
  WorkforceBoard,
  WorkforceDayOff,
  WorkforceEvent,
  WorkforceLeave,
  WorkforcePublicHoliday,
  WorkforcePunchRow,
  WorkforceResource
} from './lib/api';
import { clearDashboardCache, readDashboardCache, writeDashboardCache } from './lib/startupCache';

type TabKey = 'kitchen' | 'eats' | 'dashboard' | 'forecast' | 'stock_forecast' | 'horarios' | 'barras' | 'ponto' | 'fecho' | 'produtos' | 'about';
type DateField = 'start' | 'end' | 'single';
type DateScope = 'eats' | 'dashboard' | 'fecho' | 'fechoModal' | 'equipaWeek' | 'equipaDay' | 'equipaPunch';
type FechoModalMode = 'create' | 'view' | 'edit';
type ProductLayoutMode = 'search' | 'categories';
type DateWheelValue = {
  day: string;
  month: string;
  year: string;
};
type DateBounds = {
  min: string | null;
  max: string | null;
};

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
};

const TABS: Array<{ key: TabKey; label: string }> = [
  { key: 'dashboard', label: 'Dashboard' },
  { key: 'eats', label: 'UberEats' },
  { key: 'kitchen', label: 'Cozinha' },
  { key: 'forecast', label: 'Prev. Vendas' },
  { key: 'stock_forecast', label: 'Prev. Stock' },
  { key: 'produtos', label: 'Produtos' },
  { key: 'fecho', label: 'Fecho Financeiro' },
  { key: 'horarios', label: 'Horarios Semana' },
  { key: 'barras', label: 'Horarios Dia' },
  { key: 'ponto', label: 'Ponto' },
  { key: 'about', label: 'Sobre' }
];

function canAccessTab(session: SessionData | null, tab: TabKey): boolean {
  if (!session) {
    return false;
  }

  if (tab === 'kitchen') { return session.rights.kitchen_read === 1; }
  if (tab === 'eats') { return session.rights.eats_read === 1; }
  if (tab === 'dashboard') {
    return session.rights.dashboard_read === 1;
  }
  if (tab === 'forecast') {
    return session.rights.dashboard_read === 1;
  }
  if (tab === 'stock_forecast') {
    return session.rights.stock_forecast_read === 1;
  }
  if (tab === 'produtos') {
    return session.rights.products_read === 1 && session.rights.product_read === 1;
  }
  if (tab === 'horarios' || tab === 'barras') {
    return session.rights.workforce_schedule_read === 1;
  }
  if (tab === 'ponto') {
    return session.rights.workforce_punch_read === 1;
  }
  if (tab === 'fecho') {
    return session.rights.fecho_read === 1;
  }
  if (tab === 'about') {
    return true;
  }

  return false;
}

function getAccessibleTabs(session: SessionData | null): Array<{ key: TabKey; label: string }> {
  return TABS.filter((tab) => canAccessTab(session, tab.key));
}

const PIE_COLORS = ['var(--kf-primary)', 'var(--kf-secondary)', 'var(--kf-accent)', 'var(--kf-text)', 'var(--kf-chart-dark)', 'var(--kf-chart-neutral)', 'var(--kf-chart-light)'];
const MONTH_LABELS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
const PRODUCT_CATEGORY_SECTION_LIMIT = 30;
const FECHO_STATUS_OPTIONS: Array<{ value: number; label: string }> = [
  { value: -1, label: 'Todos' },
  { value: 0, label: 'Rascunho' },
  { value: 1, label: 'Validado' },
  { value: 9, label: 'Cancelado' }
];

type FechoValueKey = keyof FechoFinanceiroValues;
type FechoFormValues = Record<FechoValueKey, string>;
type FechoFormState = {
  id: number;
  ref: string;
  status: number;
  status_label: string;
  datecx: string;
  fk_loja: number;
  store_label: string;
  responsavel: number;
  responsavel_label: string;
  description: string;
  can_edit: number;
  values: FechoFormValues;
};

const FECHO_VALUE_KEYS: FechoValueKey[] = [
  'val_talao_caixa',
  'tal_numerario',
  'tal_mb',
  'tal_ubereats',
  'tal_glovo',
  'tal_comermelhor',
  'tal_factcredito',
  'tal_tickets',
  'tal_notacredito',
  'val_moedas',
  'val_005',
  'val_010',
  'val_020',
  'val_050',
  'val_100',
  'val_200',
  'val_500',
  'tpa_1',
  'tpa_2',
  'tpa_3',
  'tpa_4',
  'tpa_5',
  'tpa_6',
  'val_tickets',
  'tips',
  'despesas',
  'diferenca',
  'total_isen',
  'total_redu',
  'total_inte',
  'total_norm',
  'total_sem_iva'
];

type FechoFieldDef = { key: FechoValueKey; label: string; readOnly?: boolean };

const FECHO_TOTAL_VENDAS_FIELDS: FechoFieldDef[] = [
  { key: 'val_talao_caixa', label: 'Valor Talão Caixa' },
  { key: 'total_isen', label: 'Total Isento (0%)' },
  { key: 'total_redu', label: 'Total Reduzido (6%)' },
  { key: 'total_inte', label: 'Total Intermédio (13%)' },
  { key: 'total_norm', label: 'Total Normal (23%)' },
  { key: 'total_sem_iva', label: 'Total sem IVA', readOnly: true }
];

const FECHO_TALAO_FECHO_FIELDS: FechoFieldDef[] = [
  { key: 'tal_numerario', label: 'Talão Numerário' },
  { key: 'tal_mb', label: 'Talão MB' },
  { key: 'tal_ubereats', label: 'Talão Uber Eats' },
  { key: 'tal_glovo', label: 'Talão Glovo' },
  { key: 'tal_comermelhor', label: 'Talão Comer Melhor' },
  { key: 'tal_factcredito', label: 'Talão Faturas Crédito' },
  { key: 'tal_tickets', label: 'Talão Tickets' },
  { key: 'tal_notacredito', label: 'Talão Nota Crédito' }
];

const FECHO_TOTAL_RECEBIDO_FIELDS: FechoFieldDef[] = [
  { key: 'val_moedas', label: 'Moedas' },
  { key: 'val_005', label: 'Notas 5€' },
  { key: 'val_010', label: 'Notas 10€' },
  { key: 'val_020', label: 'Notas 20€' },
  { key: 'val_050', label: 'Notas 50€' },
  { key: 'val_100', label: 'Notas 100€' },
  { key: 'val_200', label: 'Notas 200€' },
  { key: 'val_500', label: 'Notas 500€' },
  { key: 'tpa_1', label: 'TPA 1' },
  { key: 'tpa_2', label: 'TPA 2' },
  { key: 'tpa_3', label: 'TPA 3' },
  { key: 'tpa_4', label: 'TPA 4' },
  { key: 'tpa_5', label: 'TPA 5' },
  { key: 'tpa_6', label: 'TPA 6' },
  { key: 'val_tickets', label: 'Tickets Restaurante' },
  { key: 'despesas', label: 'Despesas' }
];

const FECHO_RESUMO_INPUT_FIELDS: FechoFieldDef[] = [
  { key: 'diferenca', label: 'Diferença de Caixa' },
  { key: 'tips', label: 'Gorjetas' }
];

type FechoValidationState = {
  salesValid: boolean;
  salesDifference: number;
  treasuryValid: boolean;
  treasuryDifference: number;
  vatValid: boolean;
  vatDifference: number;
  canSave: boolean;
};

function round2(value: number): number {
  return Math.round((value + Number.EPSILON) * 100) / 100;
}

function parseDecimalInput(value: string): number {
  const normalized = value.replace(/\s+/g, '').replace(',', '.');
  const parsed = Number.parseFloat(normalized);
  if (!Number.isFinite(parsed)) {
    return 0;
  }
  return parsed;
}

function formatDecimalInput(value: number): string {
  return round2(value).toFixed(2);
}

function makeFechoFormValues(values?: FechoFinanceiroValues): FechoFormValues {
  const formValues = {} as FechoFormValues;
  FECHO_VALUE_KEYS.forEach((key) => {
    formValues[key] = values ? formatDecimalInput(values[key]) : '0.00';
  });
  return formValues;
}

function computeFechoMetricsFromValues(values: FechoFormValues): FechoFinanceiroMetrics {
  const val_talao_caixa = parseDecimalInput(values.val_talao_caixa);
  const tal_numerario = parseDecimalInput(values.tal_numerario);
  const tal_mb = parseDecimalInput(values.tal_mb);
  const tal_ubereats = parseDecimalInput(values.tal_ubereats);
  const tal_glovo = parseDecimalInput(values.tal_glovo);
  const tal_comermelhor = parseDecimalInput(values.tal_comermelhor);
  const tal_factcredito = parseDecimalInput(values.tal_factcredito);
  const tal_tickets = parseDecimalInput(values.tal_tickets);
  const tal_notacredito = parseDecimalInput(values.tal_notacredito);
  const val_moedas = parseDecimalInput(values.val_moedas);
  const val_005 = parseDecimalInput(values.val_005);
  const val_010 = parseDecimalInput(values.val_010);
  const val_020 = parseDecimalInput(values.val_020);
  const val_050 = parseDecimalInput(values.val_050);
  const val_100 = parseDecimalInput(values.val_100);
  const val_200 = parseDecimalInput(values.val_200);
  const val_500 = parseDecimalInput(values.val_500);
  const tpa_1 = parseDecimalInput(values.tpa_1);
  const tpa_2 = parseDecimalInput(values.tpa_2);
  const tpa_3 = parseDecimalInput(values.tpa_3);
  const tpa_4 = parseDecimalInput(values.tpa_4);
  const tpa_5 = parseDecimalInput(values.tpa_5);
  const tpa_6 = parseDecimalInput(values.tpa_6);
  const val_tickets = parseDecimalInput(values.val_tickets);
  const despesas = parseDecimalInput(values.despesas);
  const diferenca = parseDecimalInput(values.diferenca);
  const total_isen = parseDecimalInput(values.total_isen);
  const total_redu = parseDecimalInput(values.total_redu);
  const total_inte = parseDecimalInput(values.total_inte);
  const total_norm = parseDecimalInput(values.total_norm);
  const total_sem_iva_posted = parseDecimalInput(values.total_sem_iva);

  const soma_talao = round2(
    tal_numerario + tal_mb + tal_ubereats + tal_glovo + tal_comermelhor + tal_factcredito + tal_tickets - tal_notacredito
  );
  const credito_liquido = round2(tal_factcredito - tal_notacredito);
  const recebido_esperado = round2(soma_talao - credito_liquido);
  const soma_notas = round2(val_005 * 5 + val_010 * 10 + val_020 * 20 + val_050 * 50 + val_100 * 100 + val_200 * 200 + val_500 * 500);
  const soma_numerario = round2(val_moedas + soma_notas);
  const soma_tpa = round2(tpa_1 + tpa_2 + tpa_3 + tpa_4 + tpa_5 + tpa_6);
  const soma_total_valores = round2(soma_numerario + soma_tpa + val_tickets + despesas + tal_ubereats + tal_glovo + tal_comermelhor);
  const total_sem_iva_calc = round2(total_isen / 1.0 + total_redu / 1.06 + total_inte / 1.13 + total_norm / 1.23);
  const total_com_iva_calc = round2(total_isen + total_redu + total_inte + total_norm);
  const diferenca_calculada = round2(soma_total_valores - recebido_esperado);
  const tesouraria_balance = round2(soma_total_valores - diferenca);

  return {
    val_talao_caixa,
    diferenca,
    total_sem_iva_posted,
    soma_talao,
    credito_liquido,
    recebido_esperado,
    soma_notas,
    soma_numerario,
    soma_tpa,
    soma_total_valores,
    total_sem_iva_calc,
    total_com_iva_calc,
    diferenca_calculada,
    tesouraria_balance
  };
}

function buildFechoValidationState(metrics: FechoFinanceiroMetrics, tolerance = 0.01): FechoValidationState {
  const salesDifference = round2(Math.abs(metrics.val_talao_caixa - metrics.soma_talao));
  const treasuryDifference = round2(Math.abs(metrics.tesouraria_balance - metrics.recebido_esperado));
  const vatDifference = round2(Math.abs(metrics.total_com_iva_calc - metrics.val_talao_caixa));

  const salesValid = salesDifference <= tolerance;
  const treasuryValid = treasuryDifference <= tolerance;
  const vatValid = vatDifference <= tolerance;

  return {
    salesValid,
    salesDifference,
    treasuryValid,
    treasuryDifference,
    vatValid,
    vatDifference,
    canSave: salesValid && treasuryValid && vatValid
  };
}

function buildFechoSavePayload(form: FechoFormState, metrics: FechoFinanceiroMetrics): FechoFinanceiroSavePayload {
  const values = {} as FechoFinanceiroValues;
  FECHO_VALUE_KEYS.forEach((key) => {
    values[key] = parseDecimalInput(form.values[key]);
  });
  values.total_sem_iva = metrics.total_sem_iva_calc;

  return {
    datecx: form.datecx,
    fk_loja: form.fk_loja,
    responsavel: form.responsavel,
    description: form.description,
    values
  };
}

function buildFechoFormFromDetail(detail: FechoFinanceiroDetail): FechoFormState {
  return {
    id: detail.id,
    ref: detail.ref,
    status: detail.status,
    status_label: detail.status_label,
    datecx: detail.datecx,
    fk_loja: detail.fk_loja,
    store_label: detail.store_label || '',
    responsavel: detail.responsavel,
    responsavel_label: detail.responsavel_label || '',
    description: detail.description || '',
    can_edit: detail.can_edit,
    values: makeFechoFormValues(detail.values)
  };
}

function toInputDate(date: Date): string {
  const year = date.getFullYear();
  const month = date.getMonth() + 1;
  const day = date.getDate();
  return `${String(year)}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function toLocalInputDate(date: Date): string {
  return toInputDate(date);
}

function getRelativeLocalDate(daysOffset: number): string {
  const date = new Date();
  date.setDate(date.getDate() + daysOffset);
  return toLocalInputDate(date);
}

function isInputDateYmd(value: string): boolean {
  return /^\d{4}-\d{2}-\d{2}$/.test(value);
}

function clampDateToBounds(value: string, bounds: DateBounds, fallback: string): string {
  let safeValue = isInputDateYmd(value) ? value : fallback;

  if (bounds.min && safeValue < bounds.min) {
    safeValue = bounds.min;
  }
  if (bounds.max && safeValue > bounds.max) {
    safeValue = bounds.max;
  }

  return safeValue;
}

function clampRangeToBounds(start: string, end: string, bounds: DateBounds, fallback: string): { start: string; end: string } {
  let safeStart = clampDateToBounds(start, bounds, fallback);
  let safeEnd = clampDateToBounds(end, bounds, fallback);

  if (safeStart > safeEnd) {
    safeStart = safeEnd;
  }

  return { start: safeStart, end: safeEnd };
}

function clampDashboardRangeToAllowedDays(start: string, end: string, earliestAllowedDate: string | null = null): { start: string; end: string } {
  const todayLocal = getRelativeLocalDate(0);
  const yesterdayLocal = getRelativeLocalDate(-1);

  let safeStart = isInputDateYmd(start) ? start : yesterdayLocal;
  let safeEnd = isInputDateYmd(end) ? end : yesterdayLocal;

  if (safeStart > todayLocal) {
    safeStart = todayLocal;
  }
  if (safeEnd > todayLocal) {
    safeEnd = todayLocal;
  }
  if (earliestAllowedDate && isInputDateYmd(earliestAllowedDate)) {
    if (safeStart < earliestAllowedDate) {
      safeStart = earliestAllowedDate;
    }
    if (safeEnd < earliestAllowedDate) {
      safeEnd = earliestAllowedDate;
    }
  }
  if (safeStart > safeEnd) {
    safeStart = safeEnd;
  }

  return { start: safeStart, end: safeEnd };
}

function parseInputDate(value: string): { year: number; month: number; day: number } {
  const [yRaw, mRaw, dRaw] = value.split('-');
  const now = new Date();
  const year = Number.parseInt(yRaw || '', 10);
  const month = Number.parseInt(mRaw || '', 10);
  const day = Number.parseInt(dRaw || '', 10);

  return {
    year: Number.isFinite(year) ? year : now.getFullYear(),
    month: Number.isFinite(month) ? Math.min(12, Math.max(1, month)) : now.getMonth() + 1,
    day: Number.isFinite(day) ? Math.min(31, Math.max(1, day)) : now.getDate()
  };
}

function getDaysInMonth(year: number, month: number): number {
  return new Date(year, month, 0).getDate();
}

function toWheelDate(value: string): DateWheelValue {
  const parsed = parseInputDate(value);
  const clampedDay = Math.min(parsed.day, getDaysInMonth(parsed.year, parsed.month));
  return {
    year: String(parsed.year),
    month: String(parsed.month),
    day: String(clampedDay)
  };
}

function toIsoDate(value: DateWheelValue): string {
  const year = Number(value.year);
  const month = Number(value.month);
  const day = Number(value.day);
  const safeDay = Math.min(day, getDaysInMonth(year, month));
  return `${String(year)}-${String(month).padStart(2, '0')}-${String(safeDay).padStart(2, '0')}`;
}

function getInclusiveDayCount(start: string, end: string): number {
  if (!isInputDateYmd(start) || !isInputDateYmd(end)) {
    return 1;
  }

  const startParts = parseInputDate(start);
  const endParts = parseInputDate(end);
  const startDate = new Date(startParts.year, startParts.month - 1, startParts.day);
  const endDate = new Date(endParts.year, endParts.month - 1, endParts.day);
  const diffDays = Math.floor((endDate.getTime() - startDate.getTime()) / 86400000) + 1;

  return diffDays > 0 ? diffDays : 1;
}

function formatSalesVariation(current: number, previous: number): string {
  if (previous === 0) return '—';
  const rawPercentage = ((current - previous) / Math.abs(previous)) * 100;
  const percentage = Math.sign(rawPercentage) * Math.round(Math.abs(rawPercentage) * 100) / 100;
  return `${percentage > 0 ? '+' : ''}${(percentage === 0 ? 0 : percentage).toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;
}

function formatDateLabel(value: string): string {
  const { year, month, day } = parseInputDate(value);
  return new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: 'long',
    year: 'numeric'
  }).format(new Date(year, month - 1, day));
}

function formatForecastDayOption(value: string): string {
  const { year, month, day } = parseInputDate(value);
  return new Intl.DateTimeFormat('pt-PT', {
    weekday: 'short',
    day: '2-digit',
    month: '2-digit'
  }).format(new Date(year, month - 1, day));
}

function getTodayRange(): { start: string; end: string } {
  const now = new Date();
  const today = toInputDate(now);
  return { start: today, end: today };
}

function getYesterdayRange(): { start: string; end: string } {
  const now = new Date();
  now.setDate(now.getDate() - 1);
  const y = toInputDate(now);
  return { start: y, end: y };
}

function getRangeLastDays(days: number): { start: string; end: string } {
  const end = new Date();
  end.setDate(end.getDate() - 1);
  const start = new Date(end);
  start.setDate(start.getDate() - (days - 1));

  return { start: toInputDate(start), end: toInputDate(end) };
}

function getRangeCurrentMonth(): { start: string; end: string } {
  const end = new Date();
  end.setDate(end.getDate() - 1);
  const start = new Date(end.getFullYear(), end.getMonth(), 1);
  return { start: toInputDate(start), end: toInputDate(end) };
}

function getRangePreviousMonth(): { start: string; end: string } {
  const end = new Date();
  end.setDate(0);
  const start = new Date(end.getFullYear(), end.getMonth(), 1);
  return { start: toInputDate(start), end: toInputDate(end) };
}

function getWeekStartDate(value: string): string {
  const parsed = parseInputDate(value);
  const date = new Date(parsed.year, parsed.month - 1, parsed.day);
  const day = date.getDay();
  const offset = (day + 6) % 7;
  date.setDate(date.getDate() - offset);
  return toInputDate(date);
}

function addDays(value: string, days: number): string {
  const parsed = parseInputDate(value);
  const date = new Date(parsed.year, parsed.month - 1, parsed.day);
  date.setDate(date.getDate() + days);
  return toInputDate(date);
}

function parseIsoDateTime(value: string): Date | null {
  if (!value || value.trim() === '') {
    return null;
  }
  const normalized = value.includes('T') ? value : value.replace(' ', 'T');
  const parsed = new Date(normalized);
  if (Number.isNaN(parsed.getTime())) {
    return null;
  }
  return parsed;
}

function hasDateTimeValue(value: string): boolean {
  return value.trim() !== '';
}

function formatHourMinute(value: string): string {
  const date = parseIsoDateTime(value);
  if (!date) {
    return '--:--';
  }
  return new Intl.DateTimeFormat('pt-PT', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false
  }).format(date);
}

function formatHourMinuteFromTimestamp(timestamp: number | null | undefined): string {
  if (!timestamp || !Number.isFinite(timestamp)) {
    return '--:--';
  }
  return new Intl.DateTimeFormat('pt-PT', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false
  }).format(new Date(timestamp));
}

function formatCurrency(value: number): string {
  return new Intl.NumberFormat('pt-PT', {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(value);
}

function formatNumber(value: number): string {
  return new Intl.NumberFormat('pt-PT', {
    maximumFractionDigits: 0
  }).format(value);
}

function formatNumberCompact(value: number): string {
  return new Intl.NumberFormat('pt-PT', {
    maximumFractionDigits: 3
  }).format(value);
}

function formatOptionalCurrency(value: number | null | undefined): string {
  if (value === null || value === undefined) {
    return 'N/D';
  }
  return formatCurrency(value);
}

function formatOptionalNumber(value: number | null | undefined, suffix = ''): string {
  if (value === null || value === undefined) {
    return 'N/D';
  }
  return `${formatNumberCompact(value)}${suffix}`;
}

function formatAmount(value: number, decimals = 2): string {
  return new Intl.NumberFormat('pt-PT', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals
  }).format(value);
}

function formatShareCompact(value: number): string {
  return formatAmount(value, 1);
}

function compactPaymentTypeLabel(label: string): string {
  let normalized = (label || '').trim();
  if (normalized === '') {
    normalized = 'Sem etiqueta';
  }

  const replacements: Array<[RegExp, string]> = [
    [/sem etiqueta/gi, 'Sem etiq.'],
    [/cart[aã]o/gi, 'Cart.'],
    [/d[eé]bito/gi, 'Deb.'],
    [/cr[eé]dito/gi, 'Cred.'],
    [/transfer[eê]ncia/gi, 'Transf.'],
    [/multibanco/gi, 'MB'],
    [/dinheiro/gi, 'Din.']
  ];
  replacements.forEach(([pattern, replacement]) => {
    normalized = normalized.replace(pattern, replacement);
  });

  normalized = normalized.replace(/\s+/g, ' ').trim();
  if (normalized.length > 16) {
    normalized = `${normalized.slice(0, 15).trimEnd()}…`;
  }

  return normalized;
}

function formatPercent(value: number): string {
  return `${new Intl.NumberFormat('pt-PT', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(value)}%`;
}

function formatDurationFromSeconds(seconds: number): string {
  const safe = Number.isFinite(seconds) && seconds > 0 ? Math.floor(seconds) : 0;
  const hours = Math.floor(safe / 3600);
  const minutes = Math.floor((safe % 3600) / 60);
  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}

function formatDurationFromMinutes(minutes: number): string {
  const safe = Number.isFinite(minutes) && minutes > 0 ? Math.floor(minutes) : 0;
  return formatDurationFromSeconds(safe * 60);
}

function getPunchRowDurationSeconds(row: WorkforcePunchRow): number {
  if (!hasDateTimeValue(row.datahora_in) || !hasDateTimeValue(row.datahora_out)) {
    return 0;
  }

  const explicitDuration = Number(row.duration_seconds || 0);
  if (Number.isFinite(explicitDuration) && explicitDuration > 0) {
    return explicitDuration;
  }

  const startedAt = parseIsoDateTime(row.datahora_in);
  const endedAt = parseIsoDateTime(row.datahora_out);
  if (!startedAt || !endedAt) {
    return 0;
  }

  const deltaSeconds = Math.round((endedAt.getTime() - startedAt.getTime()) / 1000);
  if (!Number.isFinite(deltaSeconds) || deltaSeconds <= 0) {
    return 0;
  }

  return deltaSeconds;
}

function toPunchTimestamp(value: string): number | null {
  if (!hasDateTimeValue(value)) {
    return null;
  }
  const date = parseIsoDateTime(value);
  if (!date) {
    return null;
  }
  const timestamp = date.getTime();
  if (!Number.isFinite(timestamp)) {
    return null;
  }
  return timestamp;
}

function getPunchRowSortTimestamp(row: WorkforcePunchRow): number {
  const inTs = toPunchTimestamp(row.datahora_in);
  const outTs = toPunchTimestamp(row.datahora_out);
  if (inTs !== null && outTs !== null) {
    return Math.min(inTs, outTs);
  }
  if (inTs !== null) {
    return inTs;
  }
  if (outTs !== null) {
    return outTs;
  }
  return Number.MAX_SAFE_INTEGER;
}

function getPunchSequenceStats(rows: WorkforcePunchRow[]): { totalSeconds: number; pairedRowsCount: number } {
  if (!rows.length) {
    return { totalSeconds: 0, pairedRowsCount: 0 };
  }

  const orderedRows = [...rows].sort((a, b) => {
    const diff = getPunchRowSortTimestamp(a) - getPunchRowSortTimestamp(b);
    if (diff !== 0) {
      return diff;
    }
    return Number(a.id || 0) - Number(b.id || 0);
  });

  let totalSeconds = 0;
  let pairedRowsCount = 0;
  let pendingInTs: number | null = null;

  orderedRows.forEach((row) => {
    const inTs = toPunchTimestamp(row.datahora_in);
    const outTs = toPunchTimestamp(row.datahora_out);

    if (inTs !== null && outTs !== null) {
      const directDuration = getPunchRowDurationSeconds(row);
      if (directDuration > 0) {
        totalSeconds += directDuration;
        pairedRowsCount += 1;
      }
      pendingInTs = null;
      return;
    }

    if (inTs !== null) {
      pendingInTs = inTs;
      return;
    }

    if (outTs !== null && pendingInTs !== null && outTs > pendingInTs) {
      totalSeconds += Math.round((outTs - pendingInTs) / 1000);
      pairedRowsCount += 1;
      pendingInTs = null;
    }
  });

  return { totalSeconds, pairedRowsCount };
}

function isIsoDateWithinRange(dateIso: string, startIso: string, endIso: string): boolean {
  if (!dateIso || !startIso || !endIso) {
    return false;
  }
  return dateIso >= startIso && dateIso <= endIso;
}

function getFechoStatusClass(status: number): string {
  if (status === 1) {
    return 'status-validado';
  }
  if (status === 9) {
    return 'status-cancelado';
  }
  return 'status-rascunho';
}

function getFechoModalModeLabel(mode: FechoModalMode): string {
  if (mode === 'create') {
    return 'Novo Fecho Financeiro';
  }
  if (mode === 'edit') {
    return 'Editar Fecho Financeiro';
  }
  return 'Detalhe do Fecho Financeiro';
}

function getDatePickerTitle(scope: DateScope, field: DateField): string {
  if (scope === 'equipaWeek') {
    return 'Início da Semana';
  }
  if (scope === 'equipaDay') {
    return 'Dia do Horário';
  }
  if (scope === 'equipaPunch') {
    return 'Dia do Ponto';
  }
  if (scope === 'fechoModal' || field === 'single') {
    return 'Data';
  }
  return field === 'start' ? 'Data de início' : 'Data de fim';
}

function getPaymentTotals(rows: PaymentSale[]): { transactions: number; totalHt: number; totalTtc: number } {
  return rows.reduce(
    (acc, row) => {
      acc.transactions += Number(row.transaction_count || 0);
      acc.totalHt += Number(row.total_liquido || 0);
      acc.totalTtc += Number(row.total_gross || 0);
      return acc;
    },
    { transactions: 0, totalHt: 0, totalTtc: 0 }
  );
}

function getEmployeeTotals(rows: EmployeeSale[]): { transactions: number; qty: number; totalHt: number; totalTtc: number } {
  return rows.reduce(
    (acc, row) => {
      acc.transactions += Number(row.transaction_count || 0);
      acc.qty += Number(row.total_quantity_sold || 0);
      acc.totalHt += Number(row.total_sales_value_ht || 0);
      acc.totalTtc += Number(row.total_sales_value_ttc || 0);
      return acc;
    },
    { transactions: 0, qty: 0, totalHt: 0, totalTtc: 0 }
  );
}

function tooltipCurrency(value: number | string): string {
  return formatCurrency(Number(value));
}

function getErrorMessage(error: unknown, fallback: string): string {
  if (error instanceof Error && error.message) {
    return error.message;
  }
  return fallback;
}

function isUnauthorizedError(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401;
}

function isRunningStandalone(): boolean {
  const nav = window.navigator as Navigator & { standalone?: boolean };
  return window.matchMedia('(display-mode: standalone)').matches || nav.standalone === true;
}

function isIosInstallHintEligible(): boolean {
  const ua = window.navigator.userAgent || '';
  const isIos = /iphone|ipad|ipod/i.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  return isIos && !isRunningStandalone();
}

function getInitialTabFromQuery(): TabKey {
  const value = new URLSearchParams(window.location.search).get('tab');
  if (
    value === 'kitchen' ||
    value === 'eats' ||
    value === 'dashboard' ||
    value === 'forecast' ||
    value === 'stock_forecast' ||
    value === 'horarios' ||
    value === 'barras' ||
    value === 'ponto' ||
    value === 'fecho' ||
    value === 'produtos' ||
    value === 'about'
  ) {
    return value;
  }
  if (value === 'sobre') {
    return 'about';
  }
  if (value === 'equipa') {
    return 'horarios';
  }
  if (value === 'equipa_barras' || value === 'daybars') {
    return 'barras';
  }
  if (value === 'precos') {
    return 'produtos';
  }
  if (value === 'previsao' || value === 'salesforecast') {
    return 'forecast';
  }
  if (value === 'stock' || value === 'stockforecast' || value === 'previsaostock') {
    return 'stock_forecast';
  }
  return 'dashboard';
}

function getEntityRefLabel(entityId: number, entityLabel: string): string {
  const normalized = (entityLabel || '').trim();
  if (normalized !== '') {
    const basePart = normalized.split(/\s*-\s*/)[0].trim();
    const firstToken = basePart.split(/\s+/)[0].trim();
    const compact = firstToken.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    if (compact.length >= 2 && compact.length <= 10) {
      return compact;
    }
  }
  return String(entityId);
}

const MODULE_ENTRY_URL = '/custom/kreaflow/mobile.php';
const AUTH_LOGO_URL = '/custom/kreaflow/img/kreaflow-logo-transparent.png';
const ABOUT_LOGO_URL = AUTH_LOGO_URL;
const TOTP_CODE_LENGTH = 6;
const ABOUT_INFO_DEFAULTS = {
  gpl_notice: 'GPLv3 or later. See COPYING.',
  module: 'KreaFlow',
  version: '',
  editor: 'Kreativität Works',
  license: 'GPL-3.0-or-later',
  support: 'mail@kreativitat.com',
  website: 'http://kreativitat.com'
} as const;

function createEmptyTotpDigits(): string[] {
  return Array.from({ length: TOTP_CODE_LENGTH }, () => '');
}

function normalizeTotpCode(value: string): string {
  return value.replace(/\D+/g, '').slice(0, TOTP_CODE_LENGTH);
}

export default function App() {
  const defaultRange = getYesterdayRange();
  const fechoDefaultRange = getRangeLastDays(7);
  const workforceDefaultDay = toInputDate(new Date());
  const workforceDefaultWeekStart = getWeekStartDate(workforceDefaultDay);
  const initialSession = getBootstrapSession();
  const initialDashboard = readDashboardCache(initialSession, defaultRange.start, defaultRange.end, 1);
  const initialTab = getInitialTabFromQuery();

  const [eatsDate, setEatsDate] = useState(() => getWeekStartDate(getRelativeLocalDate(-7)));
  const [tab, setTab] = useState<TabKey>(initialTab);
  const [isNavOpen, setIsNavOpen] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [desktopNavigation, setDesktopNavigation] = useState(() => window.matchMedia('(min-width: 1200px)').matches);
  useEffect(() => {
    const query = window.matchMedia('(min-width: 1200px)');
    const update = () => setDesktopNavigation(query.matches);
    query.addEventListener('change', update);
    return () => query.removeEventListener('change', update);
  }, []);
  const [loading, setLoading] = useState(initialSession === null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [session, setSession] = useState<SessionData | null>(initialSession);

  const [dateStart, setDateStart] = useState(defaultRange.start);
  const [dateEnd, setDateEnd] = useState(defaultRange.end);
  const [fechoDateStart, setFechoDateStart] = useState(fechoDefaultRange.start);
  const [fechoDateEnd, setFechoDateEnd] = useState(fechoDefaultRange.end);
  const [fechoStatus, setFechoStatus] = useState(-1);
  const [fechoSearch, setFechoSearch] = useState('');
  const [fechoData, setFechoData] = useState<FechoFinanceiroList | null>(null);
  const [fechoLoading, setFechoLoading] = useState(false);
  const [fechoError, setFechoError] = useState<string | null>(null);
  const [fechoLoaded, setFechoLoaded] = useState(false);
  const [fechoMeta, setFechoMeta] = useState<FechoFinanceiroMeta | null>(null);
  const [fechoMetaLoading, setFechoMetaLoading] = useState(false);
  const [fechoMetaError, setFechoMetaError] = useState<string | null>(null);
  const [isFechoModalOpen, setIsFechoModalOpen] = useState(false);
  const [fechoModalMode, setFechoModalMode] = useState<FechoModalMode>('view');
  const [fechoModalLoading, setFechoModalLoading] = useState(false);
  const [fechoModalError, setFechoModalError] = useState<string | null>(null);
  const [fechoSaving, setFechoSaving] = useState(false);
  const [fechoForm, setFechoForm] = useState<FechoFormState | null>(null);
  const [isDatePickerOpen, setIsDatePickerOpen] = useState(false);
  const [activeDateScope, setActiveDateScope] = useState<DateScope>('dashboard');
  const [activeDateField, setActiveDateField] = useState<DateField>('start');
  const [dateWheelValue, setDateWheelValue] = useState<DateWheelValue>(() => toWheelDate(defaultRange.start));
  const [dashboard, setDashboard] = useState<SalesDashboard | null>(initialDashboard);
  const [dashboardLoading, setDashboardLoading] = useState(false);
  const [dashboardError, setDashboardError] = useState<string | null>(null);
  const [dashboardTopProductsPage, setDashboardTopProductsPage] = useState(1);
  const [forecastData, setForecastData] = useState<SalesForecast | null>(null);
  const [forecastLoading, setForecastLoading] = useState(false);
  const [forecastError, setForecastError] = useState<string | null>(null);
  const [forecastLoaded, setForecastLoaded] = useState(false);
  const [stockForecastData, setStockForecastData] = useState<StockForecast | null>(null);
  const [stockForecastLoading, setStockForecastLoading] = useState(false);
  const [stockForecastError, setStockForecastError] = useState<string | null>(null);
  const [stockForecastLoaded, setStockForecastLoaded] = useState(false);
  const [stockForecastDate, setStockForecastDate] = useState('');
  const [workforceWeekStart, setWorkforceWeekStart] = useState(workforceDefaultWeekStart);
  const [workforceDay, setWorkforceDay] = useState(workforceDefaultDay);
  const [workforcePunchDate, setWorkforcePunchDate] = useState(workforceDefaultDay);
  const [workforceData, setWorkforceData] = useState<WorkforceBoard | null>(null);
  const [workforceLoading, setWorkforceLoading] = useState(false);
  const [workforceError, setWorkforceError] = useState<string | null>(null);
  const [workforceLoaded, setWorkforceLoaded] = useState(false);

  const [productQuery, setProductQuery] = useState('');
  const [productLayoutMode, setProductLayoutMode] = useState<ProductLayoutMode>('search');
  const [hasProductSearchRun, setHasProductSearchRun] = useState(false);
  const [products, setProducts] = useState<ProductItem[]>([]);
  const [productCategories, setProductCategories] = useState<ProductCategory[]>([]);
  const [productCategoriesLoaded, setProductCategoriesLoaded] = useState(false);
  const [categoriesLoading, setCategoriesLoading] = useState(false);
  const [categoriesError, setCategoriesError] = useState<string | null>(null);
  const [categoryProductsById, setCategoryProductsById] = useState<Record<number, ProductItem[]>>({});
  const [categoryProductsLoading, setCategoryProductsLoading] = useState(false);
  const [categoryProductsError, setCategoryProductsError] = useState<string | null>(null);
  const [productsLoading, setProductsLoading] = useState(false);
  const [productsError, setProductsError] = useState<string | null>(null);
  const [logoutLoading, setLogoutLoading] = useState(false);
  const [isEntityPickerOpen, setIsEntityPickerOpen] = useState(false);
  const [entitySwitchLoading, setEntitySwitchLoading] = useState<number | null>(null);
  const [entitySwitchError, setEntitySwitchError] = useState<string | null>(null);
  const [installPromptEvent, setInstallPromptEvent] = useState<BeforeInstallPromptEvent | null>(null);
  const [installMessage, setInstallMessage] = useState<string | null>(null);
  const [isProductDetailOpen, setIsProductDetailOpen] = useState(false);
  const [productDetailLoading, setProductDetailLoading] = useState(false);
  const [productDetailError, setProductDetailError] = useState<string | null>(null);
  const [productDetail, setProductDetail] = useState<ProductDetail | null>(null);
  const [loginMeta, setLoginMeta] = useState<LoginMeta | null>(null);
  const [loginUsername, setLoginUsername] = useState('');
  const [loginPassword, setLoginPassword] = useState('');
  const [loginTotpDigits, setLoginTotpDigits] = useState<string[]>(() => createEmptyTotpDigits());
  const [loginEntity, setLoginEntity] = useState<number>(initialSession?.entity || 1);
  const [loginLoading, setLoginLoading] = useState(false);
  const [loginError, setLoginError] = useState<string | null>(null);
  const availableTabs = useMemo(() => getAccessibleTabs(session), [session]);
  const aboutInfo = useMemo(() => {
    const source = session?.module_info;
    if (!source) {
      return ABOUT_INFO_DEFAULTS;
    }

    return {
      gpl_notice: source.gpl_notice || ABOUT_INFO_DEFAULTS.gpl_notice,
      module: source.module || ABOUT_INFO_DEFAULTS.module,
      version: source.version || ABOUT_INFO_DEFAULTS.version,
      editor: source.editor || ABOUT_INFO_DEFAULTS.editor,
      license: source.license || ABOUT_INFO_DEFAULTS.license,
      support: source.support || ABOUT_INFO_DEFAULTS.support,
      website: source.website || ABOUT_INFO_DEFAULTS.website
    };
  }, [session]);
  const historyLimitDays = useMemo(() => {
    if (!session || session.rights.history_readall === 1) {
      return null;
    }

    const parsed = Number(session.rights.history_limit_days || 0);
    if (!Number.isFinite(parsed) || parsed <= 0) {
      return 15;
    }

    return Math.min(Math.trunc(parsed), 3650);
  }, [session]);
  const historyEarliestDate = useMemo(() => {
    if (historyLimitDays === null) {
      return null;
    }
    return getRelativeLocalDate(-historyLimitDays);
  }, [historyLimitDays]);

  const resolveDateBounds = (scope: DateScope): DateBounds => {
    const defaultMin = historyEarliestDate;

    if (scope === 'dashboard' || scope === 'eats') {
      return {
        min: defaultMin,
        max: getRelativeLocalDate(0)
      };
    }

    if (scope === 'fecho') {
      let minDate = defaultMin;
      const fechoLimitDate = fechoData?.period.limit_date;
      if (isInputDateYmd(fechoLimitDate || '')) {
        const safeFechoLimitDate = fechoLimitDate as string;
        if (!minDate || safeFechoLimitDate > minDate) {
          minDate = safeFechoLimitDate;
        }
      }
      return {
        min: minDate,
        max: getRelativeLocalDate(-1)
      };
    }

    if (scope === 'equipaWeek' || scope === 'equipaDay' || scope === 'equipaPunch') {
      return {
        min: defaultMin,
        max: null
      };
    }

    if (scope === 'fechoModal') {
      return {
        min: defaultMin,
        max: null
      };
    }

    return {
      min: null,
      max: null
    };
  };

  const clampDateForScope = (value: string, scope: DateScope): string => {
    const bounds = resolveDateBounds(scope);
    const fallback = bounds.max || bounds.min || getRelativeLocalDate(0);
    return clampDateToBounds(value, bounds, fallback);
  };

  const clampDateWheelToScope = (value: DateWheelValue, scope: DateScope): DateWheelValue => {
    const safeDate = clampDateForScope(toIsoDate(value), scope);
    return toWheelDate(safeDate);
  };

  const clampDashboardRangeWithPermissions = (start: string, end: string): { start: string; end: string } =>
    clampDashboardRangeToAllowedDays(start, end, historyEarliestDate);

  const clampFechoRangeWithPermissions = (start: string, end: string): { start: string; end: string } => {
    const bounds = resolveDateBounds('fecho');
    const fallback = bounds.max || bounds.min || getRelativeLocalDate(-1);
    return clampRangeToBounds(start, end, bounds, fallback);
  };

  const clearAuthenticatedData = () => {
    setDashboard(null);
    setDashboardError(null);
    setDashboardLoading(false);
    setDashboardTopProductsPage(1);
    setForecastData(null);
    setForecastError(null);
    setForecastLoading(false);
    setForecastLoaded(false);
    setStockForecastData(null);
    setStockForecastError(null);
    setStockForecastLoading(false);
    setStockForecastLoaded(false);
    setStockForecastDate('');
    setWorkforceData(null);
    setWorkforceError(null);
    setWorkforceLoading(false);
    setWorkforceLoaded(false);
    setFechoData(null);
    setFechoError(null);
    setFechoLoading(false);
    setFechoLoaded(false);
    setFechoMeta(null);
    setFechoMetaError(null);
    setFechoMetaLoading(false);
    setProducts([]);
    setProductsError(null);
    setProductsLoading(false);
    setHasProductSearchRun(false);
    setProductDetail(null);
    setProductDetailError(null);
    setProductDetailLoading(false);
    setIsProductDetailOpen(false);
    setProductCategories([]);
    setProductCategoriesLoaded(false);
    setCategoryProductsById({});
    setCategoriesError(null);
    setCategoriesLoading(false);
    setCategoryProductsError(null);
    setCategoryProductsLoading(false);
    setIsFechoModalOpen(false);
    setFechoModalError(null);
    setFechoModalLoading(false);
    setFechoSaving(false);
    setFechoForm(null);
    setEntitySwitchError(null);
    setEntitySwitchLoading(null);
    setIsEntityPickerOpen(false);
  };

  const handleUnauthorizedSession = () => {
    clearDashboardCache();
    clearAuthenticatedData();
    setSession(null);
    setTab('dashboard');
    setIsNavOpen(false);
    setLoading(false);
    setLogoutLoading(false);
    setLoginPassword('');
    setLoginTotpDigits(createEmptyTotpDigits());
    setLoginError(null);
  };

  const loadLoginMetadata = async () => {
    try {
      const meta = await getLoginMeta();
      setLoginMeta(meta);
      const firstEntityId = meta.entities.length > 0 ? meta.entities[0].id : 0;
      const nextEntity = meta.default_entity > 0 ? meta.default_entity : firstEntityId;
      if (nextEntity > 0) {
        setLoginEntity(nextEntity);
      }
    } catch (_error) {
      setLoginMeta(null);
    }
  };

  const loadDashboard = async (
    start: string,
    end: string,
    topProductsPage = dashboardTopProductsPage,
    cacheSession: SessionData | null = session
  ) => {
    const safeRange = clampDashboardRangeWithPermissions(start, end);
    const safeTopProductsPage = Number.isFinite(topProductsPage) ? Math.max(1, Math.trunc(topProductsPage)) : 1;
    if (safeRange.start !== dateStart) {
      setDateStart(safeRange.start);
    }
    if (safeRange.end !== dateEnd) {
      setDateEnd(safeRange.end);
    }

    setDashboardLoading(true);
    setDashboardError(null);
    try {
      const data = await getDashboard(safeRange.start, safeRange.end, safeTopProductsPage);
      setDashboard(data);
      const effectiveTopProductsPageRaw = Number(data.top_products_page || safeTopProductsPage);
      const effectiveTopProductsPage =
        Number.isFinite(effectiveTopProductsPageRaw) && effectiveTopProductsPageRaw > 0
          ? Math.trunc(effectiveTopProductsPageRaw)
          : safeTopProductsPage;
      if (effectiveTopProductsPage !== dashboardTopProductsPage) {
        setDashboardTopProductsPage(effectiveTopProductsPage);
      }
      const periodStart = isInputDateYmd(data.period.date_start) ? data.period.date_start : safeRange.start;
      const periodEnd = isInputDateYmd(data.period.date_end) ? data.period.date_end : safeRange.end;
      const effectiveRange = clampDashboardRangeWithPermissions(periodStart, periodEnd);
      if (
        effectiveRange.start === defaultRange.start &&
        effectiveRange.end === defaultRange.end &&
        effectiveTopProductsPage === 1
      ) {
        writeDashboardCache(cacheSession, effectiveRange.start, effectiveRange.end, effectiveTopProductsPage, data);
      }
      if (effectiveRange.start !== safeRange.start) {
        setDateStart(effectiveRange.start);
      }
      if (effectiveRange.end !== safeRange.end) {
        setDateEnd(effectiveRange.end);
      }
    } catch (error) {
      if (isUnauthorizedError(error)) {
        handleUnauthorizedSession();
        return;
      }
      setDashboardError(getErrorMessage(error, 'Falha ao carregar dashboard.'));
    } finally {
      setDashboardLoading(false);
    }
  };

  const loadForecast = async () => {
    const defaultForecastRange = getRangeLastDays(30);
    const safeRange = clampDashboardRangeWithPermissions(defaultForecastRange.start, defaultForecastRange.end);
    setForecastLoading(true);
    setForecastError(null);
    try {
      const data = await getSalesForecast(safeRange.start, safeRange.end);
      setForecastData(data);
      setForecastLoaded(true);
    } catch (error) {
      if (isUnauthorizedError(error)) {
        handleUnauthorizedSession();
        return;
      }
      setForecastError(getErrorMessage(error, 'Falha ao carregar previsão de vendas.'));
    } finally {
      setForecastLoading(false);
    }
  };

  const loadStockForecast = async () => {
    const defaultForecastRange = getRangeLastDays(30);
    const safeRange = clampDashboardRangeWithPermissions(defaultForecastRange.start, defaultForecastRange.end);
    setStockForecastLoading(true);
    setStockForecastError(null);
    try {
      const data = await getStockForecast(safeRange.start, safeRange.end);
      setStockForecastData(data);
      setStockForecastLoaded(true);
    } catch (error) {
      if (isUnauthorizedError(error)) {
        handleUnauthorizedSession();
        return;
      }
      setStockForecastError(getErrorMessage(error, 'Falha ao carregar previsão de stock.'));
    } finally {
      setStockForecastLoading(false);
    }
  };

  const loadWorkforce = async (
    weekStart = workforceWeekStart,
    day = workforceDay,
    punchDate = workforcePunchDate
  ) => {
    const safeWeekStart = clampDateForScope(getWeekStartDate(weekStart), 'equipaWeek');
    const safeDay = clampDateForScope(day, 'equipaDay');
    const resolvedPunchDate = punchDate || workforcePunchDate || toInputDate(new Date());
    const safePunchDate = clampDateForScope(resolvedPunchDate, 'equipaPunch');

    if (safeWeekStart !== workforceWeekStart) {
      setWorkforceWeekStart(safeWeekStart);
    }
    if (safeDay !== workforceDay) {
      setWorkforceDay(safeDay);
    }
    if (safePunchDate !== workforcePunchDate) {
      setWorkforcePunchDate(safePunchDate);
    }

    setWorkforceLoading(true);
    setWorkforceError(null);
    try {
      const data = await getWorkforceBoard(safeWeekStart, safeDay, safePunchDate);
      setWorkforceData(data);
      setWorkforceLoaded(true);
      const nextWeekStart = clampDateForScope(
        getWeekStartDate(isInputDateYmd(data.period.week_start) ? data.period.week_start : safeWeekStart),
        'equipaWeek'
      );
      const nextDay = clampDateForScope(isInputDateYmd(data.period.day) ? data.period.day : safeDay, 'equipaDay');
      const nextPunchDate = clampDateForScope(
        isInputDateYmd(data.period.punch_date) ? data.period.punch_date : safePunchDate,
        'equipaPunch'
      );
      if (nextWeekStart !== safeWeekStart) {
        setWorkforceWeekStart(nextWeekStart);
      }
      if (nextDay !== safeDay) {
        setWorkforceDay(nextDay);
      }
      if (nextPunchDate !== safePunchDate) {
        setWorkforcePunchDate(nextPunchDate);
      }
    } catch (error) {
      if (isUnauthorizedError(error)) {
        handleUnauthorizedSession();
        return;
      }
      setWorkforceError(getErrorMessage(error, 'Falha ao carregar dados de horários e ponto da equipa.'));
    } finally {
      setWorkforceLoading(false);
    }
  };

  const loadFechoFinanceiro = async (
    start = fechoDateStart,
    end = fechoDateEnd,
    status = fechoStatus,
    search = fechoSearch
  ) => {
    const safeRange = clampFechoRangeWithPermissions(start, end);
    if (safeRange.start !== fechoDateStart) {
      setFechoDateStart(safeRange.start);
    }
    if (safeRange.end !== fechoDateEnd) {
      setFechoDateEnd(safeRange.end);
    }

    setFechoLoading(true);
    setFechoError(null);
    try {
      const data = await getFechoFinanceiroList(safeRange.start, safeRange.end, status, search, 80);
      setFechoData(data);
      setFechoLoaded(true);
      const bounds = resolveDateBounds('fecho');
      const responseLimitDate = isInputDateYmd(data.period.limit_date) ? data.period.limit_date : null;
      if (responseLimitDate && (!bounds.min || responseLimitDate > bounds.min)) {
        bounds.min = responseLimitDate;
      }
      const periodStart = isInputDateYmd(data.period.date_start) ? data.period.date_start : safeRange.start;
      const periodEnd = isInputDateYmd(data.period.date_end) ? data.period.date_end : safeRange.end;
      const fallback = bounds.max || bounds.min || safeRange.end;
      const effectiveRange = clampRangeToBounds(periodStart, periodEnd, bounds, fallback);
      if (effectiveRange.start !== safeRange.start) {
        setFechoDateStart(effectiveRange.start);
      }
      if (effectiveRange.end !== safeRange.end) {
        setFechoDateEnd(effectiveRange.end);
      }
    } catch (error) {
      if (isUnauthorizedError(error)) {
        handleUnauthorizedSession();
        return;
      }
      setFechoError(getErrorMessage(error, 'Falha ao carregar fecho financeiro.'));
    } finally {
      setFechoLoading(false);
    }
  };

  const loadFechoMeta = async () => {
    setFechoMetaLoading(true);
    setFechoMetaError(null);
    try {
      const meta = await getFechoMeta();
      setFechoMeta(meta);
    } catch (error) {
      if (isUnauthorizedError(error)) {
        handleUnauthorizedSession();
        return;
      }
      setFechoMetaError(getErrorMessage(error, 'Falha ao carregar dados de apoio do fecho.'));
    } finally {
      setFechoMetaLoading(false);
    }
  };

  const loadCategoryProductSections = async (categories: ProductCategory[]) => {
    if (!categories.length) {
      setCategoryProductsById({});
      setCategoryProductsError(null);
      return;
    }

    setCategoryProductsLoading(true);
    setCategoryProductsError(null);
    try {
      const sectionEntries = await Promise.all(
        categories.map(async (category) => {
          const rows = await searchProducts('', PRODUCT_CATEGORY_SECTION_LIMIT, category.id);
          return [category.id, rows] as const;
        })
      );

      const nextMap: Record<number, ProductItem[]> = {};
      sectionEntries.forEach(([categoryId, rows]) => {
        nextMap[categoryId] = rows;
      });
      setCategoryProductsById(nextMap);
    } catch (error) {
      if (isUnauthorizedError(error)) {
        handleUnauthorizedSession();
        return;
      }
      setCategoryProductsError(getErrorMessage(error, 'Falha ao carregar produtos por categoria.'));
      setCategoryProductsById({});
    } finally {
      setCategoryProductsLoading(false);
    }
  };

  const loadProductCategoryList = async () => {
    setCategoriesLoading(true);
    setCategoriesError(null);
    try {
      const categories = await getProductCategories();
      setProductCategories(categories);
      await loadCategoryProductSections(categories);
    } catch (error) {
      if (isUnauthorizedError(error)) {
        handleUnauthorizedSession();
        return;
      }
      setCategoriesError(getErrorMessage(error, 'Falha ao carregar categorias.'));
      setCategoryProductsById({});
    } finally {
      setProductCategoriesLoaded(true);
      setCategoriesLoading(false);
    }
  };

  useEffect(() => {
    const bootstrap = async () => {
      try {
        let whoami = initialSession;
        if (!whoami) {
          try {
            whoami = await getWhoAmI();
          } catch (error) {
            if (isUnauthorizedError(error)) {
              whoami = null;
              await loadLoginMetadata();
            } else {
              throw error;
            }
          }
        }
        setSession(whoami);
        setLoadError(null);
        if (whoami) {
          if (canAccessTab(whoami, 'dashboard')) {
            const cachedDashboard = readDashboardCache(whoami, defaultRange.start, defaultRange.end, 1);
            if (cachedDashboard) {
              setDashboard(cachedDashboard);
            }
            void loadDashboard(defaultRange.start, defaultRange.end, 1, whoami);
          }
        }
      } catch (error) {
        setLoadError(getErrorMessage(error, 'Falha ao carregar sessão.'));
      } finally {
        setLoading(false);
      }
    };

    bootstrap().catch(() => {
      setLoadError('Erro inesperado na aplicação.');
      setLoading(false);
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    const onBeforeInstallPrompt = (event: Event) => {
      event.preventDefault();
      setInstallPromptEvent(event as BeforeInstallPromptEvent);
      setInstallMessage(null);
    };

    const onAppInstalled = () => {
      setInstallPromptEvent(null);
      setInstallMessage('Aplicação instalada.');
    };

    window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt);
    window.addEventListener('appinstalled', onAppInstalled);

    return () => {
      window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt);
      window.removeEventListener('appinstalled', onAppInstalled);
    };
  }, []);

  useEffect(() => {
    if (!loading && !session && !loginMeta) {
      void loadLoginMetadata();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loading, session, loginMeta]);

  useEffect(() => {
    if (!session || availableTabs.length === 0) {
      return;
    }
    if (availableTabs.some((tabEntry) => tabEntry.key === tab)) {
      return;
    }

    const fallbackTab = availableTabs[0].key;
    setTab(fallbackTab);
    const nextUrl = new URL(window.location.href);
    nextUrl.searchParams.set('tab', fallbackTab);
    window.history.replaceState(null, '', nextUrl.toString());
  }, [availableTabs, session, tab]);

  useEffect(() => {
    if (tab !== 'produtos' || !session || productCategoriesLoaded || categoriesLoading) {
      return;
    }
    void loadProductCategoryList();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, session, productCategoriesLoaded, categoriesLoading]);

  useEffect(() => {
    if (tab !== 'forecast' || forecastLoaded || forecastLoading || !session) {
      return;
    }
    void loadForecast();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, forecastLoaded, forecastLoading, session]);

  useEffect(() => {
    if (tab !== 'stock_forecast' || stockForecastLoaded || stockForecastLoading || !session) {
      return;
    }
    void loadStockForecast();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, stockForecastLoaded, stockForecastLoading, session]);

  useEffect(() => {
    if (tab !== 'fecho' || fechoLoaded || fechoLoading || !session) {
      return;
    }
    void loadFechoFinanceiro();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, fechoLoaded, fechoLoading, session]);

  useEffect(() => {
    if (tab !== 'fecho' || fechoMeta || fechoMetaLoading || !session) {
      return;
    }
    void loadFechoMeta();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, fechoMeta, fechoMetaLoading, session]);

  useEffect(() => {
    if ((tab !== 'horarios' && tab !== 'barras') || workforceLoaded || workforceLoading || !session) {
      return;
    }
    void loadWorkforce();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, workforceLoaded, workforceLoading, session]);

  useEffect(() => {
    if (tab !== 'ponto' || !session) {
      return;
    }

    const today = toInputDate(new Date());
    if (workforcePunchDate === today && workforceData?.period.punch_date === today) {
      return;
    }

    setWorkforcePunchDate(today);
    void loadWorkforce(workforceWeekStart, workforceDay, today);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, session]);

  const paymentRows = useMemo<PaymentSale[]>(() => {
    const rows = dashboard?.sales_by_payment_type || [];

    return rows.map((row) => {
      const transactionCount = Number(row.transaction_count || 0);
      const totalLiquido = Number(row.total_liquido || 0);
      const totalGross = Number(row.total_gross || 0);

      return {
        paymenttype_name: row.paymenttype_name || 'Sem etiqueta',
        transaction_count: Number.isFinite(transactionCount) ? transactionCount : 0,
        total_liquido: Number.isFinite(totalLiquido) ? totalLiquido : 0,
        total_gross: Number.isFinite(totalGross) ? totalGross : 0
      };
    });
  }, [dashboard?.sales_by_payment_type]);

  const paymentChartRows = useMemo(
    () => paymentRows.filter((row) => row.total_gross > 0),
    [paymentRows]
  );

  const paymentTotals = useMemo(
    () => getPaymentTotals(paymentRows),
    [paymentRows]
  );

  const employeeTotals = useMemo(
    () => getEmployeeTotals(dashboard?.sales_by_employee || []),
    [dashboard?.sales_by_employee]
  );
  const hourlyChartMax = useMemo(() => {
    const rows = dashboard?.hourly_sales || [];
    let max = 0;
    for (const row of rows) {
      const value = Number(row.revenue_ttc || 0);
      if (Number.isFinite(value) && value > max) {
        max = value;
      }
    }
    return max;
  }, [dashboard?.hourly_sales]);
  const forecastChartRows = useMemo(() => {
    const rows = forecastData?.chart_rows || [];
    const timeline = new Map<string, { date: string; historical_ttc: number | null; forecast_ttc: number | null }>();
    const historicalSeries: Array<{ date: string; value: number }> = [];

    rows.forEach((row) => {
      const date = (row.date || '').trim();
      if (date === '') {
        return;
      }

      const parsedRevenue = Number(row.revenue_ttc || 0);
      const revenue = Number.isFinite(parsedRevenue) ? parsedRevenue : 0;
      const point = timeline.get(date) || { date, historical_ttc: null, forecast_ttc: null };

      if (row.series === 'historical') {
        point.historical_ttc = revenue;
        historicalSeries.push({ date, value: revenue });
      } else if (row.series === 'forecast') {
        point.forecast_ttc = revenue;
      }

      timeline.set(date, point);
    });

    if (historicalSeries.length > 0) {
      historicalSeries.sort((left, right) => left.date.localeCompare(right.date));
      const lastHistorical = historicalSeries[historicalSeries.length - 1];
      const bridgePoint = timeline.get(lastHistorical.date) || {
        date: lastHistorical.date,
        historical_ttc: lastHistorical.value,
        forecast_ttc: null
      };

      bridgePoint.historical_ttc = lastHistorical.value;
      bridgePoint.forecast_ttc = lastHistorical.value;
      timeline.set(lastHistorical.date, bridgePoint);
    }

    return Array.from(timeline.values())
      .sort((left, right) => left.date.localeCompare(right.date))
      .map((row) => ({
        ...row,
        combined_ttc: row.forecast_ttc ?? row.historical_ttc ?? 0
      }));
  }, [forecastData?.chart_rows]);
  const forecastColorBoundary = useMemo(() => {
    if (forecastChartRows.length <= 1) {
      return 100;
    }
    const bridgeIndex = forecastChartRows.findIndex((row) => row.forecast_ttc !== null);
    if (bridgeIndex < 0) {
      return 100;
    }
    return Math.max(0, Math.min(100, (bridgeIndex / (forecastChartRows.length - 1)) * 100));
  }, [forecastChartRows]);
  const stockForecastDates = useMemo(() => {
    const dates = new Set<string>();
    const firstFutureDate = getRelativeLocalDate(1);
    for (const product of stockForecastData?.products || []) {
      for (const row of product.daily || []) {
        if (isInputDateYmd(row.date) && row.date >= firstFutureDate) {
          dates.add(row.date);
        }
      }
    }
    return Array.from(dates).sort((left, right) => left.localeCompare(right));
  }, [stockForecastData?.products]);
  const selectedStockForecastDate = stockForecastDates.includes(stockForecastDate)
    ? stockForecastDate
    : (stockForecastDates[0] || '');
  const selectedStockForecastSales = useMemo(() => {
    if (!selectedStockForecastDate) {
      return 0;
    }
    for (const product of stockForecastData?.products || []) {
      const row = product.daily.find((dailyRow) => dailyRow.date === selectedStockForecastDate);
      if (row) {
        const forecastSales = Number(row.forecast_sales_ttc || 0);
        return Number.isFinite(forecastSales) ? forecastSales : 0;
      }
    }
    return 0;
  }, [selectedStockForecastDate, stockForecastData?.products]);
  const stockForecastDayProducts = useMemo(() => {
    if (!selectedStockForecastDate) {
      return [];
    }

    const firstFutureDate = getRelativeLocalDate(1);
    return (stockForecastData?.products || [])
      .map((product) => {
        let requiredToday = 0;
        let cumulativeRequired = 0;
        for (const row of product.daily || []) {
          const requiredQuantity = Number(row.required_quantity || 0);
          const safeRequiredQuantity = Number.isFinite(requiredQuantity) ? requiredQuantity : 0;
          if (row.date >= firstFutureDate && row.date <= selectedStockForecastDate) {
            cumulativeRequired += safeRequiredQuantity;
          }
          if (row.date === selectedStockForecastDate) {
            requiredToday = safeRequiredQuantity;
          }
        }

        const currentStock = Number(product.current_stock || 0);
        const safeCurrentStock = Number.isFinite(currentStock) ? currentStock : 0;
        const projectedStock = safeCurrentStock - cumulativeRequired;

        return {
          product,
          requiredToday,
          projectedStock,
          shortage: Math.max(0, -projectedStock)
        };
      })
      .sort((left, right) => {
        if (right.shortage !== left.shortage) {
          return right.shortage - left.shortage;
        }
        return left.product.ref.localeCompare(right.product.ref, 'pt-PT', { numeric: true });
      });
  }, [selectedStockForecastDate, stockForecastData?.products]);
  const stockForecastDayAtRisk = useMemo(
    () => stockForecastDayProducts.filter((row) => row.shortage > 0.00001).length,
    [stockForecastDayProducts]
  );
  const workforceResources = useMemo<WorkforceResource[]>(() => workforceData?.resources || [], [workforceData?.resources]);
  const workforceEvents = useMemo<WorkforceEvent[]>(() => workforceData?.events || [], [workforceData?.events]);
  const workforcePunchRows = useMemo<WorkforcePunchRow[]>(() => workforceData?.punch_rows || [], [workforceData?.punch_rows]);
  const workforceDayOffs = useMemo<WorkforceDayOff[]>(() => workforceData?.dayoffs || [], [workforceData?.dayoffs]);
  const workforceLeaves = useMemo<WorkforceLeave[]>(() => workforceData?.leaves || [], [workforceData?.leaves]);
  const workforcePublicHolidays = useMemo<WorkforcePublicHoliday[]>(
    () => workforceData?.public_holidays || [],
    [workforceData?.public_holidays]
  );

  const workforceWeekDays = useMemo(() => {
    const weekStart = workforceData?.period.week_start || workforceWeekStart;
    return Array.from({ length: 7 }, (_, index) => {
      const iso = addDays(weekStart, index);
      const parsed = parseInputDate(iso);
      const date = new Date(parsed.year, parsed.month - 1, parsed.day);
      return {
        iso,
        label: new Intl.DateTimeFormat('pt-PT', { weekday: 'short', day: '2-digit' }).format(date)
      };
    });
  }, [workforceData?.period.week_start, workforceWeekStart]);

  const workforcePublicHolidayByDate = useMemo<Record<string, WorkforcePublicHoliday[]>>(() => {
    const byDate: Record<string, WorkforcePublicHoliday[]> = {};
    workforcePublicHolidays.forEach((holiday) => {
      const iso = (holiday.date || '').trim();
      if (!iso) {
        return;
      }
      if (!byDate[iso]) {
        byDate[iso] = [];
      }
      byDate[iso].push(holiday);
    });
    return byDate;
  }, [workforcePublicHolidays]);

  const workforceDayOffByResourceDate = useMemo<Record<number, Record<string, WorkforceDayOff[]>>>(() => {
    const byResource: Record<number, Record<string, WorkforceDayOff[]>> = {};
    workforceDayOffs.forEach((row) => {
      const resourceId = Number(row.resource_id || 0);
      const dateIso = (row.date_iso || '').trim();
      if (!Number.isFinite(resourceId) || resourceId <= 0 || dateIso === '') {
        return;
      }
      if (!byResource[resourceId]) {
        byResource[resourceId] = {};
      }
      if (!byResource[resourceId][dateIso]) {
        byResource[resourceId][dateIso] = [];
      }
      byResource[resourceId][dateIso].push(row);
    });
    return byResource;
  }, [workforceDayOffs]);

  const workforceLeavesByResourceDate = useMemo<Record<number, Record<string, WorkforceLeave[]>>>(() => {
    const byResource: Record<number, Record<string, WorkforceLeave[]>> = {};
    const weekStartIso = workforceWeekDays[0]?.iso || '';
    const weekEndIso = workforceWeekDays[workforceWeekDays.length - 1]?.iso || '';

    workforceLeaves.forEach((row) => {
      const resourceId = Number(row.resource_id || 0);
      let startIso = (row.start_iso || '').trim();
      let endIso = (row.end_iso || '').trim();
      if (!Number.isFinite(resourceId) || resourceId <= 0 || startIso === '' || endIso === '') {
        return;
      }
      if (endIso < startIso) {
        const swap = startIso;
        startIso = endIso;
        endIso = swap;
      }

      let cursor = startIso;
      let guard = 0;
      while (cursor <= endIso && guard < 400) {
        if (weekStartIso === '' || weekEndIso === '' || isIsoDateWithinRange(cursor, weekStartIso, weekEndIso)) {
          if (!byResource[resourceId]) {
            byResource[resourceId] = {};
          }
          if (!byResource[resourceId][cursor]) {
            byResource[resourceId][cursor] = [];
          }
          byResource[resourceId][cursor].push(row);
        }
        cursor = addDays(cursor, 1);
        guard += 1;
      }
    });

    return byResource;
  }, [workforceLeaves, workforceWeekDays]);

  const workforceWeekDetailsByResourceDate = useMemo(() => {
    const details: Record<number, Record<string, { totalMinutes: number; firstTs: number | null; lastTs: number | null; shiftCount: number }>> = {};

    workforceResources.forEach((resource) => {
      details[resource.id] = {};
      workforceWeekDays.forEach((day) => {
        details[resource.id][day.iso] = {
          totalMinutes: 0,
          firstTs: null,
          lastTs: null,
          shiftCount: 0
        };
      });
    });

    workforceEvents.forEach((event) => {
      const startDate = parseIsoDateTime(event.start);
      const endDate = parseIsoDateTime(event.end);
      if (!startDate || !endDate) {
        return;
      }
      if (endDate.getTime() <= startDate.getTime()) {
        return;
      }

      const resourceId = Number(event.resource_id || 0);
      if (!Number.isFinite(resourceId) || resourceId <= 0) {
        return;
      }

      if (!details[resourceId]) {
        details[resourceId] = {};
        workforceWeekDays.forEach((day) => {
          details[resourceId][day.iso] = {
            totalMinutes: 0,
            firstTs: null,
            lastTs: null,
            shiftCount: 0
          };
        });
      }

      workforceWeekDays.forEach((day) => {
        const dayStart = parseIsoDateTime(`${day.iso}T00:00:00`);
        if (!dayStart) {
          return;
        }
        const dayEnd = new Date(dayStart);
        dayEnd.setDate(dayEnd.getDate() + 1);

        const overlapStartTs = Math.max(startDate.getTime(), dayStart.getTime());
        const overlapEndTs = Math.min(endDate.getTime(), dayEnd.getTime());
        if (overlapEndTs <= overlapStartTs) {
          return;
        }

        const bucket = details[resourceId][day.iso];
        const minutes = (overlapEndTs - overlapStartTs) / 60000;
        bucket.totalMinutes += minutes;
        bucket.shiftCount += 1;
        bucket.firstTs = bucket.firstTs === null ? overlapStartTs : Math.min(bucket.firstTs, overlapStartTs);
        bucket.lastTs = bucket.lastTs === null ? overlapEndTs : Math.max(bucket.lastTs, overlapEndTs);
      });
    });

    return details;
  }, [workforceEvents, workforceResources, workforceWeekDays]);

  const workforceWeeklyHours = useMemo(() => {
    const byResource: Record<number, Record<string, number>> = {};
    const totalsByDay: Record<string, number> = {};
    const weekTotalsByResource: Record<number, number> = {};

    workforceWeekDays.forEach((day) => {
      totalsByDay[day.iso] = 0;
    });
    workforceResources.forEach((resource) => {
      byResource[resource.id] = {};
      workforceWeekDays.forEach((day) => {
        byResource[resource.id][day.iso] = 0;
      });
      weekTotalsByResource[resource.id] = 0;
    });

    workforceEvents.forEach((event) => {
      const startDate = parseIsoDateTime(event.start);
      const endDate = parseIsoDateTime(event.end);
      if (!startDate || !endDate) {
        return;
      }

      const resourceId = event.resource_id;
      if (!byResource[resourceId]) {
        byResource[resourceId] = {};
        workforceWeekDays.forEach((day) => {
          byResource[resourceId][day.iso] = 0;
        });
      }

      workforceWeekDays.forEach((day) => {
        const dayStart = parseIsoDateTime(`${day.iso}T00:00:00`);
        if (!dayStart) {
          return;
        }
        const dayEnd = new Date(dayStart);
        dayEnd.setDate(dayEnd.getDate() + 1);

        const overlapStart = Math.max(startDate.getTime(), dayStart.getTime());
        const overlapEnd = Math.min(endDate.getTime(), dayEnd.getTime());
        if (overlapEnd <= overlapStart) {
          return;
        }

        const overlapHours = (overlapEnd - overlapStart) / 3600000;
        byResource[resourceId][day.iso] = (byResource[resourceId][day.iso] || 0) + overlapHours;
        totalsByDay[day.iso] = (totalsByDay[day.iso] || 0) + overlapHours;
        weekTotalsByResource[resourceId] = (weekTotalsByResource[resourceId] || 0) + overlapHours;
      });
    });

    return { byResource, totalsByDay, weekTotalsByResource };
  }, [workforceEvents, workforceResources, workforceWeekDays]);

  const workforceDaySegmentsByResource = useMemo(() => {
    type DaySegment = {
      id: number;
      left: number;
      width: number;
      color: string;
      title: string;
      time: string;
      startTs: number;
      endTs: number;
    };
    const segmentsByResource: Record<number, DaySegment[]> = {};
    const period = workforceData?.period;
    if (!period) {
      return segmentsByResource;
    }

    const dayAnchor = parseIsoDateTime(`${period.day}T00:00:00`);
    if (!dayAnchor) {
      return segmentsByResource;
    }

    const dayStartMinutes = Number(period.day_start_minutes || 0);
    const dayEndMinutes = Number(period.day_end_minutes || 24 * 60);
    let spanMinutes = dayEndMinutes - dayStartMinutes;
    if (spanMinutes <= 0) {
      spanMinutes += 24 * 60;
    }
    if (spanMinutes <= 0) {
      spanMinutes = 24 * 60;
    }

    const dayStartTs = dayAnchor.getTime() + dayStartMinutes * 60000;
    const dayEndTs = dayStartTs + spanMinutes * 60000;
    const spanMs = spanMinutes * 60000;

    workforceResources.forEach((resource) => {
      segmentsByResource[resource.id] = [];
    });

    workforceEvents.forEach((event) => {
      const startDate = parseIsoDateTime(event.start);
      const endDate = parseIsoDateTime(event.end);
      if (!startDate || !endDate || endDate.getTime() <= startDate.getTime()) {
        return;
      }

      const overlapStart = Math.max(startDate.getTime(), dayStartTs);
      const overlapEnd = Math.min(endDate.getTime(), dayEndTs);
      if (overlapEnd <= overlapStart) {
        return;
      }

      const rawLeftPct = ((overlapStart - dayStartTs) / spanMs) * 100;
      const rawRightPct = ((overlapEnd - dayStartTs) / spanMs) * 100;
      const clampedLeft = Math.max(0, Math.min(100, rawLeftPct));
      const clampedRight = Math.max(clampedLeft, Math.min(100, rawRightPct));
      const clampedWidth = clampedRight - clampedLeft;
      if (clampedWidth <= 0) {
        return;
      }

      if (!segmentsByResource[event.resource_id]) {
        segmentsByResource[event.resource_id] = [];
      }

      const overlapStartLabel = new Intl.DateTimeFormat('pt-PT', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
      }).format(new Date(overlapStart));
      const overlapEndLabel = new Intl.DateTimeFormat('pt-PT', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
      }).format(new Date(overlapEnd));
      const timeLabel = `${overlapStartLabel} - ${overlapEndLabel}`;
      segmentsByResource[event.resource_id].push({
        id: event.id,
        left: clampedLeft,
        width: clampedWidth,
        color: event.color || 'var(--kf-primary)',
        title: event.title || event.task_label || 'Turno',
        time: timeLabel,
        startTs: overlapStart,
        endTs: overlapEnd
      });
    });

    Object.keys(segmentsByResource).forEach((resourceId) => {
      segmentsByResource[Number(resourceId)].sort((a, b) => a.left - b.left);
    });

    return segmentsByResource;
  }, [workforceData?.period, workforceEvents, workforceResources]);

  const workforceDayStatsByResource = useMemo(() => {
    const stats: Record<number, { totalMinutes: number; firstTs: number | null; lastTs: number | null; shiftCount: number }> = {};

    workforceResources.forEach((resource) => {
      const segments = workforceDaySegmentsByResource[resource.id] || [];
      if (!segments.length) {
        stats[resource.id] = {
          totalMinutes: 0,
          firstTs: null,
          lastTs: null,
          shiftCount: 0
        };
        return;
      }

      let totalMinutes = 0;
      let firstTs = Number.POSITIVE_INFINITY;
      let lastTs = 0;
      segments.forEach((segment) => {
        const durationMinutes = Math.max(0, (segment.endTs - segment.startTs) / 60000);
        totalMinutes += durationMinutes;
        if (segment.startTs < firstTs) {
          firstTs = segment.startTs;
        }
        if (segment.endTs > lastTs) {
          lastTs = segment.endTs;
        }
      });

      stats[resource.id] = {
        totalMinutes,
        firstTs: Number.isFinite(firstTs) ? firstTs : null,
        lastTs: lastTs > 0 ? lastTs : null,
        shiftCount: segments.length
      };
    });

    return stats;
  }, [workforceDaySegmentsByResource, workforceResources]);

  const selectedWorkforceDay = workforceData?.period.day || workforceDay;
  const selectedPunchDate = workforceData?.period.punch_date || workforcePunchDate;
  const selectedWorkforceWeekStart = workforceData?.period.week_start || workforceWeekStart;

  const workforceTimelineMarks = useMemo(() => {
    const period = workforceData?.period;
    if (!period) {
      return [];
    }
    const dayStartMinutes = Number(period.day_start_minutes || 0);
    const dayEndMinutes = Number(period.day_end_minutes || 24 * 60);
    let spanMinutes = dayEndMinutes - dayStartMinutes;
    if (spanMinutes <= 0) {
      spanMinutes += 24 * 60;
    }
    if (spanMinutes <= 0) {
      spanMinutes = 24 * 60;
    }

    const steps = [0, 25, 50, 75, 100];
    return steps.map((pct, index) => {
      const minutes = dayStartMinutes + (spanMinutes * pct) / 100;
      const normalized = ((Math.round(minutes) % (24 * 60)) + (24 * 60)) % (24 * 60);
      const hour = Math.floor(normalized / 60);
      const minute = normalized % 60;
      return {
        left: pct,
        label: `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`,
        align: index === 0 ? 'start' : index === steps.length - 1 ? 'end' : 'middle'
      };
    });
  }, [workforceData?.period]);

  const workforcePunchByEmployee = useMemo(() => {
    const grouped: Record<string, { key: string; empid: number; name: string; rows: WorkforcePunchRow[] }> = {};
    workforcePunchRows.forEach((row) => {
      const key = `${row.empid_zs}-${row.employee_name || ''}`;
      if (!grouped[key]) {
        grouped[key] = {
          key,
          empid: row.empid_zs,
          name: row.employee_name || `Emp #${row.empid_zs}`,
          rows: []
        };
      }
      grouped[key].rows.push(row);
    });

    return Object.values(grouped)
      .map((group) => {
        const sequenceStats = getPunchSequenceStats(group.rows);
        return {
          ...group,
          totalSeconds: sequenceStats.totalSeconds,
          pairedRowsCount: sequenceStats.pairedRowsCount
        };
      })
      .sort((a, b) => a.name.localeCompare(b.name, 'pt-PT'));
  }, [workforcePunchRows]);
  const workforceTotalScheduledHours = useMemo(
    () => Object.values(workforceWeeklyHours.weekTotalsByResource).reduce((sum, value) => sum + Number(value || 0), 0),
    [workforceWeeklyHours.weekTotalsByResource]
  );
  const workforceTotalExpectedHours = useMemo(
    () => workforceResources.reduce((sum, resource) => sum + Number(resource.expected_hours || 0), 0),
    [workforceResources]
  );
  const workforceTotalPunchSeconds = useMemo(
    () => workforcePunchByEmployee.reduce((sum, group) => sum + Number(group.totalSeconds || 0), 0),
    [workforcePunchByEmployee]
  );

  const fechoFormMetrics = useMemo(() => {
    if (!fechoForm) {
      return null;
    }
    return computeFechoMetricsFromValues(fechoForm.values);
  }, [fechoForm]);
  const fechoValidationState = useMemo(() => {
    if (!fechoFormMetrics) {
      return null;
    }
    return buildFechoValidationState(fechoFormMetrics);
  }, [fechoFormMetrics]);

  const fechoCanCreate = fechoMeta?.permissions.can_create === 1;
  const fechoCanUpdate = fechoMeta?.permissions.can_update === 1;
  const fechoCanEditCurrent =
    !!fechoForm &&
    ((fechoModalMode === 'create' && fechoCanCreate) ||
      (fechoModalMode === 'edit' && fechoCanUpdate && fechoForm.can_edit === 1));

  const activeDateBounds = resolveDateBounds(activeDateScope);
  const yearOptions = useMemo(() => {
    const currentYear = new Date().getFullYear();
    const minYear = activeDateBounds.min ? parseInputDate(activeDateBounds.min).year : currentYear - 20;
    const maxYear = activeDateBounds.max ? parseInputDate(activeDateBounds.max).year : currentYear + 1;
    const rangeMinYear = Math.min(minYear, maxYear);
    const rangeMaxYear = Math.max(minYear, maxYear);
    const years: string[] = [];
    for (let year = rangeMaxYear; year >= rangeMinYear; year -= 1) {
      years.push(String(year));
    }
    return years;
  }, [activeDateBounds.max, activeDateBounds.min]);

  const monthOptions = useMemo(() => {
    const selectedYear = Number(dateWheelValue.year);
    let minMonth = 1;
    let maxMonth = 12;

    if (activeDateBounds.min) {
      const parsedMin = parseInputDate(activeDateBounds.min);
      if (parsedMin.year === selectedYear) {
        minMonth = parsedMin.month;
      }
    }
    if (activeDateBounds.max) {
      const parsedMax = parseInputDate(activeDateBounds.max);
      if (parsedMax.year === selectedYear) {
        maxMonth = parsedMax.month;
      }
    }
    if (minMonth > maxMonth) {
      minMonth = maxMonth;
    }

    return MONTH_LABELS.map((label, index) => ({ value: String(index + 1), label })).filter((monthEntry) => {
      const monthValue = Number(monthEntry.value);
      return monthValue >= minMonth && monthValue <= maxMonth;
    });
  }, [activeDateBounds.max, activeDateBounds.min, dateWheelValue.year]);

  const dayOptions = useMemo(() => {
    const year = Number(dateWheelValue.year);
    const month = Number(dateWheelValue.month);
    const totalDays = getDaysInMonth(year, month);
    let minDay = 1;
    let maxDay = totalDays;

    if (activeDateBounds.min) {
      const parsedMin = parseInputDate(activeDateBounds.min);
      if (parsedMin.year === year && parsedMin.month === month) {
        minDay = parsedMin.day;
      }
    }
    if (activeDateBounds.max) {
      const parsedMax = parseInputDate(activeDateBounds.max);
      if (parsedMax.year === year && parsedMax.month === month) {
        maxDay = Math.min(maxDay, parsedMax.day);
      }
    }
    if (minDay > maxDay) {
      minDay = maxDay;
    }

    return Array.from({ length: maxDay - minDay + 1 }, (_, index) => String(minDay + index));
  }, [activeDateBounds.max, activeDateBounds.min, dateWheelValue.month, dateWheelValue.year]);
  const currentMonthRange = getRangeCurrentMonth();
  const dashboardMonthPresetDays = getInclusiveDayCount(currentMonthRange.start, currentMonthRange.end);
  const isDashboardMonthPresetDisabled = historyLimitDays !== null && historyLimitDays < dashboardMonthPresetDays;
  const previousMonthRange = getRangePreviousMonth();
  const dashboardLastMonthPresetDays = getInclusiveDayCount(previousMonthRange.start, previousMonthRange.end);
  const isDashboardLastMonthPresetDisabled = historyLimitDays !== null && historyLimitDays < dashboardLastMonthPresetDays;
  const dashboardSingleDayValue = dateStart === dateEnd && isInputDateYmd(dateStart) ? dateStart : null;
  const dashboardTopProductsTotalRaw = Number(dashboard?.top_products_total || 0);
  const dashboardTopProductsTotal =
    Number.isFinite(dashboardTopProductsTotalRaw) && dashboardTopProductsTotalRaw > 0 ? Math.trunc(dashboardTopProductsTotalRaw) : 0;
  const dashboardTopProductsPagesRaw = Number(dashboard?.top_products_pages || 1);
  const dashboardTopProductsPages =
    Number.isFinite(dashboardTopProductsPagesRaw) && dashboardTopProductsPagesRaw > 0 ? Math.trunc(dashboardTopProductsPagesRaw) : 1;
  const dashboardTopProductsPageRaw = Number(dashboard?.top_products_page || dashboardTopProductsPage);
  const dashboardTopProductsCurrentPage =
    Number.isFinite(dashboardTopProductsPageRaw) && dashboardTopProductsPageRaw > 0 ? Math.trunc(dashboardTopProductsPageRaw) : 1;
  const dashboardTopProductsPageSizeRaw = Number(dashboard?.top_products_page_size || 12);
  const dashboardTopProductsPageSize =
    Number.isFinite(dashboardTopProductsPageSizeRaw) && dashboardTopProductsPageSizeRaw > 0 ? Math.trunc(dashboardTopProductsPageSizeRaw) : 12;
  const dashboardTopProductsRowsCount = dashboard?.top_products.length || 0;
  const dashboardTopProductsFirstRowNumber =
    dashboardTopProductsTotal > 0 ? (dashboardTopProductsCurrentPage - 1) * dashboardTopProductsPageSize + 1 : 0;
  const dashboardTopProductsLastRowNumber =
    dashboardTopProductsTotal > 0
      ? Math.min(dashboardTopProductsTotal, dashboardTopProductsFirstRowNumber + dashboardTopProductsRowsCount - 1)
      : 0;
  const canNavigateTopProductsPrev = dashboardTopProductsCurrentPage > 1;
  const canNavigateTopProductsNext = dashboardTopProductsCurrentPage < dashboardTopProductsPages;
  const dashboardDateBounds = resolveDateBounds('dashboard');
  const canNavigateDashboardPrev =
    dashboardSingleDayValue !== null && (!dashboardDateBounds.min || dashboardSingleDayValue > dashboardDateBounds.min);
  const canNavigateDashboardNext =
    dashboardSingleDayValue !== null && (!dashboardDateBounds.max || dashboardSingleDayValue < dashboardDateBounds.max);

  const openDatePicker = (scope: DateScope, field: DateField) => {
    if (scope === 'fechoModal' && !fechoForm) {
      return;
    }

    setActiveDateScope(scope);
    setActiveDateField(field);

    let sourceDate = dateStart;
    if (scope === 'eats') {
      sourceDate = eatsDate;
    } else if (scope === 'dashboard') {
      sourceDate = field === 'end' ? dateEnd : dateStart;
    } else if (scope === 'fecho') {
      sourceDate = field === 'end' ? fechoDateEnd : fechoDateStart;
    } else if (scope === 'equipaWeek') {
      sourceDate = workforceWeekStart;
    } else if (scope === 'equipaDay') {
      sourceDate = workforceDay;
    } else if (scope === 'equipaPunch') {
      sourceDate = workforcePunchDate;
    } else if (scope === 'fechoModal') {
      sourceDate = fechoForm ? fechoForm.datecx : toInputDate(new Date());
    }

    if (scope === 'equipaWeek') {
      sourceDate = getWeekStartDate(sourceDate);
    }

    setDateWheelValue(clampDateWheelToScope(toWheelDate(sourceDate), scope));
    setIsDatePickerOpen(true);
  };

  const closeDatePicker = () => {
    setIsDatePickerOpen(false);
  };

  const applyDatePicker = () => {
    const selectedDate = clampDateForScope(toIsoDate(dateWheelValue), activeDateScope);

    if (activeDateScope === 'eats') {
      setEatsDate(getWeekStartDate(selectedDate));
    } else if (activeDateScope === 'dashboard') {
      const nextRange = clampDashboardRangeWithPermissions(
        activeDateField === 'start' ? selectedDate : dateStart,
        activeDateField === 'end' ? selectedDate : dateEnd
      );
      setDateStart(nextRange.start);
      setDateEnd(nextRange.end);
    } else if (activeDateScope === 'fecho') {
      const nextRange = clampFechoRangeWithPermissions(
        activeDateField === 'start' ? selectedDate : fechoDateStart,
        activeDateField === 'end' ? selectedDate : fechoDateEnd
      );
      setFechoDateStart(nextRange.start);
      setFechoDateEnd(nextRange.end);
    } else if (activeDateScope === 'equipaWeek') {
      setWorkforceWeekStart(clampDateForScope(getWeekStartDate(selectedDate), 'equipaWeek'));
    } else if (activeDateScope === 'equipaDay') {
      setWorkforceDay(clampDateForScope(selectedDate, 'equipaDay'));
    } else if (activeDateScope === 'equipaPunch') {
      setWorkforcePunchDate(clampDateForScope(selectedDate, 'equipaPunch'));
    } else if (activeDateScope === 'fechoModal') {
      setFechoDate(selectedDate);
    }

    setIsDatePickerOpen(false);
  };

  const handleDateWheelChange = (nextValue: DateWheelValue, _key: string) => {
    setDateWheelValue(clampDateWheelToScope(nextValue, activeDateScope));
  };

  const handlePreset = async (preset: 'yesterday' | 'today' | 'last7' | 'month' | 'lastMonth') => {
    if (
      (preset === 'month' && isDashboardMonthPresetDisabled) ||
      (preset === 'lastMonth' && isDashboardLastMonthPresetDisabled)
    ) {
      return;
    }

    let range = getYesterdayRange();

    if (preset === 'today') {
      range = getTodayRange();
    } else if (preset === 'last7') {
      range = getRangeLastDays(7);
    } else if (preset === 'month') {
      range = getRangeCurrentMonth();
    } else if (preset === 'lastMonth') {
      range = getRangePreviousMonth();
    }

    const safeRange = clampDashboardRangeWithPermissions(range.start, range.end);
    setDateStart(safeRange.start);
    setDateEnd(safeRange.end);
    setDashboardTopProductsPage(1);
    await loadDashboard(safeRange.start, safeRange.end, 1);
  };

  const applyDashboardFilter = async () => {
    setDashboardTopProductsPage(1);
    await loadDashboard(dateStart, dateEnd, 1);
  };

  const navigateDashboardDay = async (offset: -1 | 1) => {
    if (!dashboardSingleDayValue) {
      return;
    }

    const nextDay = addDays(dashboardSingleDayValue, offset);
    const safeRange = clampDashboardRangeWithPermissions(nextDay, nextDay);
    if (safeRange.start === dateStart && safeRange.end === dateEnd) {
      return;
    }

    setDateStart(safeRange.start);
    setDateEnd(safeRange.end);
    setDashboardTopProductsPage(1);
    await loadDashboard(safeRange.start, safeRange.end, 1);
  };

  const navigateTopProductsPage = async (offset: -1 | 1) => {
    if (!dashboard) {
      return;
    }

    const nextPage = dashboardTopProductsCurrentPage + offset;
    if (nextPage < 1 || nextPage > dashboardTopProductsPages) {
      return;
    }

    await loadDashboard(dateStart, dateEnd, nextPage);
  };

  const applyWorkforceWeekFilter = async () => {
    await loadWorkforce(workforceWeekStart, workforceDay, workforcePunchDate);
  };

  const applyWorkforceDayBarsFilter = async () => {
    const resolvedWeekStart = getWeekStartDate(workforceDay);
    setWorkforceWeekStart(resolvedWeekStart);
    await loadWorkforce(resolvedWeekStart, workforceDay, workforcePunchDate);
  };

  const navigateWorkforceWeek = async (offset: -1 | 1) => {
    const stepDays = offset * 7;
    const nextWeekStart = addDays(selectedWorkforceWeekStart, stepDays);
    const nextDay = addDays(selectedWorkforceDay, stepDays);
    setWorkforceWeekStart(nextWeekStart);
    setWorkforceDay(nextDay);
    await loadWorkforce(nextWeekStart, nextDay, workforcePunchDate);
  };

  const navigateWorkforceDay = async (offset: -1 | 1) => {
    const nextDay = addDays(selectedWorkforceDay, offset);
    const nextWeekStart = getWeekStartDate(nextDay);
    setWorkforceWeekStart(nextWeekStart);
    setWorkforceDay(nextDay);
    await loadWorkforce(nextWeekStart, nextDay, workforcePunchDate);
  };

  const applyPunchClockFilter = async () => {
    await loadWorkforce(workforceWeekStart, workforceDay, workforcePunchDate);
  };

  const navigatePunchClockDay = async (offset: -1 | 1) => {
    const nextPunchDate = addDays(selectedPunchDate, offset);
    setWorkforcePunchDate(nextPunchDate);
    await loadWorkforce(selectedWorkforceWeekStart, selectedWorkforceDay, nextPunchDate);
  };

  const refreshPunchClockToday = async () => {
    const today = toInputDate(new Date());
    setWorkforcePunchDate(today);
    await loadWorkforce(workforceWeekStart, workforceDay, today);
  };

  const applyFechoFilter = async () => {
    await loadFechoFinanceiro(fechoDateStart, fechoDateEnd, fechoStatus, fechoSearch);
  };

  const updateTabInUrl = (nextTab: TabKey) => {
    const nextUrl = new URL(window.location.href);
    nextUrl.searchParams.set('tab', nextTab);
    window.history.replaceState(null, '', nextUrl.toString());
  };

  const selectTab = (nextTab: TabKey) => {
    if (!canAccessTab(session, nextTab)) {
      return;
    }
    setTab(nextTab);
    setIsNavOpen(false);
    updateTabInUrl(nextTab);
  };

  const ensureFechoMeta = async (): Promise<FechoFinanceiroMeta | null> => {
    if (fechoMeta) {
      return fechoMeta;
    }
    try {
      const meta = await getFechoMeta();
      setFechoMeta(meta);
      setFechoMetaError(null);
      return meta;
    } catch (error) {
      if (isUnauthorizedError(error)) {
        handleUnauthorizedSession();
        return null;
      }
      setFechoMetaError(getErrorMessage(error, 'Falha ao carregar dados de apoio do fecho.'));
      return null;
    }
  };

  const closeFechoModal = () => {
    setIsFechoModalOpen(false);
    setFechoModalError(null);
    setFechoModalLoading(false);
    setFechoSaving(false);
    setFechoForm(null);
  };

  const openFechoCreateModal = async () => {
    if (!fechoCanCreate) {
      return;
    }

    setIsFechoModalOpen(true);
    setFechoModalMode('create');
    setFechoModalError(null);
    setFechoModalLoading(true);

    const meta = await ensureFechoMeta();
    if (!meta) {
      setFechoModalLoading(false);
      return;
    }

    setFechoForm({
      id: 0,
      ref: 'Novo',
      status: 0,
      status_label: 'Rascunho',
      datecx: meta.defaults.datecx || toInputDate(new Date()),
      fk_loja: meta.defaults.fk_loja || (session?.entity || 1),
      store_label:
        meta.stores.find((store) => store.id === (meta.defaults.fk_loja || (session?.entity || 1)))?.label || session?.entity_label || '',
      responsavel: meta.defaults.responsavel || (session?.user.id || 0),
      responsavel_label:
        meta.users.find((usr) => usr.id === (meta.defaults.responsavel || (session?.user.id || 0)))?.label ||
        session?.user.fullname ||
        session?.user.login ||
        '',
      description: '',
      can_edit: 1,
      values: makeFechoFormValues()
    });
    setFechoModalLoading(false);
  };

  const openFechoDetailModal = async (mode: 'view' | 'edit', fechoId: number) => {
    if (fechoId <= 0) {
      return;
    }
    if (mode === 'edit' && !fechoCanUpdate) {
      return;
    }

    setIsFechoModalOpen(true);
    setFechoModalMode(mode);
    setFechoModalError(null);
    setFechoModalLoading(true);

    await ensureFechoMeta();

    try {
      const detail = await getFechoDetail(fechoId);
      setFechoForm(buildFechoFormFromDetail(detail));
      if (mode === 'edit' && detail.can_edit !== 1) {
        setFechoModalError('Sem permissão para editar este fecho financeiro.');
      }
    } catch (error) {
      if (isUnauthorizedError(error)) {
        setIsFechoModalOpen(false);
        handleUnauthorizedSession();
        return;
      }
      setFechoModalError(getErrorMessage(error, 'Falha ao carregar o fecho financeiro.'));
    } finally {
      setFechoModalLoading(false);
    }
  };

  const setFechoValue = (key: FechoValueKey, value: string) => {
    setFechoForm((prev) => {
      if (!prev) {
        return prev;
      }
      return {
        ...prev,
        values: {
          ...prev.values,
          [key]: value
        }
      };
    });
  };

  const saveFechoForm = async () => {
    if (!fechoForm || !fechoFormMetrics || !fechoCanEditCurrent) {
      return;
    }
    if (!fechoForm.datecx || fechoForm.fk_loja <= 0 || fechoForm.responsavel <= 0) {
      setFechoModalError('Preencha data, loja e responsável.');
      return;
    }
    if (!fechoValidationState || !fechoValidationState.canSave) {
      const reasons: string[] = [];
      if (!fechoValidationState?.salesValid) {
        reasons.push(`Vendas (dif. ${formatCurrency(fechoValidationState?.salesDifference || 0)})`);
      }
      if (!fechoValidationState?.treasuryValid) {
        reasons.push(`Tesouraria (dif. ${formatCurrency(fechoValidationState?.treasuryDifference || 0)})`);
      }
      if (!fechoValidationState?.vatValid) {
        reasons.push(`Total C/IVA (dif. ${formatCurrency(fechoValidationState?.vatDifference || 0)})`);
      }
      setFechoModalError(
        `Validações financeiras pendentes${reasons.length ? `: ${reasons.join(' • ')}` : '.'}`
      );
      return;
    }

    setFechoSaving(true);
    setFechoModalError(null);
    const payload: FechoFinanceiroSavePayload = buildFechoSavePayload(fechoForm, fechoFormMetrics);

    try {
      const saved =
        fechoModalMode === 'create' ? await createFecho(payload) : await updateFecho(fechoForm.id, payload);
      setFechoForm(buildFechoFormFromDetail(saved));
      setFechoModalMode('view');
      await loadFechoFinanceiro(fechoDateStart, fechoDateEnd, fechoStatus, fechoSearch);
    } catch (error) {
      if (isUnauthorizedError(error)) {
        closeFechoModal();
        handleUnauthorizedSession();
        return;
      }
      setFechoModalError(getErrorMessage(error, 'Falha ao gravar o fecho financeiro.'));
    } finally {
      setFechoSaving(false);
    }
  };

  const setFechoDate = (value: string) => {
    setFechoForm((prev) => {
      if (!prev) {
        return prev;
      }
      return {
        ...prev,
        datecx: value
      };
    });
  };

  const setFechoStore = (value: string) => {
    const storeId = Number.parseInt(value, 10) || 0;
    setFechoForm((prev) => {
      if (!prev) {
        return prev;
      }
      return {
        ...prev,
        fk_loja: storeId,
        store_label: fechoStores.find((store) => store.id === storeId)?.label || prev.store_label
      };
    });
  };

  const setFechoResponsavel = (value: string) => {
    const responsavelId = Number.parseInt(value, 10) || 0;
    setFechoForm((prev) => {
      if (!prev) {
        return prev;
      }
      return {
        ...prev,
        responsavel: responsavelId,
        responsavel_label: fechoUsers.find((usr) => usr.id === responsavelId)?.label || prev.responsavel_label
      };
    });
  };

  const setFechoDescription = (value: string) => {
    setFechoForm((prev) => {
      if (!prev) {
        return prev;
      }
      return {
        ...prev,
        description: value
      };
    });
  };

  const normalizeFechoValue = (key: FechoValueKey) => {
    setFechoForm((prev) => {
      if (!prev) {
        return prev;
      }
      return {
        ...prev,
        values: {
          ...prev.values,
          [key]: formatDecimalInput(parseDecimalInput(prev.values[key]))
        }
      };
    });
  };

  const fechoStores = fechoMeta?.stores || [];
  const fechoUsers = fechoMeta?.users || [];
  const fechoCanChangeStore = fechoMeta?.permissions.can_change_store === 1;
  const fechoInputLocked = !fechoCanEditCurrent || fechoModalLoading || fechoSaving;

  const renderFechoField = (field: FechoFieldDef) => {
    if (!fechoForm) {
      return null;
    }

    const isReadOnly = field.readOnly === true;
    const locked = isReadOnly || fechoInputLocked;
    const value =
      field.key === 'total_sem_iva' && fechoFormMetrics
        ? formatDecimalInput(fechoFormMetrics.total_sem_iva_calc)
        : fechoForm.values[field.key];

    return (
      <label key={field.key} className="fecho-field">
        <span>{field.label}</span>
        <input
          type="text"
          inputMode="decimal"
          autoComplete="off"
          value={value}
          onChange={(event) => setFechoValue(field.key, event.target.value)}
          onBlur={() => normalizeFechoValue(field.key)}
          readOnly={isReadOnly}
          disabled={locked}
        />
      </label>
    );
  };

  const runProductSearch = async (query = productQuery) => {
    const normalizedQuery = query.trim();
    if (normalizedQuery === '') {
      setHasProductSearchRun(false);
      setProducts([]);
      setProductsError(null);
      return;
    }

    setHasProductSearchRun(true);
    setProductsLoading(true);
    setProductsError(null);

    try {
      const result = await searchProducts(normalizedQuery, 80, 0);
      setProducts(result);
    } catch (error) {
      if (isUnauthorizedError(error)) {
        handleUnauthorizedSession();
        return;
      }
      setProductsError(getErrorMessage(error, 'Falha ao pesquisar produtos.'));
    } finally {
      setProductsLoading(false);
    }
  };

  const openProductDetail = async (productId: number) => {
    setIsProductDetailOpen(true);
    setProductDetailLoading(true);
    setProductDetailError(null);
    setProductDetail(null);

    try {
      const detail = await getProductDetail(productId);
      setProductDetail(detail);
    } catch (error) {
      if (isUnauthorizedError(error)) {
        setIsProductDetailOpen(false);
        setProductDetail(null);
        handleUnauthorizedSession();
        return;
      }
      setProductDetailError(getErrorMessage(error, 'Falha ao carregar detalhes do produto.'));
    } finally {
      setProductDetailLoading(false);
    }
  };

  const closeProductDetail = () => {
    setIsProductDetailOpen(false);
    setProductDetail(null);
    setProductDetailError(null);
    setProductDetailLoading(false);
  };

  const handleLogout = async () => {
    setLogoutLoading(true);
    try {
      await logoutSession();
    } catch (_error) {
      // Ignore backend logout transport errors and force local app logout.
    } finally {
      setLogoutLoading(false);
    }

    clearDashboardCache();
    clearAuthenticatedData();
    setSession(null);
    setTab('dashboard');
    updateTabInUrl('dashboard');
    setLoginPassword('');
    setLoginTotpDigits(createEmptyTotpDigits());
    setLoginError(null);
    await loadLoginMetadata();
  };

  const focusLoginTotpDigit = (index: number) => {
    const safeIndex = Math.max(0, Math.min(TOTP_CODE_LENGTH - 1, index));
    const input = document.getElementById(`login-totp-${safeIndex}`);
    if (input instanceof HTMLInputElement) {
      input.focus();
      input.select();
    }
  };

  const fillLoginTotpDigits = (startIndex: number, value: string) => {
    const clean = normalizeTotpCode(value);
    setLoginTotpDigits((current) => {
      const next = [...current];
      if (clean === '') {
        next[startIndex] = '';
        return next;
      }

      for (let offset = 0; offset < clean.length && startIndex + offset < TOTP_CODE_LENGTH; offset += 1) {
        next[startIndex + offset] = clean.charAt(offset);
      }

      return next;
    });

    if (clean !== '') {
      window.setTimeout(() => focusLoginTotpDigit(startIndex + clean.length), 0);
    }
  };

  const handleLoginTotpDigitChange = (index: number, event: ChangeEvent<HTMLInputElement>) => {
    fillLoginTotpDigits(index, event.target.value);
  };

  const handleLoginTotpDigitKeyDown = (index: number, event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Backspace' && loginTotpDigits[index] === '' && index > 0) {
      window.setTimeout(() => focusLoginTotpDigit(index - 1), 0);
    }
  };

  const handleLoginTotpPaste = (index: number, event: ClipboardEvent<HTMLInputElement>) => {
    event.preventDefault();
    fillLoginTotpDigits(index, event.clipboardData.getData('text'));
  };

  const handleLoginSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    const username = loginUsername.trim();
    const password = loginPassword;
    const entity = loginEntity > 0 ? loginEntity : 1;
    const totpCode = normalizeTotpCode(loginTotpDigits.join(''));

    if (username === '') {
      setLoginError('Introduza o utilizador.');
      return;
    }
    if (password === '') {
      setLoginError('Introduza a palavra-passe.');
      return;
    }

    setLoginLoading(true);
    setLoginError(null);
    setLoadError(null);

    try {
      const sessionData = await loginWithCredentials(username, password, entity, totpCode);
      setSession(sessionData);
      clearAuthenticatedData();
      const fallbackTab = getAccessibleTabs(sessionData)[0]?.key || 'dashboard';
      setTab(fallbackTab);
      updateTabInUrl(fallbackTab);
      setLoginPassword('');
      setLoginTotpDigits(createEmptyTotpDigits());
      if (canAccessTab(sessionData, 'dashboard')) {
        void loadDashboard(defaultRange.start, defaultRange.end, 1, sessionData);
      }
    } catch (error) {
      setLoginError(getErrorMessage(error, 'Falha ao autenticar.'));
    } finally {
      setLoginLoading(false);
    }
  };

  const handleGoogleLoginSubmit = () => {
    const googleUrl = selectedKrea2FactorAuthEntity?.google_login_url || '';
    if (!googleUrl) {
      setLoginError('Google login is not available for the selected entity.');
      return;
    }

    const appendHidden = (form: HTMLFormElement, name: string, value: string) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    };

    const form = document.createElement('form');
    form.method = 'post';
    form.action = googleUrl;
    form.style.display = 'none';

    appendHidden(form, 'token', loginMeta?.krea2factorauth?.google_login_token || '');
    appendHidden(form, 'actionlogin', 'login');
    appendHidden(form, 'beforeoauthloginredirect', 'google');
    appendHidden(form, 'entity', String(loginEntity > 0 ? loginEntity : 1));
    appendHidden(form, 'username', loginUsername.trim());
    appendHidden(form, 'backtopage', MODULE_ENTRY_URL);
    appendHidden(form, 'screenwidth', String(window.screen?.width || window.innerWidth || ''));
    appendHidden(form, 'screenheight', String(window.screen?.height || window.innerHeight || ''));
    appendHidden(form, 'tz_string', Intl.DateTimeFormat().resolvedOptions().timeZone || '');

    document.body.appendChild(form);
    form.submit();
  };

  const loginEntityOptions = loginMeta?.entities || [];
  const selectedLoginEntity = loginEntityOptions.find((entity) => entity.id === loginEntity) || null;
  const selectedKrea2FactorAuthEntity = selectedLoginEntity?.krea2factorauth;
  const krea2FactorLabels = loginMeta?.krea2factorauth?.labels;
  const isKrea2FactorAuthLoginActive = selectedKrea2FactorAuthEntity?.enabled === 1;
  const isGoogleLoginActive =
    selectedKrea2FactorAuthEntity?.google_login_enabled === 1 && !!selectedKrea2FactorAuthEntity.google_login_url;
  const canSwitchEntity = !!session && session.can_switch_entity === 1 && session.available_entities.length > 1;

  const openEntityPicker = () => {
    if (!canSwitchEntity) {
      return;
    }
    setEntitySwitchError(null);
    setIsEntityPickerOpen(true);
  };

  const closeEntityPicker = () => {
    setEntitySwitchError(null);
    setIsEntityPickerOpen(false);
  };

  const handleEntitySwitch = async (entityId: number) => {
    if (!session || entitySwitchLoading !== null || entityId === session.entity) {
      return;
    }

    setEntitySwitchLoading(entityId);
    setEntitySwitchError(null);
    const tokenQuery = session.token ? `&token=${encodeURIComponent(session.token)}` : '';
    const switchUrl = `${MODULE_ENTRY_URL}?kf_switch_entity=${encodeURIComponent(String(entityId))}${tokenQuery}`;
    window.location.replace(switchUrl);
  };

  const handleInstallApp = async () => {
    if (!installPromptEvent) {
      return;
    }

    try {
      await installPromptEvent.prompt();
      const choice = await installPromptEvent.userChoice;
      setInstallMessage(choice.outcome === 'accepted' ? 'Instalação iniciada.' : 'Instalação cancelada.');
    } catch (_error) {
      setInstallMessage('Não foi possível abrir o pedido de instalação.');
    } finally {
      setInstallPromptEvent(null);
    }
  };

  const dashboardPresetRanges = {
    yesterday: getYesterdayRange(), today: getTodayRange(), last7: getRangeLastDays(7),
    month: getRangeCurrentMonth(), lastMonth: getRangePreviousMonth()
  };
  const selectedDashboardPreset = Object.entries(dashboardPresetRanges).find(([, range]) => range.start === dateStart && range.end === dateEnd)?.[0];

  const canPromptInstall = !isRunningStandalone() && installPromptEvent !== null;
  const showIosInstallHint = !canPromptInstall && isIosInstallHintEligible();
  const showMacInstallHint = !canPromptInstall && !showIosInstallHint && !isRunningStandalone() && /Macintosh/.test(navigator.userAgent) && /Safari/.test(navigator.userAgent) && !/Chrome|Chromium|Edg/.test(navigator.userAgent);
  const installControls = <>
    {canPromptInstall && <button type="button" className="topbar-install" onClick={() => void handleInstallApp()}>Instalar KreaFlow</button>}
    {showIosInstallHint && <p className="status-message install-hint">No Safari: Partilhar {'>'} Adicionar ao ecrã principal.</p>}
    {showMacInstallHint && <p className="status-message install-hint">No Safari: Partilhar {'>'} Adicionar à Dock.</p>}
  </>;
  const entityBulletLabel = session ? getEntityRefLabel(session.entity, session.entity_label) : '';
  if (loading) {
    return (
      <main className="app-shell loading-screen">
        <p>A carregar KreaFlow Mobile...</p>
      </main>
    );
  }

  if (!session) {
    return (
      <main className="app-shell auth-screen">
        <div className="ambient" />
        <section className="card auth-card">
          <div className="auth-logo-wrap">
            <img src={AUTH_LOGO_URL} alt="KreaFlow" className="auth-logo" />
          </div>
          {installControls}
          {installMessage && <p className="status-message">{installMessage}</p>}
          <form className="auth-form" onSubmit={(event) => void handleLoginSubmit(event)}>
            <label>
              Utilizador
              <input
                type="text"
                value={loginUsername}
                onChange={(event) => setLoginUsername(event.target.value)}
                autoComplete="username"
                disabled={loginLoading}
                required
              />
            </label>
            <label>
              Palavra-passe
              <input
                type="password"
                value={loginPassword}
                onChange={(event) => setLoginPassword(event.target.value)}
                autoComplete="current-password"
                disabled={loginLoading}
                required
              />
            </label>
            {loginEntityOptions.length > 0 ? (
              <label>
                Entidade
                <select
                  value={loginEntity}
                  onChange={(event) => setLoginEntity(Number.parseInt(event.target.value, 10) || 1)}
                  disabled={loginLoading}
                >
                  {loginEntityOptions.map((entity) => (
                    <option key={entity.id} value={entity.id}>
                      {entity.label}
                    </option>
                  ))}
                </select>
              </label>
            ) : (
              <label>
                Entidade
                <input
                  type="number"
                  value={loginEntity}
                  min={1}
                  onChange={(event) => setLoginEntity(Number.parseInt(event.target.value, 10) || 1)}
                  disabled={loginLoading}
                />
              </label>
            )}
            {isKrea2FactorAuthLoginActive && (
              <label className="auth-totp-label">
                {krea2FactorLabels?.login_code || 'Authenticator code'}
                <div className="auth-totp-grid" role="group" aria-label="Two-factor authentication code">
                  {loginTotpDigits.map((digit, index) => (
                    <input
                      key={index}
                      id={`login-totp-${index}`}
                      className="auth-totp-digit"
                      type="text"
                      inputMode="numeric"
                      pattern="[0-9]*"
                      maxLength={1}
                      value={digit}
                      onChange={(event) => handleLoginTotpDigitChange(index, event)}
                      onKeyDown={(event) => handleLoginTotpDigitKeyDown(index, event)}
                      onPaste={(event) => handleLoginTotpPaste(index, event)}
                      autoComplete={index === 0 ? 'one-time-code' : 'off'}
                      aria-label={`2FA digit ${index + 1}`}
                      disabled={loginLoading}
                    />
                  ))}
                </div>
                <span className="auth-totp-hint">
                  {krea2FactorLabels?.login_hint || 'Enter the 6-digit code from your authenticator app.'}
                </span>
              </label>
            )}
            <button type="submit" className="btn auth-submit-btn" disabled={loginLoading}>
              {loginLoading ? 'A entrar...' : isKrea2FactorAuthLoginActive ? krea2FactorLabels?.login_button || 'Verify and sign in' : 'Entrar'}
            </button>
            {isGoogleLoginActive && (
              <div className="auth-google-block">
                <div className="auth-google-separator" role="separator">
                  <span>{krea2FactorLabels?.login_or || 'Or'}</span>
                </div>
                <button
                  type="button"
                  className="auth-google-btn"
                  onClick={handleGoogleLoginSubmit}
                  disabled={loginLoading}
                >
                  <span className="auth-google-icon" aria-hidden="true" />
                  {krea2FactorLabels?.login_with_google || 'Sign in with Google'}
                </button>
              </div>
            )}
          </form>
          {(loginError || loadError) && <p className="status-message auth-status-message">{loginError || loadError}</p>}
        </section>
      </main>
    );
  }

  return (
    <main className={`app-shell kf-workspace${sidebarCollapsed ? ' kf-sidebar-collapsed' : ''}`}>
      <div className="ambient" />

      <header className="topbar reveal">
        <div className="topbar-left">
          <button
            type="button"
            className="menu-toggle"
            id="kf-menu-toggle"
            aria-label="Alternar navegação"
            aria-controls={desktopNavigation ? 'kf-desktop-navigation' : 'kf-navigation'}
            aria-expanded={desktopNavigation ? !sidebarCollapsed : isNavOpen}
            onClick={() => desktopNavigation ? setSidebarCollapsed(value => !value) : setIsNavOpen(true)}
          >
            <span className="menu-toggle-bar" />
            <span className="menu-toggle-bar" />
            <span className="menu-toggle-bar" />
          </button>
          <p className="kicker">{availableTabs.find(item => item.key === tab)?.label || 'KreaFlow'}</p>
        </div>
        <div className="topbar-actions">
          <button
            type="button"
            className={canSwitchEntity ? 'entity-bullet entity-bullet-action' : 'entity-bullet'}
            title={session.entity_label}
            aria-label={`Entidade ${session.entity}`}
            onClick={openEntityPicker}
            disabled={!canSwitchEntity}
          >
            {entityBulletLabel}
          </button>
          {canPromptInstall && (
            <button type="button" className="topbar-install" onClick={() => void handleInstallApp()}>
              Instalar
            </button>
          )}
        </div>
      </header>

      <Navigation tabs={availableTabs} activeTab={tab} onSelect={selectTab}
        open={isNavOpen} onClose={() => setIsNavOpen(false)}
        collapsed={sidebarCollapsed} onCollapse={() => setSidebarCollapsed(value => !value)}
        onLogout={() => void handleLogout()} logoutLoading={logoutLoading} userName={session.user.fullname || session.user.login} />

      {installMessage && <p className="status-message">{installMessage}</p>}
      {showIosInstallHint && <p className="status-message install-hint">No Safari: Partilhar {'>'} Adicionar ao ecrã principal.</p>}
      {showMacInstallHint && <p className="status-message install-hint">No Safari: Partilhar {'>'} Adicionar à Dock.</p>}
      {availableTabs.length === 0 && (
        <section className="card reveal reveal-3">
          <h2>Sem permissões de menu</h2>
          <p className="muted">O seu utilizador não tem permissões KreaFlow para abrir menus da aplicação.</p>
        </section>
      )}

      {session && canAccessTab(session, 'kitchen') && tab === 'kitchen' && <KitchenPanel key={`${session.user.id}:${session.entity}`} entity={session.entity} />}
      {session && canAccessTab(session, 'eats') && tab === 'eats' && <EatsWeeklyPanel key={`${session.user.id}:${session.entity}`} date={eatsDate} onDateChange={setEatsDate} onPickDate={() => openDatePicker('eats', 'single')} />}

      {availableTabs.length > 0 && tab === 'dashboard' && (
        <section className="dashboard-space reveal reveal-3">
          <article className="card filter-card">
            <h2>Dashboard de Vendas</h2>
            <div className="filter-grid">
              <label>
                Início
                <button type="button" className="date-trigger" onClick={() => openDatePicker('dashboard', 'start')}>
                  {formatDateLabel(dateStart)}
                </button>
              </label>
              <label>
                Fim
                <button type="button" className="date-trigger" onClick={() => openDatePicker('dashboard', 'end')}>
                  {formatDateLabel(dateEnd)}
                </button>
              </label>
              <button type="button" className="btn" onClick={applyDashboardFilter} disabled={dashboardLoading}>
                {dashboardLoading ? 'A carregar...' : 'Aplicar'}
              </button>
            </div>
            <div className="preset-row">
              <button type="button" className="ghost-btn" aria-pressed={selectedDashboardPreset === 'yesterday'} onClick={() => void handlePreset('yesterday')} disabled={dashboardLoading}>
                Ont.
              </button>
              <button type="button" className="ghost-btn" aria-pressed={selectedDashboardPreset === 'today'} onClick={() => void handlePreset('today')} disabled={dashboardLoading}>
                Hoje
              </button>
              <button type="button" className="ghost-btn" aria-pressed={selectedDashboardPreset === 'last7'} onClick={() => void handlePreset('last7')} disabled={dashboardLoading}>
                7D
              </button>
              <button
                type="button"
                className="ghost-btn" aria-pressed={selectedDashboardPreset === 'month'}
                onClick={() => void handlePreset('month')}
                disabled={dashboardLoading || isDashboardMonthPresetDisabled}
              >
                Mês
              </button>
              <button
                type="button"
                className="ghost-btn" aria-pressed={selectedDashboardPreset === 'lastMonth'}
                onClick={() => void handlePreset('lastMonth')}
                disabled={dashboardLoading || isDashboardLastMonthPresetDisabled}
              >
                Mês Ant.
              </button>
            </div>
            {dashboardSingleDayValue && (
              <div className="workforce-filter-actions dashboard-day-nav-actions">
                <button
                  type="button"
                  className="ghost-btn"
                  onClick={() => void navigateDashboardDay(-1)}
                  disabled={dashboardLoading || !canNavigateDashboardPrev}
                >
                  Dia Anterior
                </button>
                <button
                  type="button"
                  className="ghost-btn"
                  onClick={() => void navigateDashboardDay(1)}
                  disabled={dashboardLoading || !canNavigateDashboardNext}
                >
                  Próximo Dia
                </button>
              </div>
            )}
          </article>

          {dashboardError && <p className="status-message">{dashboardError}</p>}

          {dashboard && (
            <>
              <section className="kpi-grid">
                <article className="card stat-card">
                  <span className="kf-stat-icon"><UiIcon name="revenue" /></span>
                  <h3>Total Vendas</h3>
                  <h2>{formatCurrency(dashboard.kpis.total_revenue_ttc)}</h2>
                  <p className="muted">C/IVA</p>
                  <p className="muted">S/IVA</p>
                  <h4>{formatCurrency(dashboard.kpis.total_revenue_ht)}</h4>
                  {dashboard.previous_year_sales && (
                    <details className="sales-history">
                      <summary><span className="kf-comparison-badge">{dashboard.previous_year_sales.status === 'available' ? formatSalesVariation(dashboard.kpis.total_revenue_ht, dashboard.previous_year_sales.revenue_ht ?? 0) : '—'}</span> Homólogo · S/IVA</summary>
                      <p className="muted">{dashboard.previous_year_sales.comparison_mode === 'operational' ? 'Homólogo operacional · mesmos dias da semana, 52 semanas antes' : 'Homólogo calendário · mesmas datas, ano anterior'}</p>
                      <p className="muted">{formatDateLabel(dashboard.previous_year_sales.date_start)} – {formatDateLabel(dashboard.previous_year_sales.date_end)}</p>
                      {dashboard.previous_year_sales.status === 'available' ? (
                        <>
                          <p>C/IVA <strong>{formatCurrency(dashboard.previous_year_sales.revenue_ttc ?? 0)}</strong></p>
                          <p>S/IVA <strong>{formatCurrency(dashboard.previous_year_sales.revenue_ht ?? 0)}</strong> <small title="Variação face ao ano anterior. Percentagem indisponível quando o total anterior é zero.">({formatSalesVariation(dashboard.kpis.total_revenue_ht, dashboard.previous_year_sales.revenue_ht ?? 0)})</small></p>
                        </>
                      ) : (
                        <p className="muted">{dashboard.previous_year_sales.status === 'restricted' ? 'Histórico fora do período permitido.' : 'Sem vendas registadas neste período.'}</p>
                      )}
                    </details>
                  )}
                </article>
                <article className="card stat-card">
                  <span className="kf-stat-icon"><UiIcon name="produtos" /></span>
                  <h3>Unidades Vendidas</h3>
                  <h2>{formatNumber(dashboard.kpis.total_qty_sold)}</h2>
                  <p className="muted">Pessoas: {formatNumber(dashboard.kpis.total_people || 0)}</p>
                  <p className="muted">Faturas: {formatNumber(dashboard.kpis.total_invoices || 0)}</p>
                </article>
                <article className="card stat-card">
                  <span className="kf-stat-icon"><UiIcon name="forecast" /></span>
                  <h3>Média por Dia</h3>
                  <h2>{formatCurrency(dashboard.kpis.avg_per_day_ttc)}</h2>
                  <p className="muted">C/IVA</p>
                  <p className="muted">S/IVA</p>
                  <h4>{formatCurrency(dashboard.kpis.avg_per_day_ht)}</h4>
                </article>
                <article className="card stat-card">
                  <span className="kf-stat-icon"><UiIcon name="people" /></span>
                  <h3>Ticket Médio / Pessoa</h3>
                  <h2>{formatCurrency(dashboard.kpis.avg_ticket_ttc)}</h2>
                  <p className="muted">C/IVA</p>
                  <p className="muted">S/IVA</p>
                  <h4>{formatCurrency(dashboard.kpis.avg_ticket_ht)}</h4>
                </article>
              </section>

              <section className="chart-grid">
                <article className="card chart-card main-chart">
                  <h3>Vendas Diárias (Total C/IVA)</h3>
                  <div className="chart-box">
                    <ResponsiveContainer width="100%" height="100%">
                      <LineChart data={dashboard.daily_sales}>
                        <CartesianGrid strokeDasharray="3 3" stroke="var(--kf-border)" />
                        <XAxis dataKey="sale_date" />
                        <YAxis tickFormatter={(value) => formatNumber(Number(value))} />
                        <Tooltip formatter={(value) => tooltipCurrency(value as number)} />
                        <Line isAnimationActive={false} type="linear" dataKey="revenue_ttc" stroke="var(--kf-chart-line)" strokeWidth={2.5} dot={false} />
                      </LineChart>
                    </ResponsiveContainer>
                  </div>
                </article>

                <article className="card chart-card side-chart">
                  <h3>Vendas por Hora</h3>
                  <div className="chart-box">
                    <ResponsiveContainer width="100%" height="100%">
                      <BarChart data={dashboard.hourly_sales} layout="vertical">
                        <CartesianGrid strokeDasharray="3 3" stroke="var(--kf-border)" />
                        <XAxis
                          type="number"
                          domain={[0, hourlyChartMax > 0 ? round2(hourlyChartMax * 1.1) : 1]}
                          tickFormatter={(value) => formatNumber(Number(value))}
                          allowDecimals={false}
                        />
                        <YAxis type="category" dataKey="hour_of_day" width={34} />
                        <Tooltip formatter={(value) => tooltipCurrency(value as number)} />
                        <Bar isAnimationActive={false} dataKey="revenue_ttc" fill="var(--kf-chart-bar)" radius={[0, 6, 6, 0]} maxBarSize={18} />
                      </BarChart>
                    </ResponsiveContainer>
                  </div>
                </article>
              </section>

              <section className="card">
                <h3>Vendas por Tipo de Pagamento</h3>
                <div className="payment-grid">
                  <div className="payment-chart">
                    {paymentChartRows.length > 0 ? (
                      <ResponsiveContainer width="100%" height="100%">
                        <PieChart>
                          <Pie isAnimationActive={false}
                            data={paymentChartRows}
                            dataKey="total_gross"
                            nameKey="paymenttype_name"
                            cx="50%"
                            cy="50%"
                            outerRadius={76}
                            innerRadius={34}
                            paddingAngle={1}
                            label={false}
                            labelLine={false}
                          >
                            {paymentChartRows.map((row, index) => (
                              <Cell key={`${row.paymenttype_name}-${index}`} fill={PIE_COLORS[index % PIE_COLORS.length]} />
                            ))}
                          </Pie>
                          <Tooltip formatter={(value) => tooltipCurrency(Number(value))} />
                        </PieChart>
                      </ResponsiveContainer>
                    ) : (
                      <div className="payment-chart-empty">Sem dados para gráfico.</div>
                    )}
                  </div>

                  <div className="table-wrap">
                    <table className="data-table compact-row-table payment-compact-table">
                      <colgroup>
                        <col style={{ width: '31%' }} />
                        <col style={{ width: '12%' }} />
                        <col style={{ width: '19%' }} />
                        <col style={{ width: '20%' }} />
                        <col style={{ width: '18%' }} />
                      </colgroup>
                      <thead>
                        <tr>
                          <th>Pgto</th>
                          <th className="right">Tx</th>
                          <th className="right">S/IVA</th>
                          <th className="right">C/IVA</th>
                          <th className="right">Part.</th>
                        </tr>
                      </thead>
                      <tbody>
                        {paymentRows.map((row, index) => {
                          const pct = paymentTotals.totalTtc > 0 ? (row.total_gross / paymentTotals.totalTtc) * 100 : 0;
                          const compactTypeLabel = compactPaymentTypeLabel(row.paymenttype_name);
                          return (
                            <tr key={`${row.paymenttype_name}-${index}`}>
                              <td data-label="Pgto" title={row.paymenttype_name}>
                                <span className="payment-type-label">
                                  <span className="dot" style={{ background: PIE_COLORS[index % PIE_COLORS.length] }} />
                                  <span className="payment-type-text">{compactTypeLabel}</span>
                                </span>
                              </td>
                              <td className="right" data-label="Tx">
                                {formatNumber(row.transaction_count)}
                              </td>
                              <td className="right" data-label="S/IVA">
                                {formatAmount(row.total_liquido)}
                              </td>
                              <td className="right" data-label="C/IVA">
                                {formatAmount(row.total_gross)}
                              </td>
                              <td className="right" data-label="Part.">
                                {formatShareCompact(pct)}
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                      <tfoot>
                        <tr>
                          <th data-label="Pgto">Total</th>
                          <th className="right" data-label="Tx">
                            {formatNumber(paymentTotals.transactions)}
                          </th>
                          <th className="right" data-label="S/IVA">
                            {formatAmount(paymentTotals.totalHt)}
                          </th>
                          <th className="right" data-label="C/IVA">
                            {formatAmount(paymentTotals.totalTtc)}
                          </th>
                          <th className="right" data-label="Part.">
                            {paymentTotals.totalTtc > 0 ? '100,0' : '0,0'}
                          </th>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                </div>
              </section>

              <section className="card">
                <h3>Top Produtos (por faturação)</h3>
                <div className="table-wrap">
                  <table className="data-table compact-row-table">
                    <colgroup>
                      <col style={{ width: '56%' }} />
                      <col style={{ width: '16%' }} />
                      <col style={{ width: '28%' }} />
                    </colgroup>
                    <thead>
                      <tr>
                        <th>Produto</th>
                        <th className="right">Qtd.</th>
                        <th className="right">Valor Total</th>
                      </tr>
                    </thead>
                    <tbody>
                      {dashboard.top_products.length > 0 ? (
                        dashboard.top_products.map((row: TopProduct, index) => (
                          <tr key={`${row.product_ref}-${index}`}>
                            <td data-label="Produto" title={row.product_label}>
                              {row.product_label}
                            </td>
                            <td className="right" data-label="Qtd.">
                              {formatNumber(row.qty_sold)}
                            </td>
                            <td className="right" data-label="Valor Total">
                              {formatCurrency(row.revenue_ttc)}
                            </td>
                          </tr>
                        ))
                      ) : (
                        <tr>
                          <td className="empty-cell" colSpan={3}>
                            Sem produtos para o período selecionado.
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
                <div className="top-products-pagination">
                  <p className="top-products-pagination-status">
                    {dashboardTopProductsTotal > 0
                      ? `${dashboardTopProductsFirstRowNumber}-${dashboardTopProductsLastRowNumber} de ${dashboardTopProductsTotal}`
                      : '0 de 0'}
                  </p>
                  <div className="top-products-pagination-controls" aria-label="Paginação de top produtos">
                    <button
                      type="button"
                      className="ghost-btn top-products-page-btn"
                      onClick={() => void navigateTopProductsPage(-1)}
                      disabled={dashboardLoading || !canNavigateTopProductsPrev}
                    >
                      Ant.
                    </button>
                    <span className="top-products-pagination-label">
                      {dashboardTopProductsCurrentPage}/{dashboardTopProductsPages}
                    </span>
                    <button
                      type="button"
                      className="ghost-btn top-products-page-btn"
                      onClick={() => void navigateTopProductsPage(1)}
                      disabled={dashboardLoading || !canNavigateTopProductsNext}
                    >
                      Próx.
                    </button>
                  </div>
                </div>
              </section>

              <section className="card">
                <h3>Vendas por Empregado</h3>
                <div className="table-wrap">
                  <table className="data-table compact-row-table">
                    <colgroup>
                      <col style={{ width: '52%' }} />
                      <col style={{ width: '18%' }} />
                      <col style={{ width: '30%' }} />
                    </colgroup>
                    <thead>
                      <tr>
                        <th>Empregado</th>
                        <th className="right">Qtd.</th>
                        <th className="right">Vendas C/IVA</th>
                      </tr>
                    </thead>
                    <tbody>
                      {dashboard.sales_by_employee.map((row: EmployeeSale, index) => (
                        <tr key={`${row.employee_name}-${index}`}>
                          <td data-label="Empregado" title={row.employee_name || 'Sem vendedor'}>
                            {row.employee_name || 'Sem vendedor'}
                          </td>
                          <td className="right" data-label="Quantidade">
                            {formatNumber(row.total_quantity_sold)}
                          </td>
                          <td className="right" data-label="Total C/IVA">
                            {formatCurrency(row.total_sales_value_ttc)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot>
                      <tr>
                        <th data-label="Empregado">Total</th>
                        <th className="right" data-label="Quantidade">
                          {formatNumber(employeeTotals.qty)}
                        </th>
                        <th className="right" data-label="Total C/IVA">
                          {formatCurrency(employeeTotals.totalTtc)}
                        </th>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </section>
            </>
          )}
        </section>
      )}

      {availableTabs.length > 0 && tab === 'horarios' && (
        <section className="workforce-space reveal reveal-3">
          <article className="card filter-card">
            <h2>Horarios Semana</h2>
            <div className="workforce-filter-grid">
              <label>
                Semana
                <button type="button" className="date-trigger" onClick={() => openDatePicker('equipaWeek', 'single')}>
                  {formatDateLabel(workforceWeekStart)}
                </button>
              </label>
              <button type="button" className="btn" onClick={() => void applyWorkforceWeekFilter()} disabled={workforceLoading}>
                {workforceLoading ? 'A carregar...' : 'Aplicar'}
              </button>
            </div>
          </article>

          {workforceError && <p className="status-message">{workforceError}</p>}
          {workforceLoading && !workforceData && <p className="muted">A carregar dados da equipa...</p>}

          {workforceData && (
            <>
              <section className="card">
                {workforceData.permissions.schedule_read !== 1 && <p className="muted">Sem permissão de leitura de horários.</p>}
                {workforceData.permissions.schedule_read === 1 && workforceResources.length === 0 && (
                  <p className="muted">Sem colaboradores no grupo de escala configurado.</p>
                )}
                {workforceData.permissions.schedule_read === 1 && workforceResources.length > 0 && (
                  <>
                    <div className="workforce-day-nav">
                      <button
                        type="button"
                        className="ghost-btn workforce-day-step"
                        onClick={() => void navigateWorkforceWeek(-1)}
                        disabled={workforceLoading}
                      >
                        Semana Anterior
                      </button>
                      <p className="workforce-day-nav-title">{`Semana de ${formatDateLabel(selectedWorkforceWeekStart)}`}</p>
                      <button
                        type="button"
                        className="ghost-btn workforce-day-step"
                        onClick={() => void navigateWorkforceWeek(1)}
                        disabled={workforceLoading}
                      >
                        Próxima Semana
                      </button>
                    </div>
                    <div className="workforce-weekday-board">
                      {workforceWeekDays.map((day) => {
                        const holidayRows = workforcePublicHolidayByDate[day.iso] || [];
                        return (
                          <article key={`weekday-${day.iso}`} className="workforce-weekday-card">
                            <div className="workforce-weekday-head">
                              <p className="workforce-weekday-title">{formatDateLabel(day.iso)}</p>
                              {holidayRows.length > 0 && (
                                <p className="workforce-weekday-holiday" title={holidayRows.map((holiday) => holiday.name).join(' • ')}>
                                  {holidayRows.map((holiday) => holiday.name).join(' • ')}
                                </p>
                              )}
                            </div>
                            <div className="workforce-weekday-list">
                              {(() => {
                                const visibleResources = workforceResources.filter((resource) => {
                                  const detail = workforceWeekDetailsByResourceDate[resource.id]?.[day.iso];
                                  return Number(detail?.totalMinutes || 0) > 0;
                                });

                                if (visibleResources.length === 0) {
                                  return <p className="muted">Sem registos neste dia.</p>;
                                }

                                return visibleResources.map((resource) => {
                                  const detail = workforceWeekDetailsByResourceDate[resource.id]?.[day.iso] || {
                                    totalMinutes: 0,
                                    firstTs: null,
                                    lastTs: null,
                                    shiftCount: 0
                                  };
                                  const dayOffRows = workforceDayOffByResourceDate[resource.id]?.[day.iso] || [];
                                  const leaveRows = workforceLeavesByResourceDate[resource.id]?.[day.iso] || [];
                                  const hasShift = detail.totalMinutes > 0;
                                  const planned = Number(workforceWeeklyHours.weekTotalsByResource[resource.id] || 0);
                                  const hasStatePills = planned > 0 || dayOffRows.length > 0 || leaveRows.length > 0;
                                  return (
                                    <article key={`${day.iso}-${resource.id}`} className="workforce-weekday-row">
                                      <p className="workforce-employee-name" title={resource.title}>
                                        {resource.title}
                                      </p>
                                      {hasShift && (
                                        <p className="workforce-employee-meta">
                                          {`IN ${formatHourMinuteFromTimestamp(detail.firstTs)} • OUT ${formatHourMinuteFromTimestamp(detail.lastTs)} • Total ${formatDurationFromMinutes(detail.totalMinutes)}`}
                                        </p>
                                      )}
                                      {hasStatePills && (
                                        <div className="workforce-state-line">
                                          {planned > 0 && (
                                            <span className="workforce-state-pill">
                                              Sem. {formatNumberCompact(planned)}h
                                            </span>
                                          )}
                                          {dayOffRows.slice(0, 1).map((dayOff) => (
                                            <span
                                              key={`dayoff-${resource.id}-${day.iso}-${dayOff.id}`}
                                              className="workforce-state-pill dayoff"
                                              title={dayOff.description || (dayOff.entry_type === 'lockday' ? 'Dia bloqueado' : 'Folga')}
                                            >
                                              {dayOff.entry_type === 'lockday' ? 'Bloqueio' : 'Folga'}
                                            </span>
                                          ))}
                                          {leaveRows.slice(0, 2).map((leave) => (
                                            <span
                                              key={`leave-${resource.id}-${day.iso}-${leave.id}`}
                                              className="workforce-state-pill leave"
                                              title={leave.description || leave.type_label}
                                            >
                                              {leave.type_label}
                                              {leave.is_hours === 1 && leave.hours_range ? ` ${leave.hours_range}` : ''}
                                            </span>
                                          ))}
                                          {leaveRows.length > 2 && <span className="workforce-state-pill">+{leaveRows.length - 2}</span>}
                                        </div>
                                      )}
                                    </article>
                                  );
                                });
                              })()}
                            </div>
                          </article>
                        );
                      })}
                    </div>
                  </>
                )}
              </section>

              <section className="workforce-summary-grid">
                <article className="card workforce-summary-card">
                  <h3>Colaboradores</h3>
                  <strong>{formatNumber(workforceResources.length)}</strong>
                  <p className="muted">com escala no grupo configurado</p>
                </article>
                <article className="card workforce-summary-card">
                  <h3>Horas Planeadas</h3>
                  <strong>{formatNumberCompact(workforceTotalScheduledHours)} h</strong>
                  <p className="muted">Semana selecionada</p>
                </article>
                <article className="card workforce-summary-card">
                  <h3>Horas Contratadas</h3>
                  <strong>{formatNumberCompact(workforceTotalExpectedHours)} h</strong>
                  <p className="muted">Soma esperada da equipa</p>
                </article>
              </section>
            </>
          )}
        </section>
      )}

      {availableTabs.length > 0 && tab === 'barras' && (
        <section className="workforce-space reveal reveal-3">
          <article className="card filter-card">
            <h2>Horarios Dia</h2>
            <div className="workforce-filter-grid">
              <label>
                Dia Horário
                <button type="button" className="date-trigger" onClick={() => openDatePicker('equipaDay', 'single')}>
                  {formatDateLabel(workforceDay)}
                </button>
              </label>
              <button type="button" className="btn" onClick={() => void applyWorkforceDayBarsFilter()} disabled={workforceLoading}>
                {workforceLoading ? 'A carregar...' : 'Aplicar'}
              </button>
            </div>
          </article>

          {workforceError && <p className="status-message">{workforceError}</p>}
          {workforceLoading && !workforceData && <p className="muted">A carregar dados da equipa...</p>}

          {workforceData && (
            <>
              <section className="card">
                {workforceData.permissions.schedule_read !== 1 && <p className="muted">Sem permissão de leitura de horários.</p>}
                {workforceData.permissions.schedule_read === 1 && workforceResources.length === 0 && (
                  <p className="muted">Sem colaboradores para mostrar no dia selecionado.</p>
                )}
                {workforceData.permissions.schedule_read === 1 && workforceResources.length > 0 && (
                  <>
                    <div className="workforce-day-nav">
                        <button
                          type="button"
                          className="ghost-btn workforce-day-step"
                          onClick={() => void navigateWorkforceDay(-1)}
                          disabled={workforceLoading}
                        >
                          Dia Anterior
                        </button>
                      <p className="workforce-day-nav-title">{formatDateLabel(selectedWorkforceDay)}</p>
                        <button
                          type="button"
                          className="ghost-btn workforce-day-step"
                          onClick={() => void navigateWorkforceDay(1)}
                          disabled={workforceLoading}
                        >
                          Próximo Dia
                        </button>
                    </div>
                    {(workforcePublicHolidayByDate[selectedWorkforceDay] || []).length > 0 && (
                      <p className="workforce-weekday-holiday">
                        {(workforcePublicHolidayByDate[selectedWorkforceDay] || []).map((holiday) => holiday.name).join(' • ')}
                      </p>
                    )}
                    <div className="workforce-timeline-marks">
                      {workforceTimelineMarks.map((mark) => (
                        <span
                          key={`${mark.left}-${mark.label}`}
                          className={`workforce-timeline-mark workforce-timeline-mark-${mark.align}`}
                          style={{ left: `${mark.left}%` }}
                        >
                          {mark.label}
                        </span>
                      ))}
                    </div>
                    <div className="workforce-employee-list">
                      {workforceResources
                        .filter((resource) => (workforceDaySegmentsByResource[resource.id] || []).length > 0)
                        .map((resource) => {
                          const segments = workforceDaySegmentsByResource[resource.id] || [];
                          const stats = workforceDayStatsByResource[resource.id] || {
                            totalMinutes: 0,
                            firstTs: null,
                            lastTs: null,
                            shiftCount: 0
                          };
                          const dayOffRows = workforceDayOffByResourceDate[resource.id]?.[selectedWorkforceDay] || [];
                          const leaveRows = workforceLeavesByResourceDate[resource.id]?.[selectedWorkforceDay] || [];

                          return (
                            <article key={`day-${resource.id}`} className="workforce-employee-card">
                              <div className="workforce-employee-head">
                                <p className="workforce-employee-name" title={resource.title}>
                                  {resource.title}
                                </p>
                                <p className="workforce-employee-meta">
                                  {`IN ${formatHourMinuteFromTimestamp(stats.firstTs)} • OUT ${formatHourMinuteFromTimestamp(stats.lastTs)} • Total ${formatDurationFromMinutes(stats.totalMinutes)}`}
                                </p>
                                <div className="workforce-state-line">
                                  {dayOffRows.slice(0, 1).map((dayOff) => (
                                    <span
                                      key={`dayoff-day-${resource.id}-${dayOff.id}`}
                                      className="workforce-state-pill dayoff"
                                      title={dayOff.description || (dayOff.entry_type === 'lockday' ? 'Dia bloqueado' : 'Folga')}
                                    >
                                      {dayOff.entry_type === 'lockday' ? 'Bloqueio' : 'Folga'}
                                    </span>
                                  ))}
                                  {leaveRows.slice(0, 2).map((leave) => (
                                    <span key={`leave-day-${resource.id}-${leave.id}`} className="workforce-state-pill leave" title={leave.description || leave.type_label}>
                                      {leave.type_label}
                                      {leave.is_hours === 1 && leave.hours_range ? ` ${leave.hours_range}` : ''}
                                    </span>
                                  ))}
                                  {leaveRows.length > 2 && <span className="workforce-state-pill">+{leaveRows.length - 2}</span>}
                                </div>
                              </div>
                              <div className="workforce-timeline-track">
                                {segments.map((segment) => (
                                  <div
                                    key={`${resource.id}-${segment.id}-${segment.left}`}
                                    className="workforce-timeline-segment"
                                    style={{
                                      left: `${segment.left}%`,
                                      width: `${segment.width}%`,
                                      backgroundColor: segment.color
                                    }}
                                    title={`${segment.title} • ${segment.time}`}
                                  />
                                ))}
                              </div>
                              {segments.length > 0 && (
                                <div className="workforce-segment-list">
                                  {segments.map((segment) => (
                                    <span key={`${resource.id}-legend-${segment.id}-${segment.left}`} className="workforce-segment-chip">
                                      <i style={{ backgroundColor: segment.color }} />
                                      {segment.title} • {segment.time}
                                    </span>
                                  ))}
                                </div>
                              )}
                            </article>
                          );
                        })}
                      {workforceResources.filter((resource) => (workforceDaySegmentsByResource[resource.id] || []).length > 0).length === 0 && (
                        <p className="muted">Sem turnos no dia selecionado.</p>
                      )}
                    </div>
                  </>
                )}
              </section>

              <section className="workforce-summary-grid">
                <article className="card workforce-summary-card">
                  <h3>Colaboradores</h3>
                  <strong>{formatNumber(workforceResources.length)}</strong>
                  <p className="muted">com escala no grupo configurado</p>
                </article>
                <article className="card workforce-summary-card">
                  <h3>Horas Planeadas</h3>
                  <strong>{formatNumberCompact(workforceTotalScheduledHours)} h</strong>
                  <p className="muted">Semana selecionada</p>
                </article>
                <article className="card workforce-summary-card">
                  <h3>Horas Contratadas</h3>
                  <strong>{formatNumberCompact(workforceTotalExpectedHours)} h</strong>
                  <p className="muted">Soma esperada da equipa</p>
                </article>
              </section>
            </>
          )}
        </section>
      )}

      {availableTabs.length > 0 && tab === 'ponto' && (
        <section className="workforce-space reveal reveal-3">
          <article className="card filter-card">
            <h2>Ponto</h2>
            <div className="workforce-filter-grid">
              <label>
                Dia
                <button type="button" className="date-trigger" onClick={() => openDatePicker('equipaPunch', 'single')}>
                  {formatDateLabel(workforcePunchDate)}
                </button>
              </label>
              <div className="workforce-filter-actions">
                <button type="button" className="btn" onClick={() => void applyPunchClockFilter()} disabled={workforceLoading}>
                  {workforceLoading ? 'A carregar...' : 'Aplicar'}
                </button>
                <button type="button" className="btn" onClick={() => void refreshPunchClockToday()} disabled={workforceLoading}>
                  Hoje
                </button>
              </div>
            </div>
          </article>

          {workforceError && <p className="status-message">{workforceError}</p>}
          {workforceLoading && !workforceData && <p className="muted">A carregar registos de ponto...</p>}

          {workforceData && (
            <>
              <section className="card">
                <div className="workforce-day-nav">
                  <button
                    type="button"
                    className="ghost-btn workforce-day-step"
                    onClick={() => void navigatePunchClockDay(-1)}
                    disabled={workforceLoading}
                  >
                    Dia Anterior
                  </button>
                  <p className="workforce-day-nav-title">{formatDateLabel(selectedPunchDate)}</p>
                  <button
                    type="button"
                    className="ghost-btn workforce-day-step"
                    onClick={() => void navigatePunchClockDay(1)}
                    disabled={workforceLoading}
                  >
                    Próximo Dia
                  </button>
                </div>
                {workforceData.permissions.punch_read !== 1 && <p className="muted">Sem permissão de leitura de ponto.</p>}
                {workforceData.permissions.punch_read === 1 && workforcePunchByEmployee.length === 0 && (
                  <p className="muted">Sem registos de ponto para o dia selecionado.</p>
                )}
                {workforceData.permissions.punch_read === 1 && workforcePunchByEmployee.length > 0 && (
                  <div className="workforce-punch-groups">
                    {workforcePunchByEmployee.map((group) => (
                      <article key={group.key} className="workforce-punch-group">
                        <div className="workforce-punch-group-head">
                          <p className="workforce-employee-name" title={group.name}>
                            {group.name}
                          </p>
                          <p className="workforce-employee-meta">
                            {group.pairedRowsCount > 0
                              ? `EmpID ${group.empid} • Total ${formatDurationFromSeconds(group.totalSeconds)}`
                              : `EmpID ${group.empid}`}
                          </p>
                        </div>
                        <div className="workforce-punch-row-list">
                          {group.rows.map((row) => {
                            const hasIn = hasDateTimeValue(row.datahora_in);
                            const hasOut = hasDateTimeValue(row.datahora_out);
                            const pairedDuration = getPunchRowDurationSeconds(row);
                            if (!hasIn && !hasOut) {
                              return null;
                            }

                            return (
                              <div key={row.id} className="workforce-punch-row">
                                {hasIn && <span className="workforce-punch-pill">IN {formatHourMinute(row.datahora_in)}</span>}
                                {hasOut && <span className="workforce-punch-pill">OUT {formatHourMinute(row.datahora_out)}</span>}
                                {pairedDuration > 0 && (
                                  <strong className="workforce-punch-duration">{formatDurationFromSeconds(pairedDuration)}</strong>
                                )}
                              </div>
                            );
                          })}
                        </div>
                      </article>
                    ))}
                  </div>
                )}
              </section>

              <section className="workforce-summary-grid">
                <article className="card workforce-summary-card">
                  <h3>Colaboradores</h3>
                  <strong>{formatNumber(workforcePunchByEmployee.length)}</strong>
                  <p className="muted">com registos no dia</p>
                </article>
                <article className="card workforce-summary-card">
                  <h3>Registos</h3>
                  <strong>{formatNumber(workforcePunchRows.length)}</strong>
                  <p className="muted">entradas/saídas do dia</p>
                </article>
                {workforceTotalPunchSeconds > 0 && (
                  <article className="card workforce-summary-card">
                    <h3>Total</h3>
                    <strong>{formatDurationFromSeconds(workforceTotalPunchSeconds)}</strong>
                    <p className="muted">tempo acumulado no dia</p>
                  </article>
                )}
              </section>
            </>
          )}
        </section>
      )}

      {availableTabs.length > 0 && tab === 'forecast' && (
        <section className="forecast-space reveal reveal-3">
          <article className="card filter-card">
            <h2>Previsão de Vendas (Weather-aware)</h2>
            <p className="muted forecast-intro">
              Modelo sazonal por dia da semana, com tendência recente, meteorologia diária e proteção contra valores atípicos.
            </p>
            <p className="muted">
              Visualização: últimos {formatNumber(forecastData?.period.chart_days || 30)} dias · modelo: últimos{' '}
              {formatNumber(forecastData?.period.training_days || 365)} dias completos.
            </p>
          </article>

          {forecastError && <p className="status-message">{forecastError}</p>}
          {forecastLoading && !forecastData && <p className="muted">A carregar previsão de vendas...</p>}

          {forecastData && (
            <>
              <ForecastAccuracyPanel data={forecastData.accuracy} />
              <section className="kpi-grid">
                <article className="card stat-card">
                  <h3>Receita Histórica</h3>
                  <p className="muted">C/IVA</p>
                  <h2 className="forecast-vat-value">{formatCurrency(forecastData.kpis.historical_total_ttc)}</h2>
                  <p className="muted">S/IVA</p>
                  <h4>{formatCurrency(forecastData.kpis.historical_total_ht)}</h4>
                  <p className="muted forecast-period">
                    Período: {forecastData.period.date_start} a {forecastData.period.date_end}
                  </p>
                </article>
                <article className="card stat-card">
                  <h3>Média Diária Hist.</h3>
                  <p className="muted">C/IVA</p>
                  <h2 className="forecast-vat-value">{formatCurrency(forecastData.kpis.historical_avg_ttc)}</h2>
                  <p className="muted">S/IVA</p>
                  <h4>{formatCurrency(forecastData.kpis.historical_avg_ht)}</h4>
                </article>
                <article className="card stat-card">
                  <h3>Previsão {formatNumber(forecastData.period.forecast_days || 0)} dias</h3>
                  <p className="muted">C/IVA</p>
                  <h2 className="forecast-vat-value">{formatCurrency(forecastData.kpis.forecast_total_ttc)}</h2>
                  <p className="muted">S/IVA</p>
                  <h4>{formatCurrency(forecastData.kpis.forecast_total_ht)}</h4>
                </article>
                <article className="card stat-card">
                  <h3>Média Prevista (dia)</h3>
                  <p className="muted">C/IVA</p>
                  <h2 className="forecast-vat-value">{formatCurrency(forecastData.kpis.forecast_avg_ttc)}</h2>
                  <p className="muted">S/IVA</p>
                  <h4>{formatCurrency(forecastData.kpis.forecast_avg_ht)}</h4>
                </article>
              </section>

              <section className="card chart-card forecast-chart-card">
                <h3>Histórico vs Previsão (receita diária)</h3>
                <div className="forecast-chart-legend" aria-label="Legenda do gráfico">
                  <span><i className="forecast-legend-swatch historical" />Real</span>
                  <span><i className="forecast-legend-swatch forecast" />Previsão</span>
                </div>
                <div className="chart-box">
                  <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={forecastChartRows}>
                      <defs>
                        <linearGradient id="salesForecastStroke" x1="0" y1="0" x2="1" y2="0">
                          <stop offset={`${forecastColorBoundary}%`} stopColor="var(--kf-chart-line)" />
                          <stop offset={`${forecastColorBoundary}%`} stopColor="var(--kf-chart-bar)" />
                        </linearGradient>
                      </defs>
                      <CartesianGrid strokeDasharray="3 3" stroke="var(--kf-border)" />
                      <XAxis dataKey="date" />
                      <YAxis tickFormatter={(value) => formatNumber(Number(value))} />
                      <Tooltip formatter={(value) => tooltipCurrency(Number(value))} />
                      <Line isAnimationActive={false}
                        type="linear"
                        dataKey="combined_ttc"
                        name="Receita C/IVA"
                        stroke="url(#salesForecastStroke)"
                        strokeWidth={2.4}
                        dot={false}
                      />
                    </LineChart>
                  </ResponsiveContainer>
                </div>
              </section>

              <section className="card">
                <h3>Previsão por dia (próximos {formatNumber(forecastData.period.forecast_days || 0)})</h3>
                <div className="table-wrap">
                  <table className="data-table forecast-table">
                    <thead>
                      <tr>
                        <th>Data</th>
                        <th className="right">Previsto C/IVA (€)</th>
                        <th className="right">Previsto S/IVA (€)</th>
                        <th className="right">Temp. média (°C)</th>
                        <th className="right">Precip. (mm)</th>
                        <th>Intensidade</th>
                        <th>Prob. Chuva</th>
                      </tr>
                    </thead>
                    <tbody>
                      {forecastData.forecast_rows.length > 0 ? (
                        forecastData.forecast_rows.map((row, index) => (
                          <tr key={`${row.date}-${index}`}>
                            <td data-label="Data">{row.date}</td>
                            <td className="right forecast-vat-value" data-label="Previsto C/IVA (€)">
                              {formatAmount(row.forecast_ttc)}
                            </td>
                            <td className="right" data-label="Previsto S/IVA (€)">
                              {formatAmount(row.forecast_ht)}
                            </td>
                            <td className="right" data-label="Temp. média (°C)">
                              {row.temp_mean === null ? 'N/D' : formatAmount(row.temp_mean, 1)}
                            </td>
                            <td className="right" data-label="Precip. (mm)">
                              {formatAmount(row.precip_mm, 1)}
                            </td>
                            <td data-label="Intensidade">{row.precip_intensity}</td>
                            <td data-label="Prob. Chuva">
                              {row.precip_probability_pct === null
                                ? row.precip_probability
                                : `${formatAmount(row.precip_probability_pct, 0)}%`}
                            </td>
                          </tr>
                        ))
                      ) : (
                        <tr>
                          <td className="empty-cell" colSpan={7}>
                            Sem previsões disponíveis para o período selecionado.
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </section>
            </>
          )}
        </section>
      )}

      {availableTabs.length > 0 && tab === 'stock_forecast' && (
        <section className="stock-forecast-space reveal reveal-3">
          <article className="card filter-card">
            <h2>Previsão de Stock — Patties</h2>
            <p className="muted forecast-intro">
              Necessidade prevista a partir das vendas projetadas e do consumo operacional recente de cada patty.
            </p>
            <p className="muted">Sem stock de segurança nem entradas de fornecedor planeadas.</p>
          </article>

          {stockForecastError && <p className="status-message">{stockForecastError}</p>}
          {stockForecastLoading && !stockForecastData && <p className="muted">A carregar previsão de stock...</p>}

          {stockForecastData && (
            <>
              <section className="card">
                <h3>Dia da previsão</h3>
                <p className="muted stock-forecast-help">
                  Escolha um dia futuro para ver a necessidade individual. A previsão começa amanhã e não soma os restantes dias.
                </p>
                {stockForecastDates.length > 0 ? (
                  <div className="stock-forecast-day-grid" role="group" aria-label="Dia da previsão de stock">
                    {stockForecastDates.map((dateValue) => (
                      <button
                        className={`stock-forecast-day-button ${dateValue === selectedStockForecastDate ? 'active' : ''}`}
                        type="button"
                        key={dateValue}
                        aria-pressed={dateValue === selectedStockForecastDate}
                        onClick={() => setStockForecastDate(dateValue)}
                      >
                        {formatForecastDayOption(dateValue)}
                      </button>
                    ))}
                  </div>
                ) : (
                  <p className="muted">Sem dias de previsão disponíveis.</p>
                )}
              </section>

              <section className="kpi-grid stock-forecast-kpis">
                <article className="card stat-card">
                  <h3>Último inventário</h3>
                  <h2>{stockForecastData.inventory.ref}</h2>
                  <p className="muted">
                    {stockForecastData.inventory.warehouse_ref} ·{' '}
                    {formatDateLabel(toInputDate(new Date(stockForecastData.inventory.date_inventory * 1000)))} às{' '}
                    {stockForecastData.inventory.snapshot_time}
                  </p>
                </article>
                <article className="card stat-card">
                  <h3>Vendas previstas</h3>
                  <h2 className="forecast-vat-value">{formatCurrency(selectedStockForecastSales)}</h2>
                  <p className="muted">
                    C/IVA · {selectedStockForecastDate ? formatDateLabel(selectedStockForecastDate) : 'dia indisponível'}
                  </p>
                </article>
                <article className="card stat-card">
                  <h3>Produtos com falta</h3>
                  <h2 className={stockForecastDayAtRisk > 0 ? 'stock-shortage-value' : 'stock-ok-value'}>
                    {formatNumber(stockForecastDayAtRisk)}
                  </h2>
                  <p className="muted">no fim do dia · de {formatNumber(stockForecastData.summary.product_count)} patties</p>
                </article>
              </section>

              <section className="card">
                <h3>
                  Necessidade diária{selectedStockForecastDate ? ` — ${formatDateLabel(selectedStockForecastDate)}` : ''}
                </h3>
                <p className="muted stock-forecast-help">
                  “Necessário no dia” não inclui outros dias. O saldo previsto desconta o consumo acumulado até ao fim do dia selecionado.
                </p>
                <div className="table-wrap">
                  <table className="data-table forecast-table stock-forecast-table">
                    <thead>
                      <tr>
                        <th>Produto</th>
                        <th className="right">Stock atual</th>
                        <th className="right">Necessário no dia</th>
                        <th className="right">Saldo fim do dia</th>
                        <th className="right">Falta</th>
                      </tr>
                    </thead>
                    <tbody>
                      {stockForecastDayProducts.length > 0 ? (
                        stockForecastDayProducts.map((row) => (
                          <tr key={row.product.product_id}>
                            <td data-label="Produto">
                              <span className="stock-product-value">
                                <strong>{row.product.ref}</strong>
                                <span className="stock-product-label">{row.product.label}</span>
                              </span>
                            </td>
                            <td className="right" data-label="Stock atual">
                              {formatNumberCompact(row.product.current_stock)}
                            </td>
                            <td className="right" data-label="Necessário no dia">
                              {formatNumberCompact(row.requiredToday)}
                            </td>
                            <td
                              className={`right ${row.projectedStock < 0 ? 'stock-shortage-value' : 'stock-ok-value'}`}
                              data-label="Saldo fim do dia"
                            >
                              {formatNumberCompact(row.projectedStock)}
                            </td>
                            <td
                              className={`right ${row.shortage > 0 ? 'stock-shortage-value' : ''}`}
                              data-label="Falta"
                            >
                              {formatNumberCompact(row.shortage)}
                            </td>
                          </tr>
                        ))
                      ) : (
                        <tr>
                          <td className="empty-cell" colSpan={5}>
                            Sem produtos no último inventário de PATTIES.
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </section>

              <article className="card stock-forecast-method">
                <h3>Base do cálculo</h3>
                <p className="muted">
                  Consumo de {formatNumber(stockForecastData.period.history_days)} dias, de {stockForecastData.period.history_start} a{' '}
                  {stockForecastData.period.history_end}. O stock atual pertence apenas ao armazém do inventário da entidade ativa.
                </p>
              </article>
            </>
          )}
        </section>
      )}

      {availableTabs.length > 0 && tab === 'produtos' && (
        <section className="card reveal reveal-3">
          <h2>Consulta de Produtos</h2>
          <p className="muted">Digite só números para pesquisar por referência. Texto (ou texto+número) pesquisa no nome.</p>

          <div className="product-layout-row">
            <button
              type="button"
              className={`ghost-btn product-layout-btn ${productLayoutMode === 'search' ? 'is-active' : ''}`}
              onClick={() => setProductLayoutMode('search')}
            >
              Pesquisa
            </button>
            <button
              type="button"
              className={`ghost-btn product-layout-btn ${productLayoutMode === 'categories' ? 'is-active' : ''}`}
              onClick={() => setProductLayoutMode('categories')}
            >
              Categorias
            </button>
          </div>

          {productLayoutMode === 'search' && (
            <>
              <div className="search-row product-controls">
                <input
                  value={productQuery}
                  onChange={(event) => {
                    const nextQuery = event.target.value;
                    setProductQuery(nextQuery);
                    if (nextQuery.trim() === '') {
                      setHasProductSearchRun(false);
                      setProducts([]);
                      setProductsError(null);
                    }
                  }}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                      event.preventDefault();
                      void runProductSearch();
                    }
                  }}
                  placeholder="Pesquisar produto"
                />
                <button type="button" className="btn" onClick={() => void runProductSearch()} disabled={productsLoading}>
                  {productsLoading ? 'A pesquisar...' : 'Pesquisar'}
                </button>
              </div>

              {productsError && <p className="status-message">{productsError}</p>}
              {productsLoading && <p className="muted">A pesquisar produtos...</p>}

              <div className="product-list">
                {products.map((product) => (
                  <button
                    key={product.id}
                    type="button"
                    className="product-item product-item-action"
                    onClick={() => void openProductDetail(product.id)}
                  >
                    <div className="product-item-main">
                      <p className="product-item-label" title={product.label}>
                        {product.label}
                      </p>
                      <p className="product-item-meta">
                        Ref. {product.ref} • IVA {formatPercent(product.tva_tx)}
                      </p>
                    </div>
                    <div className="product-item-side">
                      <p className="product-item-price">{formatCurrency(product.price_ttc)}</p>
                      <span className="product-item-open">Ver</span>
                    </div>
                  </button>
                ))}

                {hasProductSearchRun && !productsLoading && !products.length && <p className="empty-cell">Sem resultados.</p>}
                {!hasProductSearchRun && !products.length && <p className="empty-cell">Sem resultados carregados.</p>}
              </div>
            </>
          )}

          {productLayoutMode === 'categories' && (
            <>
              {categoriesError && <p className="status-message">{categoriesError}</p>}
              {categoryProductsError && <p className="status-message">{categoryProductsError}</p>}
              {(categoriesLoading || categoryProductsLoading) && <p className="muted">A carregar categorias...</p>}

              {!categoriesLoading && !categoryProductsLoading && (
                <div className="product-category-board">
                  {productCategories.map((category) => (
                    <article key={category.id} className="product-category-card">
                      <header className="product-category-head">
                        <h3 className="product-category-title" title={category.label}>
                          {category.label}
                        </h3>
                        <span className="product-category-count">{formatNumber(category.product_count)}</span>
                      </header>

                      <div className="product-list">
                        {(categoryProductsById[category.id] || []).map((product) => (
                          <button
                            key={product.id}
                            type="button"
                            className="product-item product-item-action"
                            onClick={() => void openProductDetail(product.id)}
                          >
                            <div className="product-item-main">
                              <p className="product-item-label" title={product.label}>
                                {product.label}
                              </p>
                              <p className="product-item-meta">
                                Ref. {product.ref} • IVA {formatPercent(product.tva_tx)}
                              </p>
                            </div>
                            <div className="product-item-side">
                              <p className="product-item-price">{formatCurrency(product.price_ttc)}</p>
                              <span className="product-item-open">Ver</span>
                            </div>
                          </button>
                        ))}

                        {!(categoryProductsById[category.id] || []).length && <p className="empty-cell">Sem produtos nesta categoria.</p>}
                      </div>
                    </article>
                  ))}

                  {!productCategories.length && <p className="empty-cell">Sem categorias configuradas no setup.</p>}
                </div>
              )}
            </>
          )}
        </section>
      )}

      {availableTabs.length > 0 && tab === 'fecho' && (
        <section className="card reveal reveal-3">
          <div className="fecho-header-row">
            <h2>Fecho Financeiro</h2>
            <button type="button" className="btn fecho-new-btn" onClick={() => void openFechoCreateModal()} disabled={!fechoCanCreate || fechoMetaLoading}>
              Novo
            </button>
          </div>

          <div className="fecho-filter-grid">
            <label>
              Início
              <button type="button" className="date-trigger" onClick={() => openDatePicker('fecho', 'start')}>
                {formatDateLabel(fechoDateStart)}
              </button>
            </label>
            <label>
              Fim
              <button type="button" className="date-trigger" onClick={() => openDatePicker('fecho', 'end')}>
                {formatDateLabel(fechoDateEnd)}
              </button>
            </label>
            <label>
              Estado
              <select value={fechoStatus} onChange={(event) => setFechoStatus(Number.parseInt(event.target.value, 10) || -1)}>
                {FECHO_STATUS_OPTIONS.map((statusOption) => (
                  <option key={statusOption.value} value={statusOption.value}>
                    {statusOption.label}
                  </option>
                ))}
              </select>
            </label>
          </div>

          <div className="search-row">
            <input
              value={fechoSearch}
              onChange={(event) => setFechoSearch(event.target.value)}
              placeholder="Pesquisar por ref., loja ou responsável"
            />
            <button type="button" className="btn" onClick={() => void applyFechoFilter()} disabled={fechoLoading}>
              {fechoLoading ? 'A carregar...' : 'Aplicar'}
            </button>
          </div>

          {fechoError && <p className="status-message">{fechoError}</p>}
          {fechoMetaError && <p className="status-message">{fechoMetaError}</p>}

          {fechoData && (
            <>
              <p className="muted">
                Registos: {formatNumber(fechoData.count)} • S/IVA: {formatCurrency(fechoData.totals.total_sem_iva)} • C/IVA:{' '}
                {formatCurrency(fechoData.totals.total_talao_caixa)}
              </p>

              <div className="fecho-list">
                {fechoData.rows.map((row: FechoFinanceiroItem) => (
                  <article key={row.id} className="fecho-item">
                    <div className="fecho-item-main">
                      <p className="fecho-item-ref" title={row.ref}>
                        {row.ref}
                      </p>
                      <p className="fecho-item-meta" title={row.store_label}>
                        {row.store_label} • {formatDateLabel(row.datecx)}
                      </p>
                      <p className="fecho-item-meta" title={row.responsavel_label}>
                        {row.responsavel_label}
                      </p>
                    </div>
                    <div className="fecho-item-side">
                      <p className="fecho-item-value">S/IVA {formatCurrency(row.total_sem_iva)}</p>
                      <p className="fecho-item-value">C/IVA {formatCurrency(row.val_talao_caixa)}</p>
                      <span className={`fecho-status ${getFechoStatusClass(row.status)}`}>{row.status_label}</span>
                      <div className="fecho-actions">
                        <button type="button" className="ghost-btn fecho-action-btn" onClick={() => void openFechoDetailModal('view', row.id)}>
                          Ver
                        </button>
                        <button
                          type="button"
                          className="ghost-btn fecho-action-btn"
                          onClick={() => void openFechoDetailModal('edit', row.id)}
                          disabled={!fechoCanUpdate}
                        >
                          Editar
                        </button>
                      </div>
                    </div>
                  </article>
                ))}

                {!fechoData.rows.length && <p className="empty-cell">Sem fechos financeiros no período selecionado.</p>}
              </div>
            </>
          )}
        </section>
      )}

      {availableTabs.length > 0 && tab === 'about' && (
        <section className="about-space reveal reveal-3">
          <article className="card">
            <h2>Sobre</h2>
            <div className="about-logo-wrap">
              <img src={ABOUT_LOGO_URL} alt="KreaFlow" className="about-logo" loading="lazy" />
            </div>
            <p className="muted">{aboutInfo.module}</p>
            <p className="about-license">{aboutInfo.gpl_notice}</p>
            <div className="about-grid">
              <div className="about-row">
                <span>Módulo</span>
                <strong>{aboutInfo.module}</strong>
              </div>
              <div className="about-row">
                <span>Versão</span>
                <strong>{aboutInfo.version || '-'}</strong>
              </div>
              <div className="about-row">
                <span>Editor</span>
                <strong>{aboutInfo.editor}</strong>
              </div>
              <div className="about-row">
                <span>Licença</span>
                <strong>{aboutInfo.license}</strong>
              </div>
              <div className="about-row">
                <span>Suporte</span>
                <strong>
                  <a href={`mailto:${aboutInfo.support}`} className="about-link">
                    {aboutInfo.support}
                  </a>
                </strong>
              </div>
              <div className="about-row">
                <span>Website</span>
                <strong>
                  <a href={aboutInfo.website} target="_blank" rel="noopener noreferrer" className="about-link">
                    {aboutInfo.website}
                  </a>
                </strong>
              </div>
            </div>
          </article>
        </section>
      )}

      {isFechoModalOpen && (
        <section className="fecho-modal" role="dialog" aria-modal="true" aria-label="Fecho Financeiro">
          <button type="button" className="fecho-modal-backdrop" aria-label="Fechar fecho financeiro" onClick={closeFechoModal} />
          <div className="fecho-modal-sheet">
            <div className="fecho-modal-header">
              <div className="fecho-modal-headline">
                <h3>{getFechoModalModeLabel(fechoModalMode)}</h3>
                {fechoForm && (
                  <p className="fecho-modal-meta">
                    <span className="fecho-modal-ref">{fechoForm.ref}</span>
                    <span className={`fecho-status ${getFechoStatusClass(fechoForm.status)}`}>{fechoForm.status_label}</span>
                    <span>{formatDateLabel(fechoForm.datecx)}</span>
                  </p>
                )}
              </div>
              <div className="fecho-modal-actions">
                {fechoModalMode === 'view' && fechoCanUpdate && fechoForm?.can_edit === 1 && (
                  <button type="button" className="ghost-btn fecho-modal-btn" onClick={() => setFechoModalMode('edit')}>
                    Editar
                  </button>
                )}
                <button type="button" className="ghost-btn fecho-modal-btn" onClick={closeFechoModal}>
                  Fechar
                </button>
              </div>
            </div>

            {fechoModalLoading && <p className="status-message">A carregar fecho financeiro...</p>}
            {fechoModalError && <p className="status-message">{fechoModalError}</p>}

            {fechoForm && !fechoModalLoading && (
              <>
                <div className="fecho-primary-grid">
                  <label>
                    Data
                    <button
                      type="button"
                      className="date-trigger"
                      onClick={() => openDatePicker('fechoModal', 'single')}
                      disabled={fechoInputLocked}
                    >
                      {formatDateLabel(fechoForm.datecx)}
                    </button>
                  </label>
                  <label>
                    Loja
                    <select
                      value={fechoForm.fk_loja}
                      onChange={(event) => setFechoStore(event.target.value)}
                      disabled={fechoInputLocked || !fechoCanChangeStore}
                    >
                      {!fechoStores.some((store) => store.id === fechoForm.fk_loja) && fechoForm.fk_loja > 0 && (
                        <option value={fechoForm.fk_loja}>{fechoForm.store_label || `Loja #${fechoForm.fk_loja}`}</option>
                      )}
                      {fechoStores.map((store) => (
                        <option key={store.id} value={store.id}>
                          {store.label}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label>
                    Responsável
                    <select value={fechoForm.responsavel} onChange={(event) => setFechoResponsavel(event.target.value)} disabled={fechoInputLocked}>
                      {!fechoUsers.some((usr) => usr.id === fechoForm.responsavel) && fechoForm.responsavel > 0 && (
                        <option value={fechoForm.responsavel}>
                          {fechoForm.responsavel_label || `Utilizador #${fechoForm.responsavel}`}
                        </option>
                      )}
                      {fechoUsers.map((usr) => (
                        <option key={usr.id} value={usr.id}>
                          {usr.label}
                        </option>
                      ))}
                    </select>
                  </label>
                </div>

                <div className="fecho-form-stack">
                  <article className="fecho-form-block">
                    <h4>Total de Vendas</h4>
                    <div className="fecho-form-fields">{FECHO_TOTAL_VENDAS_FIELDS.map(renderFechoField)}</div>
                    {fechoFormMetrics && (
                      <div className="fecho-summary-rows">
                        <div className="fecho-summary-row">
                          <span className="fecho-summary-label">
                            Total com IVA
                            <i className={`fecho-validation-dot ${fechoValidationState?.vatValid ? 'is-valid' : 'is-invalid'}`} />
                          </span>
                          <strong>{formatCurrency(fechoFormMetrics.total_com_iva_calc)}</strong>
                        </div>
                        <p className="fecho-summary-note">
                          Diferença para Talão: {formatCurrency(fechoValidationState?.vatDifference || 0)}
                        </p>
                      </div>
                    )}
                  </article>

                  <article className="fecho-form-block">
                    <h4>Talão de Fecho</h4>
                    <div className="fecho-form-fields">{FECHO_TALAO_FECHO_FIELDS.map(renderFechoField)}</div>
                    {fechoFormMetrics && (
                      <div className="fecho-summary-rows">
                        <div className="fecho-summary-row">
                          <span>Soma Talão (2)</span>
                          <strong>{formatCurrency(fechoFormMetrics.soma_talao)}</strong>
                        </div>
                      </div>
                    )}
                  </article>

                  <article className="fecho-form-block">
                    <h4>Total Recebido Hoje</h4>
                    <div className="fecho-form-fields">{FECHO_TOTAL_RECEBIDO_FIELDS.map(renderFechoField)}</div>
                    {fechoFormMetrics && (
                      <div className="fecho-summary-rows">
                        <div className="fecho-summary-row">
                          <span>Soma Notas</span>
                          <strong>{formatCurrency(fechoFormMetrics.soma_notas)}</strong>
                        </div>
                        <div className="fecho-summary-row">
                          <span>Soma Numerário</span>
                          <strong>{formatCurrency(fechoFormMetrics.soma_numerario)}</strong>
                        </div>
                        <div className="fecho-summary-row">
                          <span>Soma TPA</span>
                          <strong>{formatCurrency(fechoFormMetrics.soma_tpa)}</strong>
                        </div>
                        <div className="fecho-summary-row">
                          <span>Total Recebido Hoje (3)</span>
                          <strong>{formatCurrency(fechoFormMetrics.soma_total_valores)}</strong>
                        </div>
                      </div>
                    )}
                  </article>

                  <article className="fecho-form-block">
                    <h4>Resumo de Fecho</h4>
                    {fechoFormMetrics && (
                      <div className="fecho-summary-rows">
                        <div className="fecho-summary-row">
                          <span>Diferença Calculada</span>
                          <strong>{formatCurrency(fechoFormMetrics.diferenca_calculada)}</strong>
                        </div>
                      </div>
                    )}
                    <div className="fecho-form-fields">{FECHO_RESUMO_INPUT_FIELDS.map(renderFechoField)}</div>
                    {fechoFormMetrics && (
                      <div className="fecho-summary-rows">
                        <div className="fecho-summary-row">
                          <span>Total Faturado C/IVA</span>
                          <strong>{formatCurrency(fechoFormMetrics.val_talao_caixa)}</strong>
                        </div>
                        <div className="fecho-summary-row">
                          <span>Total Recebido Hoje</span>
                          <strong>{formatCurrency(fechoFormMetrics.soma_total_valores)}</strong>
                        </div>
                        <div className="fecho-summary-row">
                          <span>Total Pendente Crédito</span>
                          <strong>{formatCurrency(fechoFormMetrics.credito_liquido)}</strong>
                        </div>
                      </div>
                    )}
                    {fechoValidationState && (
                      <div className="fecho-validations">
                        <h5>Validações</h5>
                        <div className="fecho-validation-row">
                          <span className={`fecho-validation-dot ${fechoValidationState.salesValid ? 'is-valid' : 'is-invalid'}`} />
                          <span>Validação de Vendas</span>
                          <strong>{fechoValidationState.salesValid ? 'OK' : formatCurrency(fechoValidationState.salesDifference)}</strong>
                        </div>
                        <div className="fecho-validation-row">
                          <span className={`fecho-validation-dot ${fechoValidationState.treasuryValid ? 'is-valid' : 'is-invalid'}`} />
                          <span>Validação de Tesouraria</span>
                          <strong>
                            {fechoValidationState.treasuryValid ? 'OK' : formatCurrency(fechoValidationState.treasuryDifference)}
                          </strong>
                        </div>
                      </div>
                    )}
                  </article>
                </div>

                <label className="fecho-description-field">
                  Observações
                  <textarea
                    value={fechoForm.description}
                    onChange={(event) => setFechoDescription(event.target.value)}
                    rows={2}
                    maxLength={500}
                    disabled={fechoInputLocked}
                  />
                </label>

                <div className="fecho-modal-footer">
                  {fechoModalMode === 'edit' && (
                    <button type="button" className="ghost-btn fecho-modal-btn" onClick={() => setFechoModalMode('view')} disabled={fechoSaving}>
                      Cancelar
                    </button>
                  )}
                  {fechoCanEditCurrent && (
                    <button type="button" className="btn fecho-modal-btn" onClick={() => void saveFechoForm()} disabled={fechoSaving || fechoModalLoading}>
                      {fechoSaving ? 'A gravar...' : 'Gravar'}
                    </button>
                  )}
                </div>
              </>
            )}
          </div>
        </section>
      )}

      {isProductDetailOpen && (
        <section className="product-detail-modal" role="dialog" aria-modal="true" aria-label="Detalhes do produto">
          <button type="button" className="product-detail-backdrop" aria-label="Fechar detalhes do produto" onClick={closeProductDetail} />
          <div className="product-detail-sheet">
            <div className="product-detail-header">
              <div className="product-detail-headline">
                <h3 title={productDetail?.label || ''}>{productDetail?.label || 'Detalhes do Produto'}</h3>
                {productDetail && (
                  <p className="product-detail-ref">
                    Ref. {productDetail.ref} • IVA {formatPercent(productDetail.tva_tx)}
                  </p>
                )}
              </div>
              <button type="button" className="ghost-btn" onClick={closeProductDetail}>
                Fechar
              </button>
            </div>

            {productDetailLoading && <p className="status-message">A carregar detalhes do produto...</p>}
            {productDetailError && <p className="status-message">{productDetailError}</p>}

            {productDetail && (
              <>
                <div className="product-detail-kpis">
                  <article className="product-detail-kpi">
                    <span className="muted">Preço S/IVA</span>
                    <strong>{formatCurrency(productDetail.price_ht)}</strong>
                  </article>
                  <article className="product-detail-kpi">
                    <span className="muted">Preço C/IVA</span>
                    <strong>{formatCurrency(productDetail.price_ttc)}</strong>
                  </article>
                  <article className="product-detail-kpi">
                    <span className="muted">Custo</span>
                    <strong>{formatOptionalCurrency(productDetail.cost_price)}</strong>
                  </article>
                </div>

                {productDetail.description && <p className="product-detail-description">{productDetail.description}</p>}

                <section className="product-detail-block">
                  <h4>Fornecedores</h4>
                  {productDetail.can_view_suppliers !== 1 && (
                    <p className="muted">Sem permissão para consultar fornecedores.</p>
                  )}
                  {productDetail.can_view_suppliers === 1 && productDetail.suppliers.length === 0 && (
                    <p className="muted">Sem fornecedores associados.</p>
                  )}
                  {productDetail.can_view_suppliers === 1 && productDetail.suppliers.length > 0 && (
                    <div className="detail-list">
                      {productDetail.suppliers.map((supplier) => (
                        <article key={`${supplier.id}-${supplier.supplier_id}`} className="detail-item">
                          <div className="detail-item-main">
                            <p className="detail-item-title" title={supplier.supplier_name}>
                              {supplier.supplier_name}
                            </p>
                            <p className="detail-item-sub">
                              {supplier.supplier_ref ? `Ref. ${supplier.supplier_ref} • ` : ''}
                              Qtd. mín. {formatNumberCompact(supplier.min_qty)}
                            </p>
                          </div>
                          <p className="detail-item-value">{formatCurrency(supplier.unit_price_ht)}</p>
                        </article>
                      ))}
                    </div>
                  )}
                </section>

                <section className="product-detail-block">
                  <h4>Nutrição</h4>
                  {productDetail.kreaproducts_enabled !== 1 && (
                    <p className="muted">Módulo KreaProducts não está ativo.</p>
                  )}
                  {productDetail.kreaproducts_enabled === 1 && productDetail.nutrition === null && (
                    <p className="muted">Sem informação nutricional registada.</p>
                  )}
                  {productDetail.kreaproducts_enabled === 1 && productDetail.nutrition !== null && (
                    <div className="nutrition-grid">
                      <div className="nutrition-row">
                        <span>Energia (kcal)</span>
                        <strong>{formatOptionalNumber(productDetail.nutrition.energy_kcal, ' kcal')}</strong>
                      </div>
                      <div className="nutrition-row">
                        <span>Energia (kJ)</span>
                        <strong>{formatOptionalNumber(productDetail.nutrition.energy_kj, ' kJ')}</strong>
                      </div>
                      <div className="nutrition-row">
                        <span>Gordura</span>
                        <strong>{formatOptionalNumber(productDetail.nutrition.fat, ' g')}</strong>
                      </div>
                      <div className="nutrition-row">
                        <span>Saturados</span>
                        <strong>{formatOptionalNumber(productDetail.nutrition.saturates, ' g')}</strong>
                      </div>
                      <div className="nutrition-row">
                        <span>Carboidratos</span>
                        <strong>{formatOptionalNumber(productDetail.nutrition.carbohydrates, ' g')}</strong>
                      </div>
                      <div className="nutrition-row">
                        <span>Açúcares</span>
                        <strong>{formatOptionalNumber(productDetail.nutrition.sugars, ' g')}</strong>
                      </div>
                      <div className="nutrition-row">
                        <span>Proteína</span>
                        <strong>{formatOptionalNumber(productDetail.nutrition.protein, ' g')}</strong>
                      </div>
                      <div className="nutrition-row">
                        <span>Sal</span>
                        <strong>{formatOptionalNumber(productDetail.nutrition.salt, ' g')}</strong>
                      </div>
                      <div className="nutrition-row">
                        <span>Fibra</span>
                        <strong>{formatOptionalNumber(productDetail.nutrition.fiber, ' g')}</strong>
                      </div>
                    </div>
                  )}
                </section>

                <section className="product-detail-block">
                  <h4>Alergénios</h4>
                  {productDetail.kreaproducts_enabled !== 1 && (
                    <p className="muted">Módulo KreaProducts não está ativo.</p>
                  )}
                  {productDetail.kreaproducts_enabled === 1 && productDetail.allergens.length === 0 && (
                    <p className="muted">Sem alergénios registados.</p>
                  )}
                  {productDetail.kreaproducts_enabled === 1 && productDetail.allergens.length > 0 && (
                    <div className="allergen-list">
                      {productDetail.allergens.map((allergen) => (
                        <article key={`${allergen.id}-${allergen.code}`} className="allergen-chip">
                          {allergen.icon_url && (
                            <img src={allergen.icon_url} alt={allergen.code || allergen.label} loading="lazy" />
                          )}
                          <span className="allergen-text" title={allergen.label}>
                            {allergen.label || allergen.code}
                          </span>
                          {allergen.traces === 1 && <span className="allergen-trace">Traços</span>}
                        </article>
                      ))}
                    </div>
                  )}
                </section>
              </>
            )}
          </div>
        </section>
      )}

      {isEntityPickerOpen && (
        <section className="entity-picker-modal" role="dialog" aria-modal="true" aria-label="Selecionar entidade">
          <button type="button" className="entity-picker-backdrop" aria-label="Fechar seletor de entidade" onClick={closeEntityPicker} />
          <div className="entity-picker-sheet">
            <div className="entity-picker-header">
              <h3>Entidade</h3>
              <button type="button" className="ghost-btn" onClick={closeEntityPicker}>
                Fechar
              </button>
            </div>

            <div className="entity-picker-list">
              {session.available_entities.map((entity) => {
                const isActive = entity.id === session.entity;
                const isLoading = entitySwitchLoading === entity.id;

                return (
                  <button
                    key={entity.id}
                    type="button"
                    className={isActive ? 'entity-picker-item active' : 'entity-picker-item'}
                    onClick={() => void handleEntitySwitch(entity.id)}
                    disabled={isActive || entitySwitchLoading !== null}
                  >
                    <span className="entity-picker-label" title={entity.label}>
                      {entity.label}
                    </span>
                    <span className="entity-picker-meta">{isLoading ? 'A trocar...' : String(entity.id)}</span>
                  </button>
                );
              })}
            </div>

            {entitySwitchError && <p className="status-message">{entitySwitchError}</p>}
          </div>
        </section>
      )}

      {isDatePickerOpen && (
        <section className="wheel-picker-modal" role="dialog" aria-modal="true" aria-label="Selecionar data">
          <button
            type="button"
            className="wheel-picker-backdrop"
            aria-label="Fechar seletor de data"
            onClick={closeDatePicker}
          />
          <div className="wheel-picker-sheet">
            <div className="wheel-picker-header">
              <button type="button" className="ghost-btn wheel-picker-btn" onClick={closeDatePicker}>
                Cancelar
              </button>
              <h3>{getDatePickerTitle(activeDateScope, activeDateField)}</h3>
              <button type="button" className="btn wheel-picker-btn" onClick={applyDatePicker}>
                OK
              </button>
            </div>

            <p className="wheel-picker-preview">{formatDateLabel(toIsoDate(dateWheelValue))}</p>

            <Picker
              className="wheel-picker-control"
              value={dateWheelValue}
              onChange={handleDateWheelChange}
              height={216}
              itemHeight={44}
              wheelMode="natural"
            >
              <Picker.Column name="day">
                {dayOptions.map((day) => (
                  <Picker.Item key={day} value={day}>
                    {({ selected }) => <div className={selected ? 'wheel-item selected' : 'wheel-item'}>{day.padStart(2, '0')}</div>}
                  </Picker.Item>
                ))}
              </Picker.Column>

              <Picker.Column name="month">
                {monthOptions.map((monthEntry) => {
                  const monthValue = monthEntry.value;
                  return (
                    <Picker.Item key={monthValue} value={monthValue}>
                      {({ selected }) => <div className={selected ? 'wheel-item selected' : 'wheel-item'}>{monthEntry.label}</div>}
                    </Picker.Item>
                  );
                })}
              </Picker.Column>

              <Picker.Column name="year">
                {yearOptions.map((year) => (
                  <Picker.Item key={year} value={year}>
                    {({ selected }) => <div className={selected ? 'wheel-item selected' : 'wheel-item'}>{year}</div>}
                  </Picker.Item>
                ))}
              </Picker.Column>
            </Picker>
          </div>
        </section>
      )}
    </main>
  );
}
