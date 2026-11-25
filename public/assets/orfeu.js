'use strict';

const byId = (id) => document.getElementById(id);
const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const percent = new Intl.NumberFormat('pt-BR', { style: 'percent', maximumFractionDigits: 2 });
const time = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Sao_Paulo' });
const amount = (cents) => cents == null ? '—' : currency.format(Number(cents) / 100);
const pct = (fraction) => fraction == null ? '—' : percent.format(fraction);
const dateTime = (value) => value ? time.format(new Date(value.replace(' ', 'T') + 'Z')) : '—';
const set = (id, value) => { byId(id).textContent = value; };
let tradeRows = [];
let curve = [];
const titles = {
  ORFEU_AI: 'Ensemble preditivo', MULTIHORIZON: 'Consenso de horizontes', MOMENTUM: 'Tendência EMA',
  RELATIVE_STRENGTH: 'Força transversal', MEAN_REVERSION: 'Reversão à média', BREAKOUT: 'Donchian e volume',
  VWAP_RECLAIM: 'Recuperação do VWAP', QUALITY_VALUE: 'Qualidade e valor', EARNINGS_DRIFT: 'Pós-resultado',
  LOW_VOL: 'Baixa volatilidade', ADAPTIVE_REGIME: 'Regime adaptativo', DIVIDEND_QUALITY: 'Dividendos sustentáveis',
  MACRO_ROTATION: 'Rotação macro', PORTFOLIO_ROTATION: 'Rotação de carteira', META_CHAMPION: 'Seleção de sinais'
};

async function api(path, signal) {
  const response = await fetch('api/v1/' + path, { headers: { Accept: 'application/json' }, signal });
  const data = await response.json();
  if (!response.ok) throw new Error(data.code || 'HTTP_' + response.status);
  return data;
}

function cellRow(values) {
  const tr = document.createElement('tr');
  for (const value of values) {
    const td = document.createElement('td');
    if (value instanceof Node) td.append(value);
    else td.textContent = value ?? '—';
    tr.append(td);
  }
  return tr;
}

function lines(id, values, fallback) {
  const target = byId(id);
  if (!values.length) {
    target.textContent = fallback;
    return;
  }
  const list = document.createElement('ul');
  for (const text of values) {
    const item = document.createElement('li');
    item.textContent = text;
    list.append(item);
  }
  target.replaceChildren(list);
}

async function showInstrument(id) {
  const target = byId('instrument-detail');
  target.hidden = false;
  target.textContent = 'Consultando histórico.';
  try {
    const [instrument, predictions] = await Promise.all([api('instruments/' + id), api('instruments/' + id + '/predictions')]);
    const item = instrument.instrument;
    const latest = instrument.items[0];
    const message = [
      item.symbol + ' · ' + item.isin + ' · ' + item.sector,
      latest ? 'Último fechamento bruto · ' + latest.session_date + ' · ' + amount(latest.close_px) : 'Histórico oficial pendente.'
    ];
    for (const prediction of predictions.items.slice(0, 3)) {
      const d = prediction.details;
      const states = {WAITING: 'Aguardando avaliação', MATURED: 'Avaliada', UNOBSERVABLE: 'Resultado não observável'};
      message.push(prediction.horizon + ' · probabilidade de lucro ' + pct(d.p_profitable) +
        ' · q10 ' + pct(d.q10) + ' · q90 ' + pct(d.q90) + ' · ' + states[prediction.evaluation_status]);
    }
    if (!predictions.items.length) message.push('Ainda sem previsões.');
    lines('instrument-detail', message, '');
  } catch (error) {
    target.textContent = 'Histórico indisponível.';
  }
}

