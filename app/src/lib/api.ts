const DEFAULT_DATA_URL = '/custom/kreaflow/mobile.php';

declare global {
  interface Window {
    __KREAFLOW_BOOTSTRAP__?: SessionData;
    __KREAFLOW_DATA_URL__?: string;
  }
}

export class ApiError extends Error {
  status: number;
  details?: Record<string, unknown>;

  constructor(message: string, status: number, details?: Record<string, unknown>) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.details = details;
  }
}

type QueryValue = string | number | null | undefined;

let htmlEntityDecoder: HTMLTextAreaElement | null = null;

function decodeHtmlEntities(raw: string): string {
  if (!raw) {
    return raw;
  }

  if (typeof document === 'undefined') {
    return raw;
  }

  if (!htmlEntityDecoder) {
    htmlEntityDecoder = document.createElement('textarea');
  }

  let decoded = raw;
  for (let i = 0; i < 3; i += 1) {
    htmlEntityDecoder.innerHTML = decoded;
    const next = htmlEntityDecoder.value;
    if (next === decoded) {
      break;
    }
    decoded = next;
  }

  return decoded;
}

function normalizeApiMessage(message: string): string {
  if (!message) {
    return message;
  }

  const decoded = decodeHtmlEntities(message).replace(/\u00a0/g, ' ');

  const withoutTags = decoded.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
  return withoutTags || decoded;
}

function isAuthSessionLossMessage(message: string): boolean {
  const normalized = normalizeApiMessage(message).toLowerCase();
  if (!normalized) {
    return false;
  }

  const authLossPatterns = [
    /errornotlogged/,
    /not logged/,
    /unauthenticated/,
    /without being in an authenticated session/,
    /authenticated session/,
    /access denied/,
    /acesso negado/,
    /sess[aã]o autenticada/,
    /sem estar em uma sess[aã]o autenticada/,
    /not allowed to your user/,
    /m[oó]dulo desativado/,
    /disabled module/
  ];

  return authLossPatterns.some((pattern) => pattern.test(normalized));
}

function parseEnvelopeFromText<T>(text: string): ApiEnvelope<T> | null {
  try {
    return JSON.parse(text) as ApiEnvelope<T>;
  } catch {
    // Continue with best-effort extraction.
  }

  const start = text.indexOf('{');
  const end = text.lastIndexOf('}');
  if (start >= 0 && end > start) {
    const slice = text.slice(start, end + 1);
    try {
      return JSON.parse(slice) as ApiEnvelope<T>;
    } catch {
      return null;
    }
  }

  return null;
}

export interface ApiEnvelope<T> {
  success: boolean;
  data: T;
  error?: {
    message: string;
    details?: Record<string, unknown>;
  };
}

export interface SessionData {
  user: {
    id: number;
    login: string;
    fullname: string;
    email: string;
  };
  entity: number;
  entity_label: string;
  can_switch_entity: number;
  available_entities: SessionEntity[];
  rights: {
    api_read: number;
    dashboard_read: number;
    eats_read?: number;
    kitchen_read?: number;
    stock_forecast_read: number;
    products_read: number;
    workforce_schedule_read: number;
    workforce_punch_read: number;
    fecho_read: number;
    fecho_create: number;
    fecho_update: number;
    fecho_update_validated: number;
    fecho_update_old: number;
    fecho_edit_limit_days: number;
    history_readall: number;
    history_limit_days: number;
    facture_read: number;
    product_read: number;
  };
  module_info?: {
    gpl_notice: string;
    module: string;
    version: string;
    editor: string;
    license: string;
    support: string;
    website: string;
  };
  token: string;
}

export interface SessionEntity {
  id: number;
  label: string;
  current: number;
}

export interface LoginMeta {
  default_entity: number;
  entities: LoginEntity[];
  krea2factorauth?: {
    available: number;
    non_web_enforced: number;
    google_login_token: string;
    labels?: {
      login_code: string;
      login_hint: string;
      login_button: string;
      login_or: string;
      login_with_google: string;
    };
  };
}

