'use strict';

/**
 * Side-by-side comparison UI for the autocomplete POC.
 *
 * The page keeps two columns, but a column is now a *side*, not an engine:
 * each one carries a dropdown that picks which registered suggest method it
 * shows. Everything per column -- results, cursor, latency history -- is
 * therefore keyed by 'left'/'right', and the method is just that column's
 * current setting, restored from localStorage on load. Nothing below may
 * assume the two sides differ; picking the same method twice is a legitimate
 * thing to do (it shows you the run-to-run spread of one method), and the
 * places where that would otherwise produce a nonsense claim say so.
 *
 * One deliberate choice carried over from the two-engine version: each
 * keystroke fires one request per column (engine=<that column's method>)
 * rather than a single engine=all request. Three reasons now. The round-trip
 * time shown per column is a real per-method number instead of one shared
 * figure repeated twice, which is the whole point of putting it next to the
 * method's own took_ms. A slow or dead method no longer delays the other
 * column: whichever answers first renders first. And changing one dropdown
 * re-runs that column alone, leaving the other column's rows -- and its own
 * in-flight request -- untouched, which is why the abort controller and the
 * sequence number below are per column too. The engine=all endpoint still
 * works and is what you want from curl or the benchmark; the browser just has
 * cheaper parallelism than PHP.
 */

const COLUMNS = ['left', 'right'];

/**
 * The two methods still worth arguing about, so the page opens on the actual
 * decision rather than on a settled one.
 *
 * es-prefixes is the best indexed method: it matches or beats the incumbent
 * edge-n-gram chain on every axis measured, for the same index, and needs no
 * MAX_GRAM cliff or preserve_original workaround. es-bool-prefix is the best
 * method with no prefix index at all - highest golden-set score of the five at
 * both corpus sizes, indistinguishable at the median, and no fields of its own
 * to build on top of the ones the index carries anyway - and it loses only on
 * the first two keystrokes, where its term-dictionary scan runs 3.2x slower at
 * four million documents.
 *
 * That trade is what the two columns are for. MySQL and the incumbent are one
 * click away in either picker; they are just not open questions any more.
 */
const DEFAULT_METHOD = { left: 'es-prefixes', right: 'es-bool-prefix' };

// Bumped with the defaults above: a stored pair from before this change would
// otherwise keep showing the old comparison to everyone who has already opened
// the page, which is precisely the audience the defaults are for.
const STORAGE_KEY = 'poc-autocomplete:columns:v2';
const DEBOUNCE_MS = 120;
const HISTORY_SIZE = 20;

// ngrok serves an interstitial warning page instead of the API response unless
// the request carries this header; the value is irrelevant, only its presence.
const API_HEADERS = {
  Accept: 'application/json',
  'ngrok-skip-browser-warning': '1',
};

/**
 * The dropdown is built from /api/health so the page never claims to offer a
 * method the server has not registered. This list is what it uses until that
 * request answers, and what it keeps if the payload carries no method list at
 * all -- the keys are fixed by the API, so a hardcoded copy is safe, it just
 * goes stale in the labels rather than in the behaviour.
 */
const FALLBACK_METHODS = [
  { key: 'mysql', label: 'MySQL', description: 'The MySQL baseline.' },
  { key: 'elasticsearch', label: 'ES edge n-gram', description: 'The incumbent Elasticsearch method.' },
  { key: 'es-prefixes', label: 'ES index_prefixes', description: 'Elasticsearch index_prefixes on the analysed field.' },
  { key: 'es-sayt', label: 'ES search_as_you_type', description: 'Elasticsearch search_as_you_type shingle subfields.' },
  { key: 'es-bool-prefix', label: 'ES match_bool_prefix', description: 'Elasticsearch match_bool_prefix over the analysed field.' },
  { key: 'es-completion', label: 'ES completion (FST)', description: 'Elasticsearch completion suggester over the FST.' },
];

/**
 * Plain-language answers to "what is this one, then?", behind the ? button.
 *
 * Client-side for the same reason as METHOD_NOTES below: this has to be right
 * on the first paint, before any request has answered. Deliberately not the
 * same text as the server's one-line `description` - that one names the
 * Elasticsearch feature for someone who already knows what it is, this one is
 * for someone comparing two columns and wondering why they differ.
 */
