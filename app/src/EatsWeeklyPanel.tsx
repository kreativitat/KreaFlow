/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
import { useEffect, useState } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { EatsWeek, EatsAnalysis, analyseEatsWeek, getEatsWeek } from './lib/api';

const money = (cents: number | null) => cents === null ? '—' : new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR' }).format(cents / 100);
const dateLabel = (date: string) => new Date(`${date}T12:00:00`).toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit' });
const weekday = (date: string) => ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'][new Date(`${date}T12:00:00`).getDay()];
const shift = (date: string, days: number) => { const d = new Date(`${date}T12:00:00`); d.setDate(d.getDate() + days); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; };
const states: Record<string, string> = { pending: 'Aguarda pagamento', review: 'Por conciliar', investigate: 'Investigar', matched: 'Conciliado', explained: 'Diferença justificada', stale: 'Comprovativos alterados' };

type Props = { date: string; onDateChange: (date: string) => void; onPickDate: () => void };
export function EatsWeeklyPanel({ date, onDateChange, onPickDate }: Props) {
  const [data, setData] = useState<EatsWeek | null>(null);
  const [metric, setMetric] = useState('amount');
  const [refresh, setRefresh] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  useEffect(() => {
    let active = true;
    setData(null); setLoading(true); setError('');
    getEatsWeek(date, metric).then(value => { if (active) setData(value); }).catch(reason => { if (active) setError(reason instanceof Error ? reason.message : 'Não foi possível carregar a semana.'); }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [date, metric, refresh]);
  const e = data?.economics;
  const costs = data?.costs;
  const dayMap = new Map(data?.analytics.days.map(d => [d.date, d]) || []);
  const days = data ? Array.from({ length: 7 }, (_, i) => shift(data.period.start, i)).filter(d => d <= data.period.end).map(d => ({ date: d, sales: dayMap.get(d)?.sales ?? 0 })) : [];
  return <section className="eats-week" aria-label="UberEats semanal">
    <header className="eats-heading"><div><h1>UberEats · Semana</h1><p>Vendas, encargos, produtos e lucro estimado da empresa ativa.</p></div><button type="button" onClick={() => setRefresh(v => v+1)} disabled={loading}>Atualizar</button></header>
    <div className="eats-week-picker">
      <button type="button" aria-label="Semana anterior" disabled={loading || !data?.period.previous} onClick={() => onDateChange(shift(data!.period.start, -7))}>←</button>
      <button type="button" className="date-trigger" onClick={onPickDate}>{data ? `${dateLabel(data.period.start)} — ${dateLabel(data.period.end)} · ${data.period.end.slice(0,4)}` : 'Escolher semana'}</button>
      <button type="button" aria-label="Semana seguinte" disabled={loading || !data?.period.next} onClick={() => onDateChange(shift(data!.period.start, 7))}>→</button>
    </div>
    {loading && <p role="status">A carregar a semana…</p>}
    {error && <p role="alert" className="status-message">{error}</p>}
    {data && e && costs && <>
      {data.period.limited && <p className="muted">Semana parcial: mostram-se apenas as datas disponíveis dentro do seu acesso e até hoje.</p>}
      {!e.has_data && <p className="status-message">Sem movimentos Uber importados nesta semana. Isto não significa ausência de vendas.</p>}
      <div className="eats-kpis">
        <article><span>Vendas Uber</span><strong>{money(e.has_data ? e.sales : null)}</strong><small>Após descontos · com IVA</small></article>
        <article><span>Comissões e taxas</span><strong>{money(e.has_data ? e.fees : null)}</strong><small>{e.fee_rate === null ? '—' : `${(e.fee_rate/100).toLocaleString('pt-PT', {minimumFractionDigits:2,maximumFractionDigits:2})} %`} das vendas</small></article>
        <article><span>A receber</span><strong>{money(e.has_data ? e.net : null)}</strong><small>Movimentos Uber desta semana</small></article>
        <article className="eats-profit"><span>Lucro estimado</span><strong>{money(costs.profit)}</strong><small>{costs.state === 'forbidden' ? 'Sem permissão para consultar custos' : `${money(e.has_data ? e.net : null)} − ${money(costs.known_cost)}`}</small>{costs.partial && <small>Estimativa com custos incompletos</small>}</article>
      </div>
      {data.analysis_available && <EatsAnalysisPanel key={`${data.entity}:${data.period.start}:${refresh}`} week={data} />}
      <div className="eats-panels">
        <section className="eats-card"><h2>Das vendas ao lucro</h2>{[
          ['Vendas', e.has_data ? e.sales : null], ['− Comissões e taxas', e.has_data ? e.fees : null],
          ['± Acertos', e.has_data ? e.adjustments : null], ['= A receber', e.has_data ? e.net : null],
          ['− Custos dos produtos', costs.known_cost], ['= Lucro estimado', costs.profit]
        ].map(([label,value]) => <div className="eats-equation" key={String(label)}><span>{label}</span><strong>{money(value as number | null)}</strong></div>)}
          <p className="muted">Lucro = valor a receber − custos registados dos produtos faturados nas vendas associadas. Comissões e acertos já estão incluídos no valor a receber.</p>
          {costs.partial && <p className="muted">{costs.matched}/{costs.total} vendas associadas. Custos em falta e vendas sem associação não têm custo deduzido; o lucro pode estar sobreavaliado.</p>}
          {e.difference !== 0 && <p role="alert">Diferença no relatório: {money(e.difference)}. Reveja no UberEats.</p>}
        </section>
        <section className="eats-card"><h2>Vendas por dia</h2><p className="muted">Dolibarr · {data.analytics.invoices} faturas · {money(data.analytics.sales)}<br/>Valor médio: {money(data.analytics.average)}</p>
          <div className="eats-chart"><ResponsiveContainer width="100%" height={210}><BarChart data={days} margin={{ left: -22, right: 0, bottom: 4 }}><CartesianGrid stroke="var(--kf-border)" strokeDasharray="3 3" vertical={false}/><XAxis dataKey="date" tickFormatter={weekday} interval={0} tick={{ fontSize: 10 }}/><YAxis tickFormatter={v => `${v/100}€`} tick={{ fontSize: 10 }}/><Tooltip formatter={v => money(Number(v))} labelFormatter={v => `${weekday(String(v))} · ${dateLabel(String(v))}`}/><Bar isAnimationActive={false} dataKey="sales" name="Vendas" fill="var(--kf-chart-bar)" radius={[4,4,0,0]}/></BarChart></ResponsiveContainer></div>
          <details><summary>Ver valores diários</summary>{days.map(day => <div className="eats-equation" key={day.date}><span>{weekday(day.date)} · {dateLabel(day.date)}</span><strong>{money(day.sales)}</strong></div>)}</details>
        </section>
      </div>
      <section className="eats-card"><div className="eats-heading"><h2>Produtos mais vendidos</h2><div className="eats-sort"><button type="button" aria-pressed={metric==='amount'} onClick={() => setMetric('amount')}>Valor</button><button type="button" aria-pressed={metric==='quantity'} onClick={() => setMetric('quantity')}>Unidades</button></div></div>
        <p className="muted">Top 10 · vendas Uber do Dolibarr nestas datas, incluindo vendas sem associação. Componentes de menu sem valor faturado excluídos.</p>
        {!data.analytics.can_products ? <p>Sem permissão para consultar produtos.</p> : !data.analytics.products.length ? <p>Sem produtos registados nesta semana.</p> : <ol className="eats-products">{data.analytics.products.map(product => <li key={product.rowid}><div><a href={`/product/card.php?id=${product.rowid}`}>{product.label}</a><small>{product.ref}</small><div className="eats-track"><span style={{ width: `${100*(metric==='amount' ? product.amount : product.quantity)/Math.max(1,...data.analytics.products.map(p => metric==='amount' ? p.amount : p.quantity))}%` }}/></div></div><strong>{metric==='amount' ? money(product.amount) : `${Number(product.quantity)} un.`}<small>{metric==='amount' ? `${Number(product.quantity)} un.` : money(product.amount)}</small></strong></li>)}</ol>}
      </section>
      <section className="eats-card"><h2>Conciliação</h2><p className="muted">Os totais acima seguem as datas dos movimentos, de segunda-feira a domingo. Uma transferência pode abranger várias semanas. O estado abaixo refere-se à transferência completa.</p>
        {data.payouts.map(payout => <a className="eats-payout" key={payout.id} href={`/custom/kreaeats/kreaeatsindex.php?mainmenu=billing&leftmenu=&id=${payout.id}`}><span>{dateLabel(payout.start)} — {dateLabel(payout.end)}</span><strong>{states[payout.state] || payout.state} →</strong></a>)}
        {data.excluded_dates > 0 && <p>Há movimentos sem data válida que não foram atribuídos a esta semana.</p>}
      </section>
    </>}
  </section>;
}


function EatsAnalysisPanel({ week }: { week: EatsWeek }) {
  const [analysis, setAnalysis] = useState<EatsAnalysis | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    if (!week.analysis_available || !week.economics.has_data) return;
    let active = true;
    setBusy(true); setError(''); setAnalysis(null);
    analyseEatsWeek(week.period.start, week.entity, week.token)
      .then(value => { if (active) setAnalysis(value); })
      .catch(reason => { if (active) setError(reason instanceof Error ? reason.message : 'Análise indisponível.'); })
      .finally(() => { if (active) setBusy(false); });
    return () => { active = false; };
    // The parent remounts on entity, week or refresh; changing product ranking does not request another analysis.
  }, [attempt]);
  const sections: Array<[keyof EatsAnalysis['sections'], string]> = [['performance','Vendas e lucro'],['products','Produtos'],['weather','Tempo e procura'],['reconciliation','O que falta conciliar'],['actions','Prioridades para a próxima semana']];
  return <section className="eats-card eats-analysis" aria-label="Análise da semana">
    <div className="eats-heading"><div><span className="eats-ai-label">LEITURA DA SEMANA · IA</span><h2>Análise do negócio</h2></div>{error && <button type="button" disabled={busy} onClick={() => setAttempt(v => v+1)}>Consultar resultado</button>}</div>
    {busy && <p role="status">A analisar vendas, lucro, produtos, conciliação e tempo desta semana…</p>}
    {error && <p role="alert">{error}</p>}
    {!week.analysis_access ? <p>Sem permissão para utilizar o assistente de IA.</p> : !week.analysis_available ? <p>Configure o serviço de IA nas definições do KreaProducts.</p> : !week.economics.has_data ? <p>Importe os relatórios Uber desta semana para obter uma análise.</p> : null}
    {analysis && <>
      <p className="eats-analysis-summary">{analysis.sections.summary}</p>
      <div className="eats-analysis-grid">{sections.map(([key,title]) => <article key={key} className={key==='actions' ? 'eats-analysis-actions' : ''}><h3>{title}</h3><p>{analysis.sections[key]}</p></article>)}</div>
      <details><summary>Tempo diário · {analysis.weather.location || 'Localização indisponível'} · {analysis.weather.state === 'available' ? 'Histórico disponível' : analysis.weather.state === 'partial' ? 'Histórico parcial' : 'Histórico indisponível'}</summary>
        {analysis.weather.days.map(day => <div className="eats-weather-day" key={day.date}><span>{weekday(day.date)} · {dateLabel(day.date)}</span><span>{day.temperature_c === null ? '—' : `${day.temperature_c.toLocaleString('pt-PT')} °C`}</span><span>{day.rain_mm === null ? '—' : `${day.rain_mm.toLocaleString('pt-PT')} mm`}</span></div>)}
        <p className="muted"><a href="https://open-meteo.com/en/docs/historical-weather-api" target="_blank" rel="noreferrer">Open-Meteo</a> · temperatura média e precipitação total · Europe/Lisbon. Reanálise meteorológica; os dias recentes podem ainda estar incompletos. A coincidência entre chuva e vendas não demonstra uma relação de causa.</p>
      </details>
      <p className="muted">Guardada em {new Date(analysis.generated_at).toLocaleString('pt-PT')} · {analysis.model}. Reutilizada enquanto os dados de referência não mudarem. Revise as sugestões antes de agir. Os dados incompletos limitam as conclusões; a análise não altera a conciliação.</p>
    </>}
  </section>;
}