export interface LoginEntity {
  id: number;
  label: string;
  krea2factorauth?: {
    enabled: number;
    login_logo_url: string;
    login_background_color: string;
    google_login_enabled: number;
    google_login_url: string;
  };
}

export interface ProductItem {
  id: number;
  ref: string;
  label: string;
  price_ht: number;
  price_ttc: number;
  tva_tx: number;
}

export interface ProductSupplier {
  id: number;
  supplier_id: number;
  supplier_name: string;
  supplier_ref: string;
  min_qty: number;
  unit_price_ht: number;
  total_price_ht: number;
  tva_tx: number;
  delivery_time_days: number | null;
}

export interface ProductNutrition {
  is_food: number;
  energy_kcal: number | null;
  energy_kj: number | null;
  fat: number | null;
  saturates: number | null;
  carbohydrates: number | null;
  sugars: number | null;
  protein: number | null;
  salt: number | null;
  fiber: number | null;
}

export interface ProductAllergen {
  id: number;
  code: string;
  label: string;
  icon: string;
  icon_url: string;
  traces: number;
}

export interface ProductDetail extends ProductItem {
  description: string;
  cost_price: number | null;
  can_view_suppliers: number;
  suppliers: ProductSupplier[];
  kreaproducts_enabled: number;
  nutrition: ProductNutrition | null;
  allergens: ProductAllergen[];
}

export interface ProductCategory {
  id: number;
  label: string;
  color: string;
  product_count: number;
}

export interface DashboardPeriod {
  date_start: string;
  date_end: string;
  day_count: number;
  chart_start?: string;
}

export interface DashboardKpis {
  total_revenue_ht: number;
  total_revenue_ttc: number;
  total_qty_sold: number;
  total_invoices: number;
  total_people: number;
  avg_per_day_ht: number;
  avg_per_day_ttc: number;
  avg_ticket_ht: number;
  avg_ticket_ttc: number;
}

export interface DailySale {
  sale_date: string;
  revenue_ht: number;
  revenue_ttc: number;
  qty_sold: number;
  invoice_count: number;
  people_count: number;
}

export interface HourlySale {
  hour_of_day: number;
  revenue_ttc: number;
}

export interface PaymentSale {
  paymenttype_name: string;
  transaction_count: number;
  total_liquido: number;
  total_gross: number;
}

export interface TopProduct {
  product_ref: string;
  product_label: string;
  qty_sold: number;
  revenue_ht: number;
  revenue_ttc: number;
}

export interface EmployeeSale {
  employee_name: string;
  transaction_count: number;
  total_quantity_sold: number;
  total_sales_value_ht: number;
  total_sales_value_ttc: number;
}

export interface WorkforcePeriod {
  week_start: string;
  week_end: string;
  day: string;
  punch_date: string;
  day_start_minutes: number;
  day_end_minutes: number;
}

export interface WorkforcePermissions {
  schedule_read: number;
  punch_read: number;
}

export interface WorkforceResource {
  id: number;
  title: string;
  expected_hours: number | null;
  weekly_hours: number | null;
  date_employment: string;
  date_employment_end: string;
  main_task_id: number | null;
  main_task_label: string;
}

export interface WorkforceEvent {
  id: number;
  resource_id: number;
  fk_task: number | null;
  task_label: string;
  title: string;
  start: string;
  end: string;
  color: string;
  entity: number;
  entity_label: string;
  foreign_entity: number;
}

export interface WorkforcePunchRow {
  id: number;
  empid_zs: number;
  fk_user: number;
  employee_name: string;
  dataval: string;
  datahora_in: string;
  datahora_out: string;
  duration_seconds: number;
  duration_label: string;
  real_entry: number;
}

export interface WorkforceDayOff {
  id: number;
  resource_id: number;
  date_iso: string;
  is_recurring: number;
  description: string;
  entry_type: 'dayoff' | 'lockday';
  is_lock: number;
  entity: number;
  entity_label: string;
  foreign_entity: number;
}