const METHOD_EXPLAINERS = {
  mysql:
    'The plain database option: MySQL keeps an index of the words in every address and returns the rows containing all of them, with the last word matched as the start of a word. '
    + 'The ranking is a hand-written formula, and it has no typo tolerance of its own.',
  elasticsearch:
    'Every name is chopped up into all of its beginnings when it is stored, so "Gent" is stored as g, ge, gen and gent. '
    + 'Typing a few letters is then just a direct lookup, which is quick - but storing all those fragments makes the index several times bigger.',
  'es-prefixes':
    'The same idea as the edge n-gram method, except Elasticsearch builds and maintains the list of beginnings itself instead of us configuring it by hand. '
    + 'Less to get wrong, and it has no limit on how long a name can be.',
  'es-sayt':
    'A ready-made Elasticsearch field type meant exactly for search-as-you-type. '
    + 'It also stores neighbouring words in pairs and triples, so it is good at half-typed phrases - but it has the largest index of the five by some margin.',
  'es-bool-prefix':
    'No special index at all: Elasticsearch looks up the words you finished typing the normal way, and scans for everything starting with the last one. '
    + 'Nothing extra to store, and the scan only really costs anything when you have typed just one or two letters.',
  'es-completion':
    'A separate, purpose-built dictionary of names that is by far the fastest of the five. '
    + 'The catch is that it only matches from the beginning of a single name - "kerkstraat gent" finds nothing - and it orders results purely by how big the place is.',
};

/**
 * Caveats that are about how a method *works*, not about how it is doing, so
 * they are hardcoded here rather than read from the health payload: the note
 * has to be right on the very first paint, before any request has answered,
 * and it must not change wording because a server-side description was
 * reworded. Only methods that would otherwise be misread need an entry.
 */
const METHOD_NOTES = {
  'es-completion': 'Ranks on indexed popularity alone: no multi-token reordering, no house numbers, no meaningful total.',
};

/** @type {Array<{key: string, label: string, description: string}>} */
let methods = FALLBACK_METHODS.slice();

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