function paint() {
  const canvas = byId('chart');
  const bounds = canvas.getBoundingClientRect();
  if (bounds.width === 0) return;
  const scale = window.devicePixelRatio || 1;
  canvas.width = bounds.width * scale;
  canvas.height = bounds.height * scale;
  const context = canvas.getContext('2d');
  context.scale(scale, scale);
  const width = bounds.width;
  const height = bounds.height;
  const left = 108;
  const right = width - 19;
  const good = curve.filter((row) => row.status === 'AVAILABLE' && row.equity_cents != null);
  const low = good.length ? Math.min(...good.map((row) => Number(row.equity_cents))) : 0;
  const high = good.length ? Math.max(...good.map((row) => Number(row.equity_cents))) : 0;
  const min = low - Math.max(100, (high - low) * .1);
  const max = high + Math.max(100, (high - low) * .1);
  const y = (value) => height - 20 - ((value - min) / (max - min)) * (height - 37);
  context.font = '11px system-ui';
  for (let index = 0; index < 4; index++) {
    const value = min + index * (max - min) / 3;
    const lineY = y(value);
    context.strokeStyle = '#30434d';
    context.beginPath();
    context.moveTo(left, lineY);
    context.lineTo(right, lineY);
    context.stroke();
    if (good.length) {
      context.fillStyle = '#a6b7c0';
      context.fillText(amount(value), 13, lineY + 4);
    }
  }
  context.strokeStyle = '#65d6c1';
  context.fillStyle = '#65d6c1';
  context.lineWidth = 2;
  let previous = null;
  curve.forEach((row, index) => {
    if (row.status !== 'AVAILABLE' || row.equity_cents == null) {
      previous = null;
      return;
    }
    const x = curve.length === 1 ? (left + right) / 2 : left + (right - left) * index / (curve.length - 1);
    const lineY = y(Number(row.equity_cents));
    context.beginPath();
    if (previous) {
      context.moveTo(previous[0], previous[1]);
      context.lineTo(x, lineY);
      context.stroke();
    }
    context.beginPath();
    context.arc(x, lineY, 3, 0, Math.PI * 2);
    context.fill();
    previous = [x, lineY];
  });
}

async function updateCurve() {
  const id = byId('account-picker').value;
  if (!id) {
    curve = [];
    paint();
    set('chart-status', 'Aguardando uma conta virtual.');
    return;
  }
  try {
    const data = await api('accounts/' + id + '/equity?limit=90');
    if (id !== byId('account-picker').value) return;
    curve = data.items.reverse();
    paint();
    set('chart-status', curve.length === 1 ? 'Patrimônio inicial registrado.' : curve.length + ' registros patrimoniais.');
  } catch (error) {
    set('chart-status', 'Curva patrimonial indisponível.');
  }
}