export interface WorkforceLeave {
  id: number;
  resource_id: number;
  type_id: number;
  type_label: string;
  type_code: string;
  color: string;
  start_iso: string;
  end_iso: string;
  description: string;
  halfday: number;
  is_hours: number;
  minutes_only: number;
  hours_range: string;
  hours_start: string;
  hours_end: string;
  hours_total_minutes: number;
  entity: number;
  entity_label: string;
  foreign_entity: number;
}

export interface WorkforcePublicHoliday {
  date: string;
  name: string;
}

export interface WorkforceBoard {
  period: WorkforcePeriod;
  permissions: WorkforcePermissions;
  resources: WorkforceResource[];
  events: WorkforceEvent[];
  punch_rows: WorkforcePunchRow[];
  dayoffs: WorkforceDayOff[];
  leaves: WorkforceLeave[];
  public_holidays: WorkforcePublicHoliday[];
}

export interface SalesDashboard {
  period: DashboardPeriod;
  kpis: DashboardKpis;
  previous_year_sales?: {
    comparison_mode?: 'operational' | 'calendar';
    date_start: string;
    date_end: string;
    status: 'available' | 'empty' | 'restricted';
    revenue_ht?: number;
    revenue_ttc?: number;
  };
  daily_sales: DailySale[];
  hourly_sales: HourlySale[];
  sales_by_payment_type: PaymentSale[];
  top_products: TopProduct[];
  top_products_total: number;
  top_products_page: number;
  top_products_page_size: number;
  top_products_pages: number;
  sales_by_employee: EmployeeSale[];
}

export interface SalesForecastPeriod {
  date_start: string;
  date_end: string;
  day_count: number;
  chart_start: string;
  chart_days: number;
  training_start: string;
  training_end: string;
  training_days: number;
  forecast_days: number;
}

export interface SalesForecastKpis {
  historical_total_ht: number;
  historical_total_ttc: number;
  historical_avg_ht: number;
  historical_avg_ttc: number;
  forecast_total_ht: number;
  forecast_total_ttc: number;
  forecast_avg_ht: number;
  forecast_avg_ttc: number;
}

export interface SalesForecastChartRow {
  date: string;
  revenue_ttc: number;
  series: 'historical' | 'forecast';
}

export interface SalesForecastRow {
  date: string;
  forecast_ht: number;
  forecast_ttc: number;
  temp_mean: number | null;
  precip_mm: number;
  precip_intensity: string;
  precip_probability: string;
  precip_probability_pct: number | null;
}

export interface ForecastAccuracyMetrics {
  samples: number;
  mae: number | null;
  bias: number | null;
  wape_pct: number | null;
  limited_sample: boolean;
  weekday?: number;
  horizon?: number;
}

export interface ForecastAccuracy {
  status: 'available' | 'empty' | 'unavailable' | 'restricted';
  labels?: Record<string, string> & { days: string[] };
  by_weekday?: ForecastAccuracyMetrics[];
  by_horizon?: ForecastAccuracyMetrics[];
  last_issued_at?: string | null;
  last_actual_date?: string | null;
  missing_actuals?: number;
}

export interface SalesForecast {
  accuracy?: ForecastAccuracy;
  period: SalesForecastPeriod;
  kpis: SalesForecastKpis;
  chart_rows: SalesForecastChartRow[];
  forecast_rows: SalesForecastRow[];
  meta: {
    model: string;
    has_weather_history: number;
    has_weather_forecast: number;
  };
}

export interface StockForecastDailyRow {
  date: string;
  forecast_sales_ttc: number;
  units_per_euro: number;
  required_quantity: number;
}

export interface StockForecastDelivery {
  delivery_date: string;
  covered_dates: string[];
  required_quantity: number;
}

export interface StockForecastProduct {
  product_id: number;
  ref: string;
  label: string;
  counted_stock: number;
  inventory_snapshot_stock: number;
  current_stock: number;
  forecast_required: number;
  projected_stock: number;
  shortage: number;
  next_delivery_date: string | null;
  next_delivery_required: number;
  next_delivery_projected_stock: number;
  next_delivery_shortage: number;
  overall_units_per_euro: number;
  daily: StockForecastDailyRow[];
  deliveries: StockForecastDelivery[];
}

