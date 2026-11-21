/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
import { useEffect, useState } from 'react';
import { getKitchenDashboard, KitchenDashboard, KitchenItem } from './lib/api';

const duration = (seconds: number | null) => seconds === null ? '—' : `${Math.floor(seconds / 60)}m ${Math.floor(seconds % 60).toString().padStart(2, '0')}s`;
const labels: Record<string, string> = { waiting: 'Em espera', preparing: 'Em preparação', ready: 'Pronto', completed: 'Concluído', cancelled: 'Cancelado', unknown: 'Sem correspondência' };
const clock = (value: string | null) => value ? new Date(value).toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : 'Não recebida';

const kitchenError = (reason: unknown): string => {
  const message = reason instanceof Error ? reason.message : '';
  const translations: Record<string, string> = {
    'Kitchen integration is not configured for this company.': 'A integração da cozinha não está configurada para esta empresa.',
    'Kitchen integration configuration is invalid.': 'A configuração da integração da cozinha é inválida.',
    'KreaSynch kitchen is unavailable. Check the private connection and source binding.': 'A cozinha KreaSynch está indisponível. Verifique a ligação privada e a associação à origem dos dados.',
    'KreaSynch returned an invalid kitchen response.': 'O KreaSynch devolveu uma resposta inválida para a cozinha.',
    'Kitchen working-day range was not applied.': 'O período do dia de trabalho não foi aplicado à cozinha.',
    'DoliZSynch working-day configuration unavailable': 'A configuração do dia de trabalho no DoliZSynch está indisponível.',
    'Working-day boundary unavailable': 'Não foi possível determinar o início e o fim do dia de trabalho.',
    'Kitchen station configuration unavailable': 'A configuração dos postos da cozinha está indisponível.',
    'Kitchen company configuration unavailable': 'A configuração da empresa para a cozinha está indisponível.',
    'A empresa foi alterada. Volte a abrir o separador Cozinha.': 'A empresa foi alterada. Volte a abrir o separador Cozinha.',
  };
  if (Object.prototype.hasOwnProperty.call(translations, message)) return translations[message];
  return 'Não foi possível carregar os dados da cozinha. Verifique a ligação e as permissões de acesso; se a sessão tiver terminado, inicie sessão novamente.';
};