for (const column of COLUMNS) {
  const root = document.querySelector(`.column[data-column="${column}"]`);

  dom.columns[column] = {
    root,
    head: root.querySelector('.column-head'),
    select: root.querySelector('.method-select'),
    note: root.querySelector('.method-note'),
    helpToggle: root.querySelector('.method-help-toggle'),
    help: root.querySelector('.method-help'),
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
  timer: null,
  /** The query the columns are currently showing or fetching. */
  query: '',
  method: { left: DEFAULT_METHOD.left, right: DEFAULT_METHOD.right },
  /** Per column, because one column can be re-run on its own. */
  seq: { left: 0, right: 0 },
  abort: { left: null, right: null },
  /** @type {Record<string, {status: string, result?: object, clientMs?: number, discard?: boolean}>} */
  slots: { left: { status: 'idle' }, right: { status: 'idle' } },
  history: { left: [], right: [] },
  focus: 'left',
  cursor: { left: -1, right: -1 },
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

function otherColumn(column) {
  return column === 'left' ? 'right' : 'left';
}

/** The method selected in the other column -- what this one is compared against. */
function otherMethod(column) {
  return state.method[otherColumn(column)];
}

function sameMethodBothSides() {
  return state.method.left === state.method.right;
}

function selectedTypes() {
  return dom.types.filter((box) => box.checked).map((box) => box.value);
}

/* ------------------------------------------------------------- method list */

function methodByKey(key) {
  return methods.find((method) => method.key === key) ?? null;
}

function isKnownMethod(key) {
  return methodByKey(key) !== null;
}

function methodLabel(key) {
  return methodByKey(key)?.label ?? key;
}

function pickString(...candidates) {
  for (const candidate of candidates) {
    if (typeof candidate === 'string' && candidate.trim() !== '') {
      return candidate.trim();
    }
  }

  return null;
}

/**
 * Accepts either a list of method objects or an object keyed by method key,
 * and tolerates the obvious naming variants. Entries without a usable key are
 * dropped -- a dropdown option whose value means nothing to the API is worse
 * than a missing one.
 */
function normaliseMethods(value) {
  const entries = Array.isArray(value)
    ? value
    : (value !== null && typeof value === 'object'
      ? Object.entries(value).map(([key, item]) => ({ key, ...(item ?? {}) }))
      : []);

  return entries
    .map((item) => ({
      key: pickString(item?.key, item?.engine, item?.id) ?? '',
      label: pickString(item?.label, item?.name, item?.title),
      description: pickString(item?.description, item?.summary),
    }))
    .filter((method) => method.key !== '');
}

/**
 * Reads the method metadata out of the /api/health payload. The property this
 * expects is `methods`: a list of {key, label, description}. That payload
 * change is another agent's work and merges *after* this one, so the reader is
 * written to survive what it finds. If `methods` is absent it tries the same
 * metadata hung off the `engines` entries instead (plausible, since that map
 * is already keyed by engine) but only when those entries actually carry a
 * label, because `engines` on today's payload is a health report and would
 * otherwise silently shrink the dropdown to the two live engines. Failing
 * both, the hardcoded list stands. Labels and descriptions missing from an
 * otherwise valid entry are filled in from the hardcoded list by key.
 */
function methodsFromHealth(body) {
  const listed = normaliseMethods(body?.methods);
  const source = listed.length > 0
    ? listed
    : normaliseMethods(body?.engines).filter((method) => method.label !== null);

  if (source.length === 0) {
    return FALLBACK_METHODS.slice();
  }

  return source.map((method) => {
    const known = FALLBACK_METHODS.find((fallback) => fallback.key === method.key);

    return {
      key: method.key,
      label: method.label ?? known?.label ?? method.key,
      description: method.description ?? known?.description ?? '',
    };
  });
}

/**
 * Swaps in the server's list once it arrives. A column pointed at a method the
 * server does not offer has to move: leaving it there would mean a dropdown
 * showing nothing selected and a column that can never answer.
 */
function adoptMethods(next) {
  methods = next;
  populateMethodPickers();

  for (const column of COLUMNS) {
    if (isKnownMethod(state.method[column])) {
      applyMethodToDom(column);

      continue;
    }

    const fallback = isKnownMethod(DEFAULT_METHOD[column]) ? DEFAULT_METHOD[column] : methods[0].key;

    selectMethod(column, fallback);
  }

  persistMethods();
  render();
}

/* --------------------------------------------------------------- storage */

/**
 * Both accessors are wrapped whole, not just the get/set call: a browser with
 * site data blocked throws on the `localStorage` property itself, and a page
 * that cannot remember a dropdown must still render.
 */
function readStoredMethods() {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);

    return raw === null ? null : JSON.parse(raw);
  } catch (error) {
    return null;
  }
}

function persistMethods() {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
      left: state.method.left,
      right: state.method.right,
    }));
  } catch (error) {
    // Nothing to do and nothing to say: the choice just does not survive a
    // reload. Failing loudly here would be worse than forgetting.
  }
}

/**
 * Whatever comes back out of storage is validated against the method list
 * before it is used. It was written by a previous version of this page, and
 * the set of methods is a server-side thing that changes underneath it: a key
 * that has since been renamed or dropped would otherwise leave a column
 * pointed at an engine that answers nothing, for good, with no way back except
 * clearing site data. An unusable value is not an error, it is a first visit.
 */
function restoreMethods() {
  const stored = readStoredMethods();

  for (const column of COLUMNS) {
    const key = stored?.[column];

    state.method[column] = typeof key === 'string' && isKnownMethod(key)
      ? key
      : DEFAULT_METHOD[column];
  }
}

/* ---------------------------------------------------------------- fetching */

