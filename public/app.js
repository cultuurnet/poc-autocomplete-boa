'use strict';

/**
 * Side-by-side comparison UI for the autocomplete POC.
 *
 * One deliberate choice up front: each keystroke fires one request per engine
 * (engine=mysql and engine=elasticsearch) rather than a single engine=all
 * request. Two reasons. The round-trip time shown per column is then a real
 * per-engine number instead of one shared figure repeated twice, which is the
 * whole point of putting it next to the engine's own took_ms. And a slow or
 * dead engine no longer delays the other column: whichever answers first
 * renders first. The engine=all endpoint still works and is what you want from
 * curl or the benchmark; the browser just has cheaper parallelism than PHP.
 */

const ENGINES = ['mysql', 'elasticsearch'];
const SHORT_NAME = { mysql: 'MySQL', elasticsearch: 'ES' };
const DEBOUNCE_MS = 120;
const HISTORY_SIZE = 20;

// ngrok serves an interstitial warning page instead of the API response unless
// the request carries this header; the value is irrelevant, only its presence.
const API_HEADERS = {
  Accept: 'application/json',
  'ngrok-skip-browser-warning': '1',
};

const dom = {
  q: document.getElementById('q'),
  limit: document.getElementById('limit'),
  fuzzy: document.getElementById('fuzzy'),
  explain: document.getElementById('explain'),
  types: Array.from(document.querySelectorAll('input.type')),
  status: document.getElementById('status'),
  health: document.getElementById('health'),
  trend: document.getElementById('trend'),
  pinned: document.getElementById('pinned'),
  pinnedBody: document.getElementById('pinned-body'),
  unpin: document.getElementById('unpin'),
  columns: {},
};

for (const engine of ENGINES) {
  const root = document.querySelector(`.column[data-engine="${engine}"]`);

  dom.columns[engine] = {
    root,
    head: root.querySelector('.column-head'),
    fastest: root.querySelector('.fastest'),
    server: root.querySelector('.stat-server'),
    client: root.querySelector('.stat-client'),
    count: root.querySelector('.stat-count'),
    error: root.querySelector('.engine-error'),
    list: root.querySelector('.results'),
    empty: root.querySelector('.empty'),
  };
}

const state = {
  seq: 0,
  timer: null,
  abort: null,
  /** @type {{seq: number, engines: Record<string, object>}|null} */
  current: null,
  history: { mysql: [], elasticsearch: [] },
  focus: 'mysql',
  cursor: { mysql: -1, elasticsearch: -1 },
};

/* ------------------------------------------------------------------ utils */

function el(tag, className, text) {
  const node = document.createElement(tag);

  if (className) {
    node.className = className;
  }

  // Everything data-derived goes through textContent. Street names come
  // straight out of a CSV; none of it is ever parsed as markup.
  if (text !== undefined && text !== null) {
    node.textContent = String(text);
  }

  return node;
}

function ms(value) {
  if (value === null || value === undefined) {
    return '–';
  }

  return `${value < 10 ? value.toFixed(1) : Math.round(value)} ms`;
}

function median(values) {
  if (values.length === 0) {
    return null;
  }

  const sorted = [...values].sort((a, b) => a - b);
  const mid = sorted.length >> 1;

  return sorted.length % 2 === 1 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
}

function otherEngine(engine) {
  return engine === 'mysql' ? 'elasticsearch' : 'mysql';
}

function selectedTypes() {
  return dom.types.filter((box) => box.checked).map((box) => box.value);
}

/* ---------------------------------------------------------------- fetching */

function buildUrl(engine, query, types) {
  const params = new URLSearchParams({
    q: query,
    limit: dom.limit.value,
    engine,
    fuzzy: dom.fuzzy.checked ? '1' : '0',
    // Costs the engine real time, so it is only asked for when the panel it
    // feeds is actually wanted; the timings shown while it is on are not
    // comparable to the ones shown while it is off.
    explain: dom.explain.checked ? '1' : '0',
  });

  // An empty selection would mean "search nothing", which the API reads as
  // "search everything"; the change handler keeps at least one box ticked.
  if (types.length > 0 && types.length < dom.types.length) {
    params.set('types', types.join(','));
  }

  return `api/suggest?${params.toString()}`;
}

