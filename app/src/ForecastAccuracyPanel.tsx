/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
import type { ForecastAccuracy } from './lib/api';

export function ForecastAccuracyPanel({ data }: { data?: ForecastAccuracy }) {
  if (!data?.labels || data.status === 'restricted') return null;
  const labels = data.labels;
  const number = (value: number | null, unit: string) => value === null ? '—' : `${value.toLocaleString(undefined, { maximumFractionDigits: 2 })}${unit}`;
  return <article className="card forecast-accuracy">
    <h3>{labels.title}</h3>
    <p className="muted">{labels.description}</p>
    {data.status !== 'available' ? <p>{labels[data.status]}</p> : <>
      <p>{labels.freshness}: {data.last_issued_at || '—'} / {data.last_actual_date || '—'}</p>
      <p>{labels.missing}: {data.missing_actuals || 0}</p>
      {(['by_weekday', 'by_horizon'] as const).map(group => <section key={group}>
        <h4>{group === 'by_weekday' ? labels.weekdays : labels.horizons}</h4>
        <div className="forecast-accuracy-grid">
          {(data[group] || []).map(row => <div className="forecast-accuracy-item" key={row.weekday ?? row.horizon}>
            <strong>{row.weekday ? labels.days[row.weekday - 1] : `${labels.lead} ${row.horizon}`}</strong>
            <p>{labels.samples}: {row.samples}</p>
            <p>{labels.mae}: {number(row.mae, ' €')}</p>
            <p>{labels.bias}: {number(row.bias, ' €')}</p>
            <p>{labels.wape}: {number(row.wape_pct, '%')}</p>
            {row.limited_sample && <small>{labels.limited}</small>}
          </div>)}
        </div>
      </section>)}
      <p className="muted">{labels.caution}</p>
    </>}
  </article>;
}