function render(data) {
  const s = data.summary;
  const delta = s.equity_cents == null || s.initial_cents == null ? null : s.equity_cents - s.initial_cents;
  set('market-status', { CLOSED: 'Pregão fechado', CONTINUOUS: 'Pregão contínuo' }[data.market_status] || 'Calendário indisponível');
  set('as-of', 'Atualizado em ' + dateTime(data.as_of));
  set('notice', s.eod_bars ? 'Barras diárias importadas · ' + s.eod_bars : 'Histórico diário pendente. As cotações disponíveis são para acompanhamento.');
  set('equity', amount(s.equity_cents));
  set('capital', 'Capital inicial · ' + amount(s.initial_cents));
  set('pnl', amount(delta));
  set('return', 'Retorno · ' + pct(s.initial_cents && delta != null ? delta / s.initial_cents : null));
  set('accounts', String(s.accounts));
  set('fills', 'Execuções hoje · ' + s.fills_today);
  const cdi = data.benchmarks.CDI;
  set('cdi', pct(cdi ? cdi.value / 100 : null));
  const ibov = data.benchmarks.IBOV;
  set('ibov', 'Ibovespa · ' + (ibov ? Number(ibov.value).toLocaleString('pt-BR') : '—'));
  set('season', data.season?.name ?? 'Aguardando temporada');
  set('universe-status', 'Lista fixa · ' + data.items.length + ' ações');
  byId('markets').replaceChildren(...data.items.map((row) => {
    const control = document.createElement('button');
    control.type = 'button';
    control.textContent = row.symbol + (row.mandatory ? ' · Obrigatória' : '');
    control.disabled = row.id == null;
    control.addEventListener('click', () => showInstrument(row.id));
    const quote = row.quote;
    const label = quote ? (quote.status === 'STALE' ? 'Cotação antiga · brapi' : 'Cotação observada · brapi') : 'Sem cotação';
    const health = {PENDING_IDENTITY: 'Cadastro pendente', PENDING_HISTORY: 'Histórico pendente',
      MONITOR_ONLY: 'Em acompanhamento', EXPERIMENTAL_BASELINE: 'Regra experimental',
      TRADABLE: 'Sinal elegível', REJECTED: 'Sem sinal elegível', SUSPENDED: 'Suspensa', DELISTED: 'Deslistada'};
    return cellRow([control, row.name, amount(quote?.price_cents), dateTime(quote?.event_at),
      pct(row.p_profitable), (health[row.status] ?? row.status) + ' · ' + label]);
  }));
  byId('strategy-rows').replaceChildren(...data.accounts.map((row) => {
    const d = row.details;
    return cellRow([titles[row.strategy_key] || row.strategy_key, amount(row.equity_cents),
      amount(d.available_cents), amount(d.realized_cents), amount(d.unrealized_cents), amount(d.fees_cents)]);
  }));
  const picker = byId('account-picker');
  const selected = picker.value;
  picker.replaceChildren(new Option('Selecione', ''),
    ...data.accounts.map((row) => new Option(titles[row.strategy_key] || row.strategy_key, row.id)));
  picker.value = data.accounts.some((row) => String(row.id) === selected) ? selected : (data.accounts[0]?.id ?? '');
  updateCurve();
}