function scheduleQuery() {
  window.clearTimeout(state.timer);
  state.timer = window.setTimeout(runQuery, DEBOUNCE_MS);
}

async function runQuery() {
  const query = dom.q.value.trim();

  // A new keystroke invalidates everything already in flight. The abort stops
  // the work; the sequence number is the actual correctness guard, because an
  // abort can lose the race with a response that is already being parsed.
  state.abort?.abort();
  state.abort = new AbortController();

  const seq = ++state.seq;
  const types = selectedTypes();

  state.current = {
    seq,
    query,
    engines: Object.fromEntries(ENGINES.map((e) => [e, { status: 'pending' }])),
  };

  state.cursor = { mysql: -1, elasticsearch: -1 };
  render();

  if (query === '') {
    dom.status.textContent = '';

    for (const engine of ENGINES) {
      state.current.engines[engine] = { status: 'ok', result: emptyResult(engine), clientMs: 0 };
    }

    render();

    return;
  }

  dom.status.textContent = 'searching…';

  await Promise.all(ENGINES.map((engine) => fetchEngine(engine, query, types, seq)));

  if (seq === state.seq) {
    dom.status.textContent = `“${query}”`;
  }
}

function emptyResult(engine) {
  return { engine, took_ms: 0, total: 0, count: 0, suggestions: [], debug: {} };
}

async function fetchEngine(engine, query, types, seq) {
  const startedAt = performance.now();

  try {
    const response = await fetch(buildUrl(engine, query, types), {
      signal: state.abort.signal,
      headers: API_HEADERS,
    });

    const body = await response.json();
    const clientMs = performance.now() - startedAt;

    // Stale: a newer keystroke already owns the columns.
    if (seq !== state.seq) {
      return;
    }

    const result = body?.engines?.[engine] ?? emptyResult(engine);

    state.current.engines[engine] = { status: 'ok', result, clientMs };

    if (!result.error) {
      pushHistory(engine, result.took_ms, clientMs);
    }
  } catch (error) {
    if (error?.name === 'AbortError' || seq !== state.seq) {
      return;
    }

    state.current.engines[engine] = {
      status: 'error',
      result: { ...emptyResult(engine), error: String(error?.message ?? error) },
      clientMs: performance.now() - startedAt,
    };
  }

  render();
}

function pushHistory(engine, serverMs, clientMs) {
  const entries = state.history[engine];

  entries.push({ server: serverMs, client: clientMs });

  if (entries.length > HISTORY_SIZE) {
    entries.shift();
  }
}

/* --------------------------------------------------------------- rendering */

function render() {
  const view = state.current;

  if (!view) {
    return;
  }

  markFastest(view);

  for (const engine of ENGINES) {
    renderColumn(engine, view);
  }

  renderTrend(view);
}

/**
 * Only compare when both engines have answered for this same keystroke;
 * calling a column "fastest" against a stale or missing number would be a lie.
 */
function markFastest(view) {
  const times = {};

  for (const engine of ENGINES) {
    const slot = view.engines[engine];

    times[engine] = slot.status === 'ok' && !slot.result.error ? slot.result.took_ms : null;
  }

  const comparable = times.mysql !== null && times.elasticsearch !== null;
  const winner = comparable && times.mysql !== times.elasticsearch
    ? (times.mysql < times.elasticsearch ? 'mysql' : 'elasticsearch')
    : null;

  for (const engine of ENGINES) {
    dom.columns[engine].fastest.hidden = engine !== winner;
    dom.columns[engine].root.classList.toggle('is-fastest', engine === winner);
  }
}