export interface StockForecast {
  category: {
    id: number;
    label: string;
  };
  inventory: {
    id: number;
    ref: string;
    date_inventory: number;
    warehouse_id: number;
    warehouse_ref: string;
    snapshot_time: string;
  };
  period: {
    history_start: string;
    history_end: string;
    history_days: number;
    forecast_days: number;
  };
  summary: {
    product_count: number;
    at_risk_products: number;
    horizon_at_risk_products: number;
    forecast_sales_ttc: number;
  };
  products: StockForecastProduct[];
  meta: {
    model: string;
    safety_stock_included: number;
    planned_receipts_included: number;
    delivery_weekdays: number[];
    no_delivery_weekdays: number[];
  };
}

export interface FechoFinanceiroPeriod {
  date_start: string;
  date_end: string;
  limit_date: string;
}

export interface FechoFinanceiroTotals {
  total_sem_iva: number;
  total_talao_caixa: number;
}

export interface FechoFinanceiroItem {
  id: number;
  ref: string;
  datecx: string;
  store_id: number;
  store_label: string;
  responsavel_id: number;
  responsavel_label: string;
  total_sem_iva: number;
  val_talao_caixa: number;
  status: number;
  status_label: string;
}

export interface FechoFinanceiroList {
  period: FechoFinanceiroPeriod;
  count: number;
  totals: FechoFinanceiroTotals;
  rows: FechoFinanceiroItem[];
}

export interface FechoMetaOption {
  id: number;
  label: string;
}

export interface FechoFinanceiroMeta {
  permissions: {
    can_create: number;
    can_update: number;
    can_update_validated: number;
    can_update_old: number;
    edit_limit_days: number;
    can_write: number;
    can_change_store: number;
  };
  defaults: {
    datecx: string;
    fk_loja: number;
    responsavel: number;
  };
  stores: FechoMetaOption[];
  users: FechoMetaOption[];
}

export interface FechoFinanceiroMetrics {
  val_talao_caixa: number;
  diferenca: number;
  total_sem_iva_posted: number;
  soma_talao: number;
  credito_liquido: number;
  recebido_esperado: number;
  soma_notas: number;
  soma_numerario: number;
  soma_tpa: number;
  soma_total_valores: number;
  total_sem_iva_calc: number;
  total_com_iva_calc: number;
  diferenca_calculada: number;
  tesouraria_balance: number;
}

export interface FechoFinanceiroValues {
  val_talao_caixa: number;
  tal_numerario: number;
  tal_mb: number;
  tal_ubereats: number;
  tal_glovo: number;
  tal_comermelhor: number;
  tal_factcredito: number;
  tal_tickets: number;
  tal_notacredito: number;
  val_moedas: number;
  val_005: number;
  val_010: number;
  val_020: number;
  val_050: number;
  val_100: number;
  val_200: number;
  val_500: number;
  tpa_1: number;
  tpa_2: number;
  tpa_3: number;
  tpa_4: number;
  tpa_5: number;
  tpa_6: number;
  val_tickets: number;
  tips: number;
  despesas: number;
  diferenca: number;
  total_isen: number;
  total_redu: number;
  total_inte: number;
  total_norm: number;
  total_sem_iva: number;
}

export interface FechoFinanceiroDetail {
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
  values: FechoFinanceiroValues;
  metrics: FechoFinanceiroMetrics;
  can_edit: number;
}

export interface FechoFinanceiroSavePayload {
  datecx: string;
  fk_loja: number;
  responsavel: number;
  description: string;
  values: FechoFinanceiroValues;
}

function resolveDataUrl(): string {
  const envUrl = import.meta.env.VITE_KREAFLOW_API_ROOT as string | undefined;
  if (envUrl && envUrl.trim() !== '') {
    return envUrl;
  }

  const bootstrappedUrl = window.__KREAFLOW_DATA_URL__;
  if (bootstrappedUrl && bootstrappedUrl.trim() !== '') {
    return bootstrappedUrl;
  }

  return DEFAULT_DATA_URL;
}