export function KitchenPanel({ entity }: { entity: number }) {
  const [data, setData] = useState<KitchenDashboard | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(true);
  const [refresh, setRefresh] = useState(0);
  const [status, setStatus] = useState('preparing');
  const [query, setQuery] = useState('');
  const [tick, setTick] = useState(Date.now());
  const [received, setReceived] = useState(0);
  useEffect(() => {
    let active = true;
    let timer: ReturnType<typeof setTimeout>;
    let loading = false;
    const load = async () => {
      if (!active || loading || document.hidden) return;
      loading = true; setBusy(true);
      try {
        const result = await getKitchenDashboard();
        if (active) {
          if (result.entity !== entity) throw new Error('A empresa foi alterada. Volte a abrir o separador Cozinha.');
          setData(result); setReceived(Date.now()); setTick(Date.now()); setError('');
        }
      } catch (reason) {
        if (active) { setData(null); setError(kitchenError(reason)); }
      } finally {
        loading = false;
        if (active) { setBusy(false); timer = setTimeout(() => void load(), 30000); }
      }
    };
    const visible = () => { if (!document.hidden) { clearTimeout(timer); void load(); } };
    void load();
    document.addEventListener('visibilitychange', visible);
    const ticker = setInterval(() => setTick(Date.now()), 1000);
    return () => { active = false; clearTimeout(timer); clearInterval(ticker); document.removeEventListener('visibilitychange', visible); };
  }, [entity, refresh]);
  const localAge = Math.max(0, (tick - received) / 1000);
  const sourceAge = data?.sampled_at ? Math.max(0, (Date.parse(data.generated_at) - Date.parse(data.sampled_at)) / 1000) : Infinity;
  const fresh = !!data?.fresh && localAge <= 90 && sourceAge + localAge <= 180;
  // Keep each complete observed order together; state/search select orders, not individual lines.
  const orderMap = new Map<string, KitchenItem[]>();
  for (const item of data?.items || []) {
    const key = JSON.stringify([item.order || `line:${item.id}`, item.table, item.zone]);
    const lines = orderMap.get(key) || [];
    lines.push(item); orderMap.set(key, lines);
  }
  const orders = [...orderMap.entries()].filter(([, lines]) =>
    (status === 'all' || lines.some(item => item.status === status)) &&
    lines.some(item => `${item.label} ${item.product} ${item.order} ${item.table} ${item.zone_name || item.zone || ''}`.toLowerCase().includes(query.toLowerCase())));
  const oldest = (lines: KitchenItem[]) => Math.max(-1, ...lines.map(item => item.elapsed_seconds ?? -1));
  orders.sort(([, a], [, b]) => oldest(b) - oldest(a));
  const timingReady = !!data?.lifecycle_verified && !!data?.timing_configured;
  const preparationCount = data?.counts.preparing ?? 0;
  return <section className="kitchen-panel" lang="pt-PT" aria-label="Painel da cozinha">
    <header className="kitchen-heading"><div><span className="kitchen-eyebrow">OPERAÇÕES · KREASYNCH</span><h1>Cozinha</h1><p>Trabalho em preparação e tempos registados</p></div>
      <button type="button" className="ghost-btn" disabled={busy} onClick={() => setRefresh(v => v + 1)}>{busy ? 'A atualizar…' : 'Atualizar'}</button></header>
    {error && <div role="alert" className="kitchen-notice"><strong>Cozinha indisponível</strong><p>{error}</p><p>Não é possível confirmar os totais da cozinha enquanto não existir ligação à origem dos dados.</p></div>}
    {!data && busy && <p role="status">A carregar os registos da cozinha…</p>}
    {data && <>
      <div className={`kitchen-freshness ${fresh ? 'is-fresh' : 'is-stale'}`}><strong>{fresh ? '● A receber atualizações' : '○ Dados desatualizados ou indisponíveis'}</strong><span>Recolha na origem {clock(data.sampled_at)} · receção {clock(data.received_at)}</span></div>
      {!fresh && <p className="kitchen-notice" role="status">Estes registos não permitem confirmar o que está em preparação neste momento. Os contadores de tempo ficam suspensos até serem recebidos dados atualizados.</p>}
      {!data.lifecycle_verified && <p className="kitchen-notice">Os códigos de estado da origem ainda não foram validados. Os totais em preparação e concluídos permanecem desconhecidos; os produtos registados continuam disponíveis abaixo.</p>}
      <div className="kitchen-kpis">
        <article><span>Registados em preparação</span><strong>{fresh && data.lifecycle_verified ? preparationCount : '—'}</strong><small>Linhas de produtos · postos configurados</small></article>
        <article><span>Registados em espera</span><strong>{fresh && data.supported_states?.includes('waiting') ? data.counts.waiting : '—'}</strong><small>{data.supported_states?.includes('waiting') ? 'Linhas de produtos · postos configurados' : 'A origem não fornece o estado de espera'}</small></article>
        <article><span>Tempo médio de preparação</span><strong>{duration(timingReady ? data.timings.average_seconds : null)}</strong><small>{data.timings.samples} linhas com tempo registado · dia de trabalho</small></article>
        <article><span>90% preparados em até</span><strong>{duration(timingReady ? data.timings.p90_seconds : null)}</strong><small>{data.timings.samples ? 'Percentil 90 · dia de trabalho' : 'Sem tempos de preparação validados'}</small></article>
      </div>
      <section className="kitchen-card"><div className="kitchen-heading"><div><h2>Quadro de preparação</h2><p>Dia de trabalho · {data.day} · {orders.length} pedidos</p></div></div>
        <div className="kitchen-filters">
          <label>Estado<select value={status} onChange={e => setStatus(e.target.value)}><option value="all">Todos os estados</option>{Object.entries(labels).map(([key, label]) => <option value={key} key={key}>{label}</option>)}</select></label>
          <label>Pesquisar produto ou pedido<input type="search" value={query} onChange={e => setQuery(e.target.value)} placeholder="Produto, pedido, mesa…" /></label>
        </div>
        {!orders.length && <p className="kitchen-empty">{data.items.length ? 'Nenhum registo corresponde a estes filtros.' : 'Ainda não foram recebidos registos da cozinha. Isto não significa que não existam pedidos.'}</p>}
        <div className="kitchen-board">{orders.map(([key, lines]) => {
          const first = lines[0];
          const active = lines.some(item => item.status === 'preparing');
          const elapsed = oldest(lines);
          const centres = [...new Set(lines.map(item => item.centre))];
          return <article className={`kitchen-ticket state-${active ? 'preparing' : first.status}`} key={key}>
            <div className="kitchen-ticket-top"><span>{active ? 'Em preparação' : labels[first.status] || 'Sem correspondência'}</span><strong>{active && fresh && elapsed >= 0 ? duration(elapsed + localAge) : '—'}</strong></div>
            <h3>Pedido {first.order || '—'} · Mesa {first.table || '—'}</h3>
            <p>Zona {first.zone_name || first.zone || 'indisponível'}</p>
            {centres.map(centre => <section className="kitchen-order-station" key={centre}>
              <h4>{lines.find(item => item.centre === centre)?.centre_name || `Posto ${centre}`}</h4>
              <ul>{lines.filter(item => item.centre === centre).map(item => <li key={item.id}>
                <span><b>{item.quantity === null ? '?' : item.quantity} ×</b> {item.label}</span>
                <small>{labels[item.status] || 'Sem correspondência'}</small>
              </li>)}</ul>
            </section>)}
            <footer><span>{lines.length} linhas de produtos registadas</span><span>{active ? 'Linha há mais tempo em preparação' : 'Dia de trabalho atual'}</span></footer>
          </article>;
        })}</div>
      </section>
      <section className="kitchen-card kitchen-quality"><h2>Cobertura dos dados</h2><p>Dados do dia de trabalho configurado, com base na entrada no posto de produção. Os tempos são medidos desde a entrada no posto até ao estado pronto, por linha e não por unidade. Os postos de impressão ou encaminhamento sem um ciclo de estados validado não contribuem para os totais de preparação. Os produtos repetidos com identificadores de origem distintos são mantidos; estes registos não representam a totalidade dos pedidos ativos.</p><p>{data.timings.samples} de {data.timings.eligible_lines} linhas prontas ou concluídas neste dia de trabalho têm tempos válidos. {data.counts.unknown} linhas têm estados sem correspondência.</p>
        {!data.timing_configured && <p>É necessário validar os campos do POS que indicam o início e o fim da preparação. As datas de atualização, por si só, não permitem calcular o tempo de preparação.</p>}
        {data.limited && <p role="alert">A resposta da origem atingiu o limite de 1 000 linhas. As contagens e as médias abrangem apenas os registos apresentados.</p>}
        <small>O painel verifica as atualizações a cada 30 segundos enquanto está visível. A recolha no POS tem um intervalo previsto de 30 segundos; o tempo total até à apresentação dos dados não é garantido.</small>
      </section>
    </>}
  </section>;
}