function renderColumn(engine, view) {
  const ui = dom.columns[engine];
  const slot = view.engines[engine];

  if (slot.status === 'pending') {
    ui.root.classList.add('is-loading');
    ui.server.textContent = '…';
    ui.client.textContent = '…';

    return;
  }

  ui.root.classList.remove('is-loading');

  const result = slot.result;

  ui.server.textContent = ms(result.took_ms);
  ui.client.textContent = ms(slot.clientMs);
  ui.count.textContent = result.total > result.count
    ? `${result.count} of ${result.total}`
    : String(result.count);

  ui.error.hidden = !result.error;
  ui.error.textContent = result.error ?? '';

  const suggestions = result.suggestions ?? [];

  ui.empty.hidden = suggestions.length > 0 || Boolean(result.error) || view.query === '';

  renderRows(engine, view, suggestions);
  renderEngineQuery(ui, result.debug);
}

/**
 * The debug blob a SuggestResult carries is the query actually sent to the
 * engine. It answers "is MySQL even looking for the same thing?" faster than
 * reading either suggester's source, so it lives one click away from the head.
 */
function renderEngineQuery(ui, debug) {
  ui.head.querySelector('.engine-query')?.remove();

  if (!debug || Object.keys(debug).length === 0) {
    return;
  }

  const details = el('details', 'engine-query');

  details.append(el('summary', null, 'query sent to engine'));
  details.append(el('pre', null, JSON.stringify(debug, null, 2)));
  ui.head.append(details);
}

/**
 * Rank lookup for the other engine, used for the overlap annotations. Built
 * only when that engine actually answered for this keystroke.
 */
function otherRanks(engine, view) {
  const slot = view.engines[otherEngine(engine)];

  if (slot.status !== 'ok' || slot.result.error) {
    return null;
  }

  const ranks = new Map();

  (slot.result.suggestions ?? []).forEach((s, index) => ranks.set(s.id, index + 1));

  return ranks;
}

function renderRows(engine, view, suggestions) {
  const ui = dom.columns[engine];
  const ranks = otherRanks(engine, view);
  const rows = suggestions.map((s, index) => buildRow(engine, s, index, ranks));

  ui.list.replaceChildren(...rows);
  applyCursor(engine);
}

function buildRow(engine, suggestion, index, ranks) {
  const row = el('li', 'row');

  row.dataset.index = String(index);
  row.tabIndex = -1;

  const main = el('div', 'row-main');

  main.append(el('span', 'rank', index + 1));
  main.append(el('span', 'label', suggestion.label));
  main.append(el('span', 'score', `score ${suggestion.score}`));
  row.append(main);

  const meta = el('div', 'row-meta');

  meta.append(el('span', `badge type-${suggestion.type}`, suggestion.type));
  meta.append(el('span', `badge filter-${suggestion.filter}`, suggestion.filter));

  if (suggestion.postcode) {
    meta.append(el('span', 'badge muted', suggestion.postcode));
  }

  if (suggestion.municipality_name) {
    meta.append(el('span', 'badge muted', suggestion.municipality_name));
  }

  meta.append(el('span', 'coords', formatCoordinates(suggestion.coordinates)));

  if (suggestion.popularity) {
    meta.append(el('span', 'pop', `pop ${suggestion.popularity}`));
  }

  meta.append(buildOverlapTag(engine, suggestion, index, ranks));
  row.append(meta);

  const debug = suggestion.debug;

  if (debug && Object.keys(debug).length > 0) {
    const details = el('details', 'row-debug');

    details.append(el('summary', null, 'why this rank'));
    details.append(el('pre', null, JSON.stringify(debug, null, 2)));
    row.append(details);
  }

  row.addEventListener('click', () => {
    state.focus = engine;
    state.cursor[engine] = index;
    applyCursor(engine);
    pin(engine, suggestion);
  });

  return row;
}

/**
 * The single most useful quality signal on this page: does the other engine
 * know about this result at all, and if so, where did it put it?
 */