function buildActionUrl(action: string, query: Record<string, QueryValue> = {}): string {
  const baseUrl = resolveDataUrl();
  const url = new URL(baseUrl, window.location.origin);
  url.searchParams.set('kf_action', action);

  Object.entries(query).forEach(([key, value]) => {
    if (value === null || value === undefined || value === '') {
      return;
    }
    url.searchParams.set(key, String(value));
  });

  return url.toString();
}

async function request<T>(action: string, query: Record<string, QueryValue> = {}, init?: RequestInit): Promise<T> {
  const response = await fetch(buildActionUrl(action, query), {
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      ...(init?.body ? { 'Content-Type': 'application/json' } : {})
    },
    ...init
  });

  const text = await response.text();
  const envelope = parseEnvelopeFromText<T>(text);

  if (!envelope) {
    const fallbackMessage = normalizeApiMessage(text);
    if (fallbackMessage) {
      const mappedStatus = isAuthSessionLossMessage(fallbackMessage) ? 401 : response.status;
      throw new ApiError(fallbackMessage, mappedStatus);
    }
    throw new ApiError(`Resposta API inválida (${response.status})`, response.status);
  }

  if (!response.ok || !envelope.success) {
    const normalizedMessage = normalizeApiMessage(envelope.error?.message || `Pedido falhou (${response.status})`);
    const mappedStatus = isAuthSessionLossMessage(normalizedMessage) ? 401 : response.status;
    throw new ApiError(
      normalizedMessage,
      mappedStatus,
      envelope.error?.details
    );
  }

  return envelope.data;
}

function postRequest<T>(action: string, body: unknown, query: Record<string, QueryValue> = {}): Promise<T> {
  return request<T>(action, query, {
    method: 'POST',
    body: JSON.stringify(body)
  });
}

export function getBootstrapSession(): SessionData | null {
  if (!window.__KREAFLOW_BOOTSTRAP__ || typeof window.__KREAFLOW_BOOTSTRAP__ !== 'object') {
    return null;
  }
  return window.__KREAFLOW_BOOTSTRAP__;
}

export function getWhoAmI(): Promise<SessionData> {
  return request<SessionData>('whoami');
}

export function getLoginMeta(): Promise<LoginMeta> {
  return request<LoginMeta>('login_meta');
}

export function loginWithCredentials(username: string, password: string, entity: number, totpCode = ''): Promise<SessionData> {
  return postRequest<SessionData>('login', {
    username,
    password,
    entity,
    totp_code: totpCode
  });
}

export function logoutSession(): Promise<{ logged_out: number }> {
  return postRequest<{ logged_out: number }>('logout', {});
}

export function getDashboard(dateStart: string, dateEnd: string, topProductsPage = 1): Promise<SalesDashboard> {
  return request<SalesDashboard>('dashboard', {
    date_start: dateStart,
    date_end: dateEnd,
    top_products_page: topProductsPage
  });
}

export function getSalesForecast(dateStart: string, dateEnd: string): Promise<SalesForecast> {
  return request<SalesForecast>('sales_forecast', {
    date_start: dateStart,
    date_end: dateEnd
  });
}

export function getStockForecast(dateStart: string, dateEnd: string): Promise<StockForecast> {
  return request<StockForecast>('stock_forecast', {
    date_start: dateStart,
    date_end: dateEnd
  });
}

export function getWorkforceBoard(weekStart: string, day: string, punchDate: string): Promise<WorkforceBoard> {
  return request<WorkforceBoard>('workforce', {
    week_start: weekStart,
    day,
    punch_date: punchDate
  });
}

export function getProductCategories(): Promise<ProductCategory[]> {
  return request<ProductCategory[]>('product_categories');
}