function buildUrl(method, query, types) {
  const params = new URLSearchParams({
    q: query,
    limit: dom.limit.value,
    engine: method,
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

/** A changed query or filter invalidates both columns. */
function runQuery() {
  state.query = dom.q.value.trim();
  state.cursor = { left: -1, right: -1 };

  // discard=false: the previous rows stay on screen, dimmed, while the new
  // ones load. They are the same method answering a slightly shorter query,
  // which is a better thing to look at mid-keystroke than an empty column.
  return Promise.all(COLUMNS.map((column) => refreshColumn(column, false)));
}

/**
 * Re-runs one column. `discard` throws that column's rows away first, which is
 * right when they came from a method the column no longer shows and wrong
 * while typing -- see runQuery.
 */
async function refreshColumn(column, discard) {
  // A newer request invalidates the one in flight for this column only. The
  // abort stops the work; the per-column sequence number is the actual
  // correctness guard, because an abort can lose the race with a response that
  // is already being parsed.
  state.abort[column]?.abort();
  state.abort[column] = new AbortController();
  state.seq[column] += 1;
  state.slots[column] = { status: 'pending', discard };

  render();

  if (state.query === '') {
    state.slots[column] = {
      status: 'ok',
      result: emptyResult(state.method[column]),
      clientMs: 0,
    };

    render();

    return;
  }

  await fetchColumn(column);
}

function emptyResult(method) {
  return { engine: method, took_ms: 0, total: 0, count: 0, suggestions: [], debug: {} };
}

async function fetchColumn(column) {
  const method = state.method[column];
  const seq = state.seq[column];
  const startedAt = performance.now();

  try {
    const response = await fetch(buildUrl(method, state.query, selectedTypes()), {
      signal: state.abort[column].signal,
      headers: API_HEADERS,
    });

    const body = await response.json();
    const clientMs = performance.now() - startedAt;

    // Stale: a newer keystroke or a method change already owns this column.
    if (seq !== state.seq[column]) {
      return;
    }

    const result = body?.engines?.[method] ?? null;

    if (result === null) {
      // /api/suggest answers 200 with the engines it knows and silently
      // ignores an ?engine= it does not recognise, so a method this page
      // offers but the server has not registered yet comes back as a *missing*
      // slice rather than as an error. Say so in the column: an empty list
      // with no explanation reads as "no matches", which is a completely
      // different and much more misleading claim.
      state.slots[column] = {
        status: 'error',
        result: {
          ...emptyResult(method),
          error: `No result for engine “${method}”: the API did not answer for this method.`,
        },
        clientMs,
      };
    } else {
      state.slots[column] = { status: 'ok', result, clientMs };

      if (!result.error) {
        pushHistory(column, result.took_ms, clientMs);
      }
    }
  } catch (error) {
    if (error?.name === 'AbortError' || seq !== state.seq[column]) {
      return;
    }

    state.slots[column] = {
      status: 'error',
      result: { ...emptyResult(method), error: String(error?.message ?? error) },
      clientMs: performance.now() - startedAt,
    };
  }

  render();
}

function pushHistory(column, serverMs, clientMs) {
  const entries = state.history[column];

  entries.push({ server: serverMs, client: clientMs });

  if (entries.length > HISTORY_SIZE) {
    entries.shift();
  }
}

/* ------------------------------------------------------------ method picker */

function populateMethodPickers() {
  for (const column of COLUMNS) {
    const select = dom.columns[column].select;

    select.replaceChildren(...methods.map((method) => {
      const option = el('option', null, method.label);

      option.value = method.key;

      // The one-line description is the only place the difference between five
      // near-identically named ES methods is written down.
      if (method.description) {
        option.title = method.description;
      }

      return option;
    }));

    select.value = state.method[column];
  }
}

function applyMethodToDom(column) {
  const ui = dom.columns[column];
  const key = state.method[column];
  const method = methodByKey(key);
  const note = METHOD_NOTES[key] ?? null;

  // The data-method attribute is what the stylesheet colours the column head
  // by, so "teal means MySQL, orange means Elasticsearch" survives the move
  // from a fixed column to a chosen method.
  ui.root.dataset.method = key;
  ui.select.value = key;
  ui.select.title = method?.description ?? '';

  ui.note.hidden = note === null;
  ui.note.textContent = note ?? '';

  // The panel is rebuilt on every method change but its open/closed state is
  // left alone: someone stepping through the methods to read about each one
  // should not have to reopen it five times.
  ui.help.replaceChildren(
    el('strong', 'method-help-title', method?.label ?? key),
    el('p', null, METHOD_EXPLAINERS[key] ?? 'No description available for this method.'),
  );
}

/**
 * Show or hide one column's explanation panel.
 *
 * A panel rather than a window.alert(): an alert blocks the whole page, so you
 * could not read the explanation while looking at the results it is explaining,
 * which is the only reason to open it.
 */
function toggleMethodHelp(column, open) {
  const ui = dom.columns[column];
  const show = open ?? ui.help.hidden;

  ui.help.hidden = !show;
  ui.helpToggle.setAttribute('aria-expanded', String(show));
}

function closeAllMethodHelp() {
  COLUMNS.forEach((column) => toggleMethodHelp(column, false));
}

/**
 * The rolling median is cleared on every method change, deliberately: a median
 * over samples from two different methods is not a slow number or a fast one,
 * it is a meaningless one, and it would keep being wrong for the next twenty
 * keystrokes. Same for the cursor, which indexes into rows that are about to
 * be replaced.
 */
function selectMethod(column, key) {
  state.method[column] = key;
  state.history[column] = [];
  state.cursor[column] = -1;

  persistMethods();
  applyMethodToDom(column);

  // Nothing has been searched yet on a fresh page: there is no query to re-run
  // and firing one would put "0 ms" in a column that has not done anything.
  if (state.slots[column].status !== 'idle') {
    refreshColumn(column, true);
  }

  // The *other* column's overlap tags name this column's method and rank
  // against its results, so both columns need repainting, not just this one.
  render();
}

/* --------------------------------------------------------------- rendering */

function render() {
  renderStatus();
  markFastest();

  for (const column of COLUMNS) {
    renderColumn(column);
  }

  renderTrend();
}

function renderStatus() {
  const pending = COLUMNS.some((column) => state.slots[column].status === 'pending');

  dom.status.textContent = state.query === ''
    ? ''
    : (pending ? 'searching…' : `“${state.query}”`);
}

/**
 * Only compare when both columns have answered for the same query; calling a
 * column "fastest" against a stale or missing number would be a lie.
 *
 * With the same method selected on both sides the badge is suppressed
 * outright. The two numbers are then two samples of one method and the gap
 * between them is scheduling noise, so a badge on one of them would read as a
 * finding about the method -- which is exactly what it is not. The trend strip
 * keeps showing both medians, which is the honest way to look at that spread.
 */
function markFastest() {
  const times = {};

  for (const column of COLUMNS) {
    const slot = state.slots[column];

    times[column] = slot.status === 'ok' && !slot.result.error ? slot.result.took_ms : null;
  }

  const comparable = !sameMethodBothSides() && times.left !== null && times.right !== null;
  const winner = comparable && times.left !== times.right
    ? (times.left < times.right ? 'left' : 'right')
    : null;

  for (const column of COLUMNS) {
    dom.columns[column].fastest.hidden = column !== winner;
    dom.columns[column].root.classList.toggle('is-fastest', column === winner);
  }
}

function renderColumn(column) {
  const ui = dom.columns[column];
  const slot = state.slots[column];

  if (slot.status === 'idle') {
    return;
  }

  if (slot.status === 'pending') {
    ui.root.classList.add('is-loading');
    ui.server.textContent = '…';
    ui.client.textContent = '…';

    if (slot.discard) {
      ui.count.textContent = '–';
      ui.error.hidden = true;
      ui.empty.hidden = true;
      ui.list.replaceChildren();
      ui.head.querySelector('.engine-query')?.remove();
    }

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

  ui.empty.hidden = suggestions.length > 0 || Boolean(result.error) || state.query === '';

  renderRows(column, suggestions);
  renderEngineQuery(ui, result.debug);
}

/**
 * The debug blob a SuggestResult carries is the query actually sent to the
 * engine. It answers "is this method even looking for the same thing?" faster
 * than reading the suggester's source, so it lives one click away from the
 * head.
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
 * Rank lookup for whatever the other column is currently showing, used for the
 * overlap annotations. Built only when that column has actually answered, so a
 * column still loading a just-picked method annotates as unknown rather than
 * against the method it used to show.
 */
function otherRanks(column) {
  const slot = state.slots[otherColumn(column)];

  if (slot.status !== 'ok' || slot.result.error) {
    return null;
  }

  const ranks = new Map();

  (slot.result.suggestions ?? []).forEach((s, index) => ranks.set(s.id, index + 1));

  return ranks;
}

function renderRows(column, suggestions) {
  const ui = dom.columns[column];
  const ranks = otherRanks(column);
  const rows = suggestions.map((s, index) => buildRow(column, s, index, ranks));

  ui.list.replaceChildren(...rows);
  applyCursor(column);
}

function buildRow(column, suggestion, index, ranks) {
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

  meta.append(buildOverlapTag(column, suggestion, index, ranks));
  row.append(meta);

  const debug = suggestion.debug;

  if (debug && Object.keys(debug).length > 0) {
    const details = el('details', 'row-debug');

    details.append(el('summary', null, 'why this rank'));
    details.append(el('pre', null, JSON.stringify(debug, null, 2)));
    row.append(details);
  }

  row.addEventListener('click', () => {
    state.focus = column;
    state.cursor[column] = index;
    applyCursor(column);
    pin(column, suggestion);
  });

  return row;
}

/**
 * The single most useful quality signal on this page: does the method in the
 * other column know about this result at all, and if so, where did it put it?
 *
 * Named by method, except when both columns show the same method -- "ES
 * completion #3" sitting in a column that is itself ES completion names
 * nothing, so those fall back to naming the side.
 */
function buildOverlapTag(column, suggestion, index, ranks) {
  const ambiguous = sameMethodBothSides();
  const other = ambiguous ? `${otherColumn(column)} column` : methodLabel(otherMethod(column));

  if (ranks === null) {
    return el('span', 'overlap pending', `${other}: ?`);
  }

  const rank = ranks.get(suggestion.id);

  if (rank === undefined) {
    // Marked on the row too, as a coloured left border.
    const here = ambiguous ? `the ${column} column` : methodLabel(state.method[column]);

    return el('span', 'overlap unique', `only in ${here}`);
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
 * page swings by a factor of three and says nothing about either method. The
 * window is per column and is emptied whenever that column's method changes,
 * so n is "samples since you picked this method", never a blend of two.
 */
function renderTrend() {
  const parts = [];

  for (const column of COLUMNS) {
    const entries = state.history[column];
    const server = median(entries.map((e) => e.server));
    const client = median(entries.map((e) => e.client));

    const item = el('span', 'trend-item');

    // Both the side and the method, because the two sides can hold the same
    // method and two identical rows would be unreadable.
    item.dataset.method = state.method[column];
    item.append(el('span', 'trend-side', column));
    item.append(el('strong', null, methodLabel(state.method[column])));
    item.append(el('span', null, ` median engine ${ms(server)} · round-trip ${ms(client)}`));
    item.append(el('span', 'trend-n', ` (n=${entries.length})`));
    parts.push(item);
  }

  const overlap = overlapCount();

  if (overlap !== null) {
    const item = el('span', 'trend-item trend-overlap');

    item.append(el('strong', null, 'overlap'));
    item.append(el('span', null, ` ${overlap.shared} of ${overlap.total} ids shared`));
    parts.push(item);
  }

  dom.trend.replaceChildren(...parts);
}

function overlapCount() {
  const [a, b] = COLUMNS.map((column) => state.slots[column]);

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

/**
 * Doubles as the method-list fetch, which is why it runs before anything is
 * typed: the dropdowns are built from whatever this returns.
 */
async function loadHealth() {
  dom.health.replaceChildren(el('span', 'health-item is-unknown', 'checking engines…'));

  try {
    const response = await fetch('api/health', { headers: API_HEADERS });
    const body = await response.json();

    adoptMethods(methodsFromHealth(body));

    // One chip per engine the health endpoint actually reports on, rather than
    // per offered method: a method can be registered and selectable while its
    // store has nothing to say about itself.
    const reported = Object.entries(body?.engines ?? {});
    const items = reported.map(([key, info]) => {
      const ok = Boolean(info?.ok);
      const item = el('span', `health-item ${ok ? 'is-ok' : 'is-down'}`);

      item.append(el('strong', null, methodLabel(key)));
      item.append(el('span', null, ok ? ` ${Number(info.documents).toLocaleString()} docs` : ' unavailable'));
      // The detail string is where "index is still importing" shows up.
      item.title = String(info?.detail ?? 'no detail');

      return item;
    });

    dom.health.replaceChildren(...(items.length > 0
      ? items
      : [el('span', 'health-item is-unknown', 'no engines reported')]));
  } catch (error) {
    dom.health.replaceChildren(el('span', 'health-item is-down', `health check failed: ${error?.message ?? error}`));
  }
}

/* ----------------------------------------------------------------- pinning */

function pin(column, suggestion) {
  dom.pinned.hidden = false;

  const method = methodLabel(state.method[column]);
  const head = el('p', 'pinned-label', suggestion.label);
  const meta = el('p', 'pinned-meta', `${method} · ${suggestion.type} · filter: ${suggestion.filter}`);
  const body = el('pre', null, JSON.stringify(suggestion, null, 2));

  dom.pinnedBody.replaceChildren(head, meta, body);
}

function unpin() {
  dom.pinned.hidden = true;
  dom.pinnedBody.replaceChildren();
}

/* ---------------------------------------------------------------- keyboard */

function applyCursor(column) {
  const ui = dom.columns[column];
  const index = state.cursor[column];

  Array.from(ui.list.children).forEach((row, i) => {
    row.classList.toggle('is-active', i === index && state.focus === column);
  });

  ui.root.classList.toggle('is-focused', state.focus === column);

  if (index >= 0 && state.focus === column) {
    ui.list.children[index]?.scrollIntoView({ block: 'nearest' });
  }
}

function rowCount(column) {
  return dom.columns[column].list.children.length;
}

function moveCursor(delta) {
  const column = state.focus;
  const count = rowCount(column);

  if (count === 0) {
    return;
  }

  const next = state.cursor[column] + delta;

  state.cursor[column] = Math.max(0, Math.min(count - 1, next < 0 ? 0 : next));
  COLUMNS.forEach(applyCursor);
}

function switchColumn(column) {
  state.focus = column;

  if (state.cursor[column] < 0 && rowCount(column) > 0) {
    state.cursor[column] = 0;
  }

  COLUMNS.forEach(applyCursor);
}

function hasHighlight() {
  return state.cursor[state.focus] >= 0;
}

function activeSuggestion() {
  const slot = state.slots[state.focus];
  const index = state.cursor[state.focus];

  if (!slot || slot.status !== 'ok' || index < 0) {
    return null;
  }

  return slot.result.suggestions?.[index] ?? null;
}

function onKeydown(event) {
  // Leave the native widgets alone; arrows and Enter belong to them. That now
  // includes the two method dropdowns, which live inside the columns.
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
    switchColumn(event.key === 'ArrowRight' ? 'right' : 'left');

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

    // Innermost thing first: a panel the user just opened is what they meant
    // to close, not the query they spent five keystrokes typing.
    if (COLUMNS.some((column) => !dom.columns[column].help.hidden)) {
      closeAllMethodHelp();

      return;
    }

    if (hasHighlight()) {
      state.cursor = { left: -1, right: -1 };
      COLUMNS.forEach(applyCursor);

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

for (const column of COLUMNS) {
  const ui = dom.columns[column];

  ui.select.addEventListener('change', () => selectMethod(column, ui.select.value));
  ui.root.addEventListener('mousedown', () => switchColumn(column));

  ui.helpToggle.addEventListener('click', (event) => {
    // Without this the document-level handler below closes the panel again in
    // the same click that opened it.
    event.stopPropagation();
    toggleMethodHelp(column);
  });

  ui.help.addEventListener('click', (event) => event.stopPropagation());
}

document.addEventListener('click', closeAllMethodHelp);
document.addEventListener('keydown', onKeydown);
dom.unpin.addEventListener('click', unpin);

// Document counts move while an import runs, so the strip is re-checkable.
dom.health.title = 'click to re-check';
dom.health.addEventListener('click', loadHealth);

// Validated against the hardcoded list here and re-validated against the
// server's list the moment loadHealth() answers.
restoreMethods();
populateMethodPickers();
COLUMNS.forEach(applyMethodToDom);
renderTrend();

loadHealth();
dom.q.focus();