function buildOverlapTag(engine, suggestion, index, ranks) {
  const other = SHORT_NAME[otherEngine(engine)];

  if (ranks === null) {
    return el('span', 'overlap pending', `${other}: ?`);
  }

  const rank = ranks.get(suggestion.id);

  if (rank === undefined) {
    // Marked on the row too, as a coloured left border.
    return el('span', 'overlap unique', `only in ${SHORT_NAME[engine]}`);
  }

  const delta = rank - (index + 1);
  const arrow = delta === 0 ? '=' : (delta > 0 ? `↓${delta}` : `↑${-delta}`);

  return el('span', `overlap shared${delta === 0 ? ' same' : ''}`, `${other} #${rank} ${arrow}`);
}

function formatCoordinates(coordinates) {
  if (!coordinates) {
    return 'no coordinates';
  }

  return `${coordinates.lat.toFixed(5)}, ${coordinates.lon.toFixed(5)}`;
}

/**
 * Rolling medians, not the last sample: a single keystroke's latency on a warm
 * page swings by a factor of three and says nothing about either engine.
 */
function renderTrend(view) {
  const parts = [];

  for (const engine of ENGINES) {
    const entries = state.history[engine];
    const server = median(entries.map((e) => e.server));
    const client = median(entries.map((e) => e.client));

    const item = el('span', `trend-item trend-${engine}`);

    item.append(el('strong', null, SHORT_NAME[engine]));
    item.append(el('span', null, ` median engine ${ms(server)} · round-trip ${ms(client)}`));
    item.append(el('span', 'trend-n', ` (n=${entries.length})`));
    parts.push(item);
  }

  const overlap = overlapCount(view);

  if (overlap !== null) {
    const item = el('span', 'trend-item trend-overlap');

    item.append(el('strong', null, 'overlap'));
    item.append(el('span', null, ` ${overlap.shared} of ${overlap.total} ids shared`));
    parts.push(item);
  }

  dom.trend.replaceChildren(...parts);
}

function overlapCount(view) {
  const [a, b] = ENGINES.map((engine) => view.engines[engine]);

  if (a.status !== 'ok' || b.status !== 'ok' || a.result.error || b.result.error) {
    return null;
  }

  const left = (a.result.suggestions ?? []).map((s) => s.id);
  const right = new Set((b.result.suggestions ?? []).map((s) => s.id));
  const shared = left.filter((id) => right.has(id)).length;
  const total = new Set([...left, ...right]).size;

  return total === 0 ? null : { shared, total };
}

/* ------------------------------------------------------------------ health */

async function loadHealth() {
  dom.health.replaceChildren(el('span', 'health-item is-unknown', 'checking engines…'));

  try {
    const response = await fetch('api/health', { headers: API_HEADERS });
    const body = await response.json();
    const items = ENGINES.map((engine) => {
      const info = body?.engines?.[engine];
      const ok = Boolean(info?.ok);
      const item = el('span', `health-item ${ok ? 'is-ok' : 'is-down'}`);

      item.append(el('strong', null, SHORT_NAME[engine]));
      item.append(el('span', null, ok ? ` ${Number(info.documents).toLocaleString()} docs` : ' unavailable'));
      // The detail string is where "index is still importing" shows up.
      item.title = String(info?.detail ?? 'no detail');

      return item;
    });

    dom.health.replaceChildren(...items);
  } catch (error) {
    dom.health.replaceChildren(el('span', 'health-item is-down', `health check failed: ${error?.message ?? error}`));
  }
}

/* ----------------------------------------------------------------- pinning */

function pin(engine, suggestion) {
  dom.pinned.hidden = false;

  const head = el('p', 'pinned-label', suggestion.label);
  const meta = el('p', 'pinned-meta', `${SHORT_NAME[engine]} · ${suggestion.type} · filter: ${suggestion.filter}`);
  const body = el('pre', null, JSON.stringify(suggestion, null, 2));

  dom.pinnedBody.replaceChildren(head, meta, body);
}