export function getFechoFinanceiroList(
  dateStart: string,
  dateEnd: string,
  status = -1,
  search = '',
  limit = 80
): Promise<FechoFinanceiroList> {
  return request<FechoFinanceiroList>('fechofinanceiro', {
    date_start: dateStart,
    date_end: dateEnd,
    status,
    search,
    limit
  });
}

export function getFechoMeta(): Promise<FechoFinanceiroMeta> {
  return request<FechoFinanceiroMeta>('fecho_meta');
}

export function getFechoDetail(id: number): Promise<FechoFinanceiroDetail> {
  return request<FechoFinanceiroDetail>('fechofinanceiro_detail', { id });
}

export function createFecho(payload: FechoFinanceiroSavePayload): Promise<FechoFinanceiroDetail> {
  return postRequest<FechoFinanceiroDetail>('fechofinanceiro_create', payload);
}

export function updateFecho(id: number, payload: FechoFinanceiroSavePayload): Promise<FechoFinanceiroDetail> {
  return postRequest<FechoFinanceiroDetail>('fechofinanceiro_update', { ...payload, id }, { id });
}

export function searchProducts(query: string, limit = 50, categoryId = 0): Promise<ProductItem[]> {
  return request<ProductItem[]>('products', {
    q: query,
    limit,
    category_id: categoryId > 0 ? categoryId : undefined
  });
}

export function getProductDetail(productId: number): Promise<ProductDetail> {
  return request<ProductDetail>('product_detail', {
    id: productId
  });
}

export type EatsWeek = {
  entity: number; token: string; analysis_access: boolean; analysis_available: boolean;
  period: { start: string; end: string; limited: boolean; previous: boolean; next: boolean };
  economics: { has_data: boolean; sales: number; fees: number; net: number; adjustments: number; fee_rate: number | null; commission_rate: number | null; retained_rate: number | null; difference: number };
  costs: { known_cost: number | null; profit: number | null; matched: number; total: number; partial: boolean; state: string };
  analytics: { invoices: number; sales: number; average: number | null; can_products: boolean; days: Array<{ date: string; sales: number; invoices: number }>; products: Array<{ rowid: number; ref: string; label: string; quantity: number; amount: number }> };
  payouts: Array<{ id: number; start: string; end: string; state: string }>;
  excluded_dates: number;
};
export function getEatsWeek(week: string, metric: string): Promise<EatsWeek> {
  return request<EatsWeek>('eats_week', { week, metric });
}

export type EatsAnalysis = {
  sections: Record<'summary' | 'performance' | 'products' | 'weather' | 'reconciliation' | 'actions', string>;
  model: string; generated_at: string;
  weather: { state: string; location: string | null; days: Array<{ date: string; temperature_c: number | null; rain_mm: number | null }> };
};
export function analyseEatsWeek(week: string, entity: number, token: string): Promise<EatsAnalysis> {
  return postRequest<EatsAnalysis>('eats_analysis', { week, entity, token });
}

export type KitchenItem = {
  id: string; order: string; table: string; zone?: string; zone_name?: string; product: string; label: string;
  quantity: number | null; centre: string; centre_name?: string; status: string; raw_status: string;
  elapsed_seconds: number | null; duration_seconds: number | null; started_at: string | null;
};
export type KitchenDashboard = {
  entity: number; generated_at: string; sampled_at: string | null; received_at: string | null;
  fresh: boolean; limited: boolean; supported_states?: string[]; lifecycle_verified: boolean; timing_configured: boolean;
  complete_queue: boolean; window_hours: number; note: string; day: string; timezone: string;
  stations: Array<{id: string; name: string}>;
  counts: Record<string, number>; items: KitchenItem[];
  products: Array<{ product: string; label: string; centre: string; centre_name?: string; preparing_lines: number;
    preparing_quantity: number; unknown_quantities: number; timed_lines: number; average_seconds: number | null }>;
  timings: { samples: number; eligible_lines: number; average_seconds: number | null; p90_seconds: number | null };
};
export function getKitchenDashboard(): Promise<KitchenDashboard> {
  return request<KitchenDashboard>('kitchen', {});
}
