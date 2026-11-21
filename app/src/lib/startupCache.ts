/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

import type { SalesDashboard, SessionData } from './api';

const DASHBOARD_CACHE_PREFIX = 'kreaflow:dashboard:v1';
const DASHBOARD_CACHE_MAX_AGE_MS = 12 * 60 * 60 * 1000;

interface DashboardCacheRecord {
  version: 1;
  stored_at: number;
  user_id: number;
  entity: number;
  date_start: string;
  date_end: string;
  top_products_page: number;
  data: SalesDashboard;
}

function buildDashboardCacheKey(
  session: SessionData,
  dateStart: string,
  dateEnd: string,
  topProductsPage: number
): string {
  return [
    DASHBOARD_CACHE_PREFIX,
    session.user.id,
    session.entity,
    dateStart,
    dateEnd,
    Math.max(1, Math.trunc(topProductsPage))
  ].join(':');
}

function isDashboardPayload(value: unknown): value is SalesDashboard {
  if (!value || typeof value !== 'object') {
    return false;
  }

  const data = value as Partial<SalesDashboard>;
  return (
    !!data.period &&
    typeof data.period.date_start === 'string' &&
    typeof data.period.date_end === 'string' &&
    !!data.kpis &&
    Array.isArray(data.daily_sales) &&
    Array.isArray(data.hourly_sales) &&
    Array.isArray(data.sales_by_payment_type) &&
    Array.isArray(data.top_products) &&
    Array.isArray(data.sales_by_employee)
  );
}

export function readDashboardCache(
  session: SessionData | null,
  dateStart: string,
  dateEnd: string,
  topProductsPage = 1
): SalesDashboard | null {
  if (!session || session.rights.dashboard_read !== 1) {
    return null;
  }

  const key = buildDashboardCacheKey(session, dateStart, dateEnd, topProductsPage);
  try {
    const raw = window.localStorage.getItem(key);
    if (!raw) {
      return null;
    }

    const record = JSON.parse(raw) as Partial<DashboardCacheRecord>;
    const isExpired =
      typeof record.stored_at !== 'number' || Date.now() - record.stored_at > DASHBOARD_CACHE_MAX_AGE_MS;
    const isWrongScope =
      record.version !== 1 ||
      record.user_id !== session.user.id ||
      record.entity !== session.entity ||
      record.date_start !== dateStart ||
      record.date_end !== dateEnd ||
      record.top_products_page !== Math.max(1, Math.trunc(topProductsPage));

    if (isExpired || isWrongScope || !isDashboardPayload(record.data)) {
      window.localStorage.removeItem(key);
      return null;
    }

    // Historical totals are revalidated against current server permissions.
    const { previous_year_sales: _previousYearSales, ...currentDashboard } = record.data;
    return currentDashboard;
  } catch {
    window.localStorage.removeItem(key);
    return null;
  }
}

export function writeDashboardCache(
  session: SessionData | null,
  dateStart: string,
  dateEnd: string,
  topProductsPage: number,
  data: SalesDashboard
): void {
  if (!session || session.rights.dashboard_read !== 1 || !isDashboardPayload(data)) {
    return;
  }

  const safePage = Math.max(1, Math.trunc(topProductsPage));
  const record: DashboardCacheRecord = {
    version: 1,
    stored_at: Date.now(),
    user_id: session.user.id,
    entity: session.entity,
    date_start: dateStart,
    date_end: dateEnd,
    top_products_page: safePage,
    data
  };

  try {
    const scopePrefix = `${DASHBOARD_CACHE_PREFIX}:${session.user.id}:${session.entity}:`;
    for (let index = window.localStorage.length - 1; index >= 0; index -= 1) {
      const existingKey = window.localStorage.key(index);
      if (existingKey?.startsWith(scopePrefix)) {
        window.localStorage.removeItem(existingKey);
      }
    }
    window.localStorage.setItem(buildDashboardCacheKey(session, dateStart, dateEnd, safePage), JSON.stringify(record));
  } catch {
    // Storage can be unavailable or full. Startup must continue without cache.
  }
}

export function clearDashboardCache(): void {
  try {
    for (let index = window.localStorage.length - 1; index >= 0; index -= 1) {
      const key = window.localStorage.key(index);
      if (key?.startsWith(`${DASHBOARD_CACHE_PREFIX}:`)) {
        window.localStorage.removeItem(key);
      }
    }
  } catch {
    // Logout and session cleanup must not fail because browser storage is unavailable.
  }
}