async function loadPanels(signal) {
  const panels = await Promise.allSettled([
    api('matrix?limit=160', signal), api('orders', signal), api('trades', signal),
    api('research/runs', signal), api('research/models', signal), api('health', signal)
  ]);
  const received = (index) => panels[index].status === 'fulfilled' ? panels[index].value : {};
  const matrix = received(0).items ?? [];
  const orders = received(1).items ?? [];
  tradeRows = received(2).items ?? tradeRows;
  const models = received(4).items ?? [];
  const health = received(5);
  const heading = ['Estratégia', ...Array.from(byId('markets').rows).map((row) => row.cells[0].textContent)];
  const grid = document.createElement('table');
  const matrixBody = document.createElement('tbody');
  matrixBody.append(cellRow(heading));
  const instrumentIds = dashboardInstruments.map((row) => String(row.id));
  for (const [key, title] of Object.entries(titles)) {
    matrixBody.append(cellRow([title, ...instrumentIds.map((instrument) => {
      const signalRow = matrix.find((row) => row.strategy_key === key && String(row.instrument_id) === instrument);
      if (!signalRow) return '—';
      const content = document.createElement('span');
      content.textContent = signalRow.action;
      content.title = signalRow.reason;
      return content;
    })]));
  }
  grid.append(matrixBody);
  const wrap = document.createElement('div');
  wrap.className = 'table-wrap';
  wrap.append(grid);
  byId('matrix-data').replaceChildren(wrap);
  lines('trade-data', [...tradeRows.map((row) =>
    'Execução · ' + dateTime(row.filled_at) + ' · ' + row.details.side + ' · ' + row.details.quantity + ' ações · ' + amount(row.details.price_cents)),
  ...orders.map((row) => 'Ordem · ' + dateTime(row.decision_at) + ' · ' + row.details.side + ' · ' + row.status)],
  'Nenhuma ordem ou negócio registrado na página atual.');
  lines('research-data', [...models.map((m) => m.horizon + ' · ' + m.status + ' · ' + m.id.slice(0,12)),
    ...(received(3).items ?? []).map((run) => {
      if (run.kind === 'STRATEGY_FACTORY') {
        const status = {FROZEN: 'Candidato congelado', NO_CANDIDATE: 'Sem candidato',
          REJECTED: 'Rejeitado na validação', REVIEW_FOR_SHADOW: 'Aguardando revisão para observação'};
        return 'Fábrica de estratégias · ' + (status[run.details?.status] ?? 'Relatório') + ' · ' + dateTime(run.created_at);
      }
      return run.kind + ' · ' + dateTime(run.created_at) + ' · ' + run.id.slice(0,12);
    })],
  'Nenhum modelo ou estudo importado.');
  lines('health-data', [...(health.items ?? []).map((source) => source.source + ' · ' + source.status + ' · ' + dateTime(source.last_success)),
    ...(health.eod_checkpoints ?? []).slice(0, 5).map((job) => 'EOD · ' + job.session_date + ' · ' +
      ({DONE: 'Processado', GAP: 'Sessão perdida', WAITING: 'Aguardando dados', RETRY: 'Nova tentativa',
        RUNNING: 'Em processamento', DEAD: 'Requer revisão'}[job.status] ?? job.status)),
    ...(health.jobs ?? []).slice(0,5).map((job) => job.job + ' · ' + job.status + ' · ' + job.detail),
    ...(health.alerts ?? []).map((alert) => alert.code + ' · ' + dateTime(alert.as_of))], 'Aguardando registros de coleta.');
  const events = [
    ...(health.items ?? []).map((row) => ({at: row.last_attempt, origin: row.source, state: row.status,
      detail: row.detail || (row.last_success ? 'Última coleta concluída em ' + dateTime(row.last_success) : 'Ainda sem coleta concluída')})),
    ...(health.jobs ?? []).map((row) => ({at: row.as_of, origin: row.job, state: row.status, detail: row.detail})),
    ...(health.eod_checkpoints ?? []).map((row) => ({at: row.updated_at || row.started_at, origin: 'EOD · ' + row.session_date,
      state: row.status, detail: row.last_error || row.result || ''})),
    ...(health.alerts ?? []).map((row) => ({at: row.as_of, origin: 'Alerta', state: row.code,
      detail: row.details?.message || row.details?.reason || row.code}))
  ].sort((a, b) => String(b.at || '').localeCompare(String(a.at || ''))).slice(0, 100);
  const logRows = byId('log-rows');
  logRows.replaceChildren(...events.map((event) => cellRow([
    dateTime(event.at), event.origin || 'Sistema', event.state || '—', event.detail || '—'
  ])));
  byId('logs-empty').hidden = events.length > 0;
}

let dashboardInstruments = [];
async function refresh() {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 10000);
  try {
    const data = await api('dashboard', controller.signal);
    dashboardInstruments = data.items;
    render(data);
    await loadPanels(controller.signal);
  } catch (error) {
    set('notice', 'Atualização indisponível · ' + error.message);
  } finally {
    clearTimeout(timer);
    setTimeout(refresh, document.hidden ? 60000 : 15000);
  }
}

function csvCell(value) {
  let text = String(value ?? '');
  if (/^\s*[=+\-@\t\r]/.test(text)) text = "'" + text;
  return '"' + text.replaceAll('"', '""') + '"';
}
byId('export-trades').addEventListener('click', () => {
  const fields = ['filled_at', 'side', 'quantity', 'market', 'reference_cents', 'price_cents', 'fees_cents', 'settlement'];
  const text = [fields, ...tradeRows.map((row) => fields.map((key) => row.details[key]))]
    .map((row) => row.map(csvCell).join(';')).join('\r\n');
  const url = URL.createObjectURL(new Blob(['\ufeff',text], { type: 'text/csv;charset=utf-8' }));
  const a = document.createElement('a');
  a.href = url;
  a.download = 'orfeu-negocios.csv';
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
});
byId('account-picker').addEventListener('change', updateCurve);
window.addEventListener('resize', paint);
const nav = Array.from(document.querySelectorAll('nav a'));
function activeLink() {
  nav.forEach((link) => link.classList.toggle('active', link.hash === (location.hash || '#visao')));
}
window.addEventListener('hashchange', activeLink);
activeLink();
refresh();