function unpin() {
  dom.pinned.hidden = true;
  dom.pinnedBody.replaceChildren();
}

/* ---------------------------------------------------------------- keyboard */

function applyCursor(engine) {
  const ui = dom.columns[engine];
  const index = state.cursor[engine];

  Array.from(ui.list.children).forEach((row, i) => {
    row.classList.toggle('is-active', i === index && state.focus === engine);
  });

  ui.root.classList.toggle('is-focused', state.focus === engine);

  if (index >= 0 && state.focus === engine) {
    ui.list.children[index]?.scrollIntoView({ block: 'nearest' });
  }
}

function rowCount(engine) {
  return dom.columns[engine].list.children.length;
}

function moveCursor(delta) {
  const engine = state.focus;
  const count = rowCount(engine);

  if (count === 0) {
    return;
  }

  const next = state.cursor[engine] + delta;

  state.cursor[engine] = Math.max(0, Math.min(count - 1, next < 0 ? 0 : next));
  ENGINES.forEach(applyCursor);
}

function switchColumn(engine) {
  state.focus = engine;

  if (state.cursor[engine] < 0 && rowCount(engine) > 0) {
    state.cursor[engine] = 0;
  }

  ENGINES.forEach(applyCursor);
}

function hasHighlight() {
  return state.cursor[state.focus] >= 0;
}

function activeSuggestion() {
  const slot = state.current?.engines[state.focus];
  const index = state.cursor[state.focus];

  if (!slot || slot.status !== 'ok' || index < 0) {
    return null;
  }

  return slot.result.suggestions?.[index] ?? null;
}

function onKeydown(event) {
  // Leave the native widgets alone; arrows and Enter belong to them.
  const tag = event.target?.tagName;

  if (tag === 'SELECT' || tag === 'BUTTON' || event.altKey || event.metaKey || event.ctrlKey) {
    return;
  }

  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault();
    moveCursor(event.key === 'ArrowDown' ? 1 : -1);

    return;
  }

  // Left/right only steal the caret once a row is highlighted, so normal
  // typing and editing in the search box keeps working.
  if ((event.key === 'ArrowLeft' || event.key === 'ArrowRight') && hasHighlight()) {
    event.preventDefault();
    switchColumn(event.key === 'ArrowRight' ? 'elasticsearch' : 'mysql');

    return;
  }

  if (event.key === 'Enter') {
    const suggestion = activeSuggestion();

    if (suggestion) {
      event.preventDefault();
      pin(state.focus, suggestion);
    }

    return;
  }

  if (event.key === 'Escape') {
    event.preventDefault();

    if (hasHighlight()) {
      state.cursor = { mysql: -1, elasticsearch: -1 };
      ENGINES.forEach(applyCursor);

      return;
    }

    unpin();
    dom.q.value = '';
    dom.q.focus();
    runQuery();
  }
}

/* -------------------------------------------------------------------- wire */

dom.q.addEventListener('input', scheduleQuery);
dom.limit.addEventListener('change', runQuery);
dom.fuzzy.addEventListener('change', runQuery);
dom.explain.addEventListener('change', runQuery);

for (const box of dom.types) {
  box.addEventListener('change', () => {
    // Searching zero types is not a question anyone means to ask.
    if (selectedTypes().length === 0) {
      dom.types.forEach((other) => { other.checked = true; });
    }

    runQuery();
  });
}

for (const engine of ENGINES) {
  dom.columns[engine].root.addEventListener('mousedown', () => switchColumn(engine));
}

document.addEventListener('keydown', onKeydown);
dom.unpin.addEventListener('click', unpin);

// Document counts move while an import runs, so the strip is re-checkable.
dom.health.title = 'click to re-check';
dom.health.addEventListener('click', loadHealth);

loadHealth();
dom.q.focus();
