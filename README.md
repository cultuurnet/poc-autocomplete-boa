# Address autocomplete POC — MySQL vs Elasticsearch 8

A proof of concept for the "locatie suggesties" autocomplete endpoint described in
*Zoek op route & locatie* (briefing 17/09, in the repo root). It indexes the Flemish address
register plus the UiTdatabank place export, and answers type-ahead queries on place, street,
postcode and municipality — the same data, the same request, through **two independent
engines** so their speed and their result quality can be compared side by side.

The POC exists to answer one question: **which engine do we build the endpoint on?** It is
not a prototype of the endpoint itself. There is no geocoding waterfall and no Search API
integration — only the two stores, loaded identically, and the instrumentation needed to tell
them apart.

Plain PHP 8.3, no framework. Composer for autoloading and two client libraries. Everything
runs in Docker.

## Prerequisites

- **Docker** with Compose v2 (`docker compose`, not `docker-compose`). The stack wants ~2 GB
  of RAM free: Elasticsearch is pinned to a 1 GB heap and MySQL to a 1 GB buffer pool, both
  deliberately, so the benchmark measures the engines rather than swapping.
- **An address CSV.** There is no default filename — every import names its file with
  `--csv/-f`, or `make import CSV=...`. The file below is 591 MiB and **not in git**. Download
  the combined Belgian address export from
  <https://data.gov.be/nl/datasets/fpsbosa-dis-best-csv-deriv> — the `BE-VLG` (Flanders)
  extract is the file this POC reads. The export is refreshed weekly; any recent copy works
  as long as the header still matches `src/Import/CsvColumns.php::EXPECTED_HEADER`, which the
  importer verifies before it writes anything.
- Free ports **8080**, **3307** and **9200** (see [Troubleshooting](#troubleshooting)).

No PHP or Composer on the host: everything runs inside the `php` container.

## Setup from zero

```
make up                                        # build the php image, start mysql + es + nginx, wait for healthy, composer install
make import CSV=openaddress-bevlg.csv          # street + municipality + postcode documents into both engines
make import-places CSV=export_places_udb.csv   # the UiTdatabank places, into the same two stores
make health                                    # confirm both engines hold the same document count
open http://localhost:8080
```

| Step | What it does | How long |
|---|---|---|
| `make up` | Builds the PHP image, pulls MySQL 8.4 and Elasticsearch 8.19.12, starts all four containers, blocks until their healthchecks pass, then runs `composer install` if `vendor/` is missing | Several minutes cold (image pulls dominate); **~4 s** warm |
| `make import CSV=...` | One pass over the 591 MiB CSV, aggregating it into 82,643 documents, written to both engines. `CSV=` is required — there is no default filename | **~38 s** |
| `make import-places CSV=...` | One pass over the 21 MiB place export, 62,709 documents, written to both engines. Optional: the address register stands on its own | **~10 s** |
| `make health` | Reads the configured paths and asks both engines for a document count | < 1 s |

`make up` is idempotent and is the only command needed to spin the stack up — rerunning it on
a running stack is a no-op that costs a few seconds and touches no data.

A full street-level import, measured on the stack as configured:

| | |
|---|---|
| documents produced | **82,643** (81,841 street, 285 municipality, 517 postcode) |
| wall clock | 37.9 s (shared CSV pass + both engines) |
| MySQL write time | 6.2 s (13,284 docs/s) |
| Elasticsearch write time | 7.4 s (11,205 docs/s) |
| peak PHP memory | 85 MiB |
| stored size | 36 MiB MySQL table, 41 MiB Elasticsearch index |

The place import on top of it:

| | |
|---|---|
| documents produced | **62,709** (of 64,301 rows; see the rejection table below) |
| wall clock | 10.0 s (shared CSV pass + both engines) |
| MySQL write time | 2.5 s (25,101 docs/s) |
| Elasticsearch write time | 6.7 s (9,352 docs/s) |
| peak PHP memory | 24 MiB |

Write time is reported per engine and excludes the CSV pass, which is shared: the file is read
**once** and every document is fanned out to both indexers. Running the pipeline twice would
double the slowest part of the job and — worse — could let the two engines see different input.

## What gets indexed

**Two exports, one index.** Both write into the same MySQL table and the same Elasticsearch
index, and each import command clears only the document types it owns, so neither can destroy
the other's documents. They are separate commands because they are separate files on separate
release cycles, not because they produce separate stores.

### The address register — `import`

`openaddress-bevlg.csv` holds **4,215,562 rows**, one per individual address in Flanders, of
which **3,884,621** are usable (status `current`, with a postcode and coordinates). That is
the wrong granularity for autocomplete — nobody types a house number to find a street — so
the import aggregates it.

### The place export — `import-places`

`export_places_udb.csv` holds **64,301 UiTdatabank places**, one row per venue, already at
the granularity someone would pick. **62,709** are indexed; see the rejection table below.

| Document type  | Count     | Search API filter | Example label                                 |
|----------------|-----------|-------------------|-----------------------------------------------|
| `place`        | 62,709    | `place`           | `Yper Museum (Grote Markt 34, 8900 Ieper)`    |
| `street`       | 81,841    | `coordinates`     | `Wolterslaan, 9040 Sint-Amandsberg (Gent)`    |
| `municipality` | 285       | `region`          | `Gent`                                        |
| `postcode`     | 517       | `region`          | `9040 Sint-Amandsberg`                        |
| `address`      | 3,884,621 | `coordinates`     | `Goorbaan 59, 2230 Herselt`                   |

`street`, `municipality` and `postcode` are what `--level=street` (the default) produces:
82,643 documents. `address` documents are off by default and exist only to measure what the
two engines do at ~3.9M documents instead of 83k. The normal working set is therefore
**145,352 documents** — the street level plus the places.

Street documents carry the **average coordinate** of their addresses and an **address count**
that doubles as a popularity signal — the register has no population data, and without some
prior the ranking cannot tell a 3-address alley from a main road.

The `coordinates` / `region` / `place` split on every suggestion is the distinction the
briefing asks for: point suggestions feed the Search API `coordinates` filter, area
suggestions feed `region`. Municipality and postcode documents carry the NIS code for that
mapping. A place is neither: it is already an identified entity, so its id (`place:<cdbid>`)
is what a `location.id` filter wants, and it is the one document type that carries **no
coordinates and no NIS code** — the export simply has none.

### What a place document is made of

`name` and `address` in the export are JSON keyed by language. The Dutch value wins where
there is one (63,224 of 64,301 rows), otherwise FR, DE, EN, then whatever is there; every
*other* language's name is indexed as an alias, so "Musée Yper" finds the Yper Museum. The
`description` column is deliberately never indexed — it is prose about what happens at a
place, and feeding it to the haystack would make every document match nearly every query.

A locality written `Onkerzele (Geraardsbergen)` (9,557 rows) is split into its sub-locality
and its municipality, so the place is findable by either and its label renders the address
exactly the way a standalone address suggestion does.

Places carry a **fixed popularity of 24**, which is the median number of addresses on a
Flemish street. The export has no usage signal at all, and both engines damp popularity
logarithmically and need a non-zero value (Elasticsearch's `log1p` factor multiplies a
zero-popularity document's whole score by zero). 24 puts a place level with a typical street
on that term and leaves the ordering to the tier below, where it is stated on purpose.

### Rows the place import rejects

| Reason | Rows | Why |
|---|---|---|
| not in Belgium | 1,352 | `addressCountry` is NL, DE, FR, AT, … |
| unusable postcode | 239 | `addressCountry` says BE but the postcode is not four digits — `59000 Lille`, `5521 ND Bergeik`, `5113BV Baarle-Nassau`. These are foreign addresses mislabelled in the export. |
| no address | 1 | no parseable address object at all |

The four-digit rule is not fussiness: the `postcode` column, the `--postcode` filter and the
postcode suggestions are all built on that shape. Every rejection is counted and printed by
the import, because "why is my place missing" is a question that gets asked.

## CLI reference

Everything lives behind `bin/console` inside the `php` container. Two equivalent ways to reach
it:

```
make import CSV=openaddress-bevlg.csv ARGS="--limit=200000"            # via make
docker compose exec php php bin/console import \
  -f openaddress-bevlg.csv --limit=200000                              # raw
```

The Makefile only wraps the combinations that get used often, and each wrapper hard-codes some
flags (`make import` always passes `--engine=all --recreate`). **`ARGS="..."` appends to that**,
it does not replace it. The file to read is always named explicitly, as `CSV=` via make or
`--csv/-f` raw. Anything the Makefile does not cover — `--level=address`, `--postcode`, an
import *without* `--recreate` — use the raw form.

Global flags from Symfony Console apply to every command: `-h/--help`, `-v`/`-vv`/`-vvv`,
`-q/--quiet`, `--silent`, `--no-ansi`, `-n/--no-interaction`, `-V/--version`.
`docker compose exec php php bin/console list` prints the command list.

### `import`

Loads the register into MySQL and/or Elasticsearch from a single CSV pass.

| Option | Values | Default | What it does |
|---|---|---|---|
| `--engine` | `all`, `mysql`, `elasticsearch` | `all` | Which stores to write to. With `all`, an engine that is down is reported and skipped; the other one is still imported. |
| `--level` | `street`, `address`, `all` | `street` | Which document types to produce — see the table below. |
| `--limit` | integer | none | Stop after N *usable* CSV rows (rows failing the status/postcode/coordinate checks are not counted). |
| `--postcode` | e.g. `2230`, repeatable | all | Keep only these postcodes. Still reads the whole file. |
| `--recreate` | flag | off | Delete this export's own documents (`address`, `street`, `municipality`, `postcode`) before writing, leaving imported places alone. Without it, documents are upserted into whatever is already there — which refreshes what changed but never removes what disappeared from the export. |
| `-f`, `--csv` | path | `CSV_PATH` env, otherwise **required** | Which file to import. Relative paths resolve against the working directory, which is `/app` in the container — the project root is mounted there, so any file under it works. Via make: `make import CSV=data/other.csv`. |

`--level` in terms of what it costs:

| `--level` | Documents | Cost |
|---|---|---|
| `street` | 82,643 — aggregated streets plus municipalities and postcodes | one CSV pass, ~38 s |
| `address` | 3,884,621 — one per house number, no aggregates | two CSV passes (the aggregate pass still runs, because address documents borrow their street's popularity), tens of minutes, several GB on disk |
| `all` | 3,967,264 — both of the above | same two passes, everything written |

Only `street` is the level the endpoint would actually serve. `address` is a load test.

#### Worked examples

```bash
# Full street-level import into both engines, from scratch. The normal case.
make import CSV=openaddress-bevlg.csv
docker compose exec php php bin/console import --engine=all --recreate -f openaddress-bevlg.csv

# Quick partial import for iterating: first 200k usable rows.
# ~8 s, 50,988 documents (50,268 streets, 266 municipalities, 454 postcodes).
make import CSV=openaddress-bevlg.csv ARGS="--limit=200000"
docker compose exec php php bin/console import --engine=all --recreate \
  -f openaddress-bevlg.csv --limit=200000

# One engine only -- useful when you are changing that engine's mapping or schema
# and do not want to wait for the other.
make import-mysql CSV=openaddress-bevlg.csv
make import-es CSV=openaddress-bevlg.csv
docker compose exec php php bin/console import --engine=elasticsearch --recreate \
  -f openaddress-bevlg.csv

# One municipality's postcodes. Still scans the whole file (~21 s) because the
# filter is applied per row, but produces a 573-document index you can eyeball.
docker compose exec php php bin/console import \
  --engine=all --recreate -f openaddress-bevlg.csv --postcode=2230 --postcode=2260

# House-number level, ~3.9M documents. Slow, and the point of the exercise.
make import-addresses CSV=openaddress-bevlg.csv
docker compose exec php php bin/console import --engine=all --level=all --recreate \
  -f openaddress-bevlg.csv

# Any other file. Relative paths resolve against /app, absolute ones are taken
# as given. The filename carries no meaning; only the header has to match.
make import CSV=data/other.csv
docker compose exec php php bin/console import --recreate --csv=/app/data/other.csv
```

The CSV is validated before anything destructive happens — a missing file, a directory, an
unreadable file, an empty file or a wrong header all fail with a message naming the path and
exit `2`, with the table and index untouched:

```
$ docker compose exec php php bin/console import -f data/nope.csv --recreate

 [ERROR] CSV file not found: data/nope.csv
         Relative paths resolve against /app (the project root inside the container).
```

To import into a throwaway table and index instead of the real ones, override the environment
rather than adding a flag:

```bash
docker compose exec \
  -e MYSQL_TABLE=scratch_suggestions -e ELASTICSEARCH_INDEX=scratch_suggestions \
  php php bin/console import --engine=all --recreate -f openaddress-bevlg.csv --limit=200000
```

### `import-places`

Loads the UiTdatabank place export. The same options as `import` minus `--level`: the address
register is imported at a chosen granularity because it holds 4.2M house numbers nobody
types, while this export is already one row per thing a user would pick.

| Option | Values | Default | What it does |
|---|---|---|---|
| `--engine` | `all`, `mysql`, `elasticsearch` | `all` | As for `import`. |
| `--limit` | integer | none | Stop after N CSV rows (counted before the rejection rules, unlike `import`). |
| `--postcode` | e.g. `2230`, repeatable | all | Keep only these postcodes. |
| `--recreate` | flag | off | Delete the existing `place` documents first, leaving the address register alone. |
| `-f`, `--csv` | path | `CSV_PATH` env, otherwise **required** | Which file to import. |

```bash
# The normal case: ~63k places into both engines, ~10 s.
make import-places CSV=export_places_udb.csv
docker compose exec php php bin/console import-places --engine=all --recreate \
  -f export_places_udb.csv

# Both exports from scratch, in order.
make import CSV=openaddress-bevlg.csv
make import-places CSV=export_places_udb.csv

# Or in one go.
make reset CSV=openaddress-bevlg.csv PLACES=export_places_udb.csv

# One municipality, for eyeballing.
docker compose exec php php bin/console import-places --engine=all --recreate \
  -f export_places_udb.csv --postcode=8900
```

The header is checked before anything is deleted, so pointing this command at the address
register (or `import` at the place export) fails with a message naming both headers and exits
`2` with the index untouched.

**Neither import drops its store any more.** That is what keeps the two exports from
destroying each other, but it also means a change to `sql/schema.sql` or to
`ElasticsearchMapping` is *not* picked up by re-importing: the table and the index already
exist, so `prepare()` leaves them as they are and the Elasticsearch bulk write then fails with
`strict_dynamic_mapping_exception` on any field the old mapping does not know. After a schema
or mapping change, drop the stores first — `make destroy` (wipes the volumes), or by hand:

```bash
docker compose exec mysql mysql -uautocomplete -pautocomplete autocomplete \
  -e "DROP TABLE IF EXISTS location_suggestions"
curl -X DELETE http://localhost:9200/location_suggestions
```

### `benchmark`

Runs the same query set against every selected method and reports latency and result quality.

| Option | Default | What it does |
|---|---|---|
| `--queries` | `benchmark/queries.txt` | Query file, one per line; `#` comments and blanks ignored, duplicates dropped. |
| `--golden` | `benchmark/golden.json` | Curated expectations to score against. |
| `--iterations` | `20` | Timed runs per query per engine. |
| `--warmup` | `5` | Runs discarded before timing starts. |
| `--limit` | `10` | Suggestions requested per query — also the *k* in overlap@k and hit@k. |
| `--engine` | `all` | `all` (all six methods), `es` (the five Elasticsearch ones), `mysql`, or any comma-separated list of method keys. **The first one selected is the agreement baseline**, so `--engine=elasticsearch,es-sayt` measures sayt against the incumbent, while the default measures everything against MySQL. A single engine leaves nothing to compare and the agreement report is skipped. |
| `--no-fuzzy` | off | Disable fuzzy matching on every selected method. The single most interesting switch: MySQL has no native fuzziness, so this is where the two diverge. |
| `--format` | `table` | `table`, `json` or `csv`. |

```bash
make benchmark                          # aggregate latency + quality, all six methods
make benchmark ARGS="--engine=es"       # the five Elasticsearch methods against each other
make benchmark ARGS="-v"                # per-query breakdown
make benchmark ARGS="--no-fuzzy"        # how much of ES's lead is fuzziness
make benchmark ARGS="--format=json"     # machine readable
docker compose exec php php bin/console benchmark --iterations=5 --warmup=2   # fast pass
```

Defaults now run 106 queries × 6 methods × 25 calls = 15,900 requests, so `--engine=es` or
`--iterations=5 --warmup=2` is the faster iteration loop.

### `health`

| Option | Values | Default | What it does |
|---|---|---|---|
| `-f`, `--csv` | path | `CSV_PATH` env, otherwise unset | Report on this file. Resolved exactly as `import` resolves it, so the readability line describes the file an import would actually read. Unset is not a failure — the CSV row reads `not set`, and only the engines decide the exit code. |

Prints the resolved configuration (hosts, table, index, CSV path and whether the
CSV is readable) and then asks each engine whether it is up and how many documents it holds.
Exits non-zero if either engine is unhealthy. The same information is available as JSON at
`GET /api/health`.

### Other make targets

| Target | What it does |
|---|---|
| `make up` / `make down` | Start / stop the stack. `down` keeps the data volumes. |
| `make build` | Rebuild the php image only. |
| `make install` | `composer install` inside the php container. |
| `make logs` | Follow all service logs. |
| `make shell` | Bash in the php container (working dir `/app`). |
| `make mysql-cli` | `mysql` client on the `autocomplete` database. |
| `make import-places` | `import-places --engine=all --recreate`. Needs `CSV=`. |
| `make reset` | `destroy` + `up` + `install` + `import`. The full from-scratch rebuild. Passes `CSV=` and `ARGS=` through to the import; add `PLACES=export_places_udb.csv` to load the places too. |
| `make destroy` | Stop the stack **and delete the volumes** — this wipes every imported document. |

## The comparison UI

`http://localhost:8080` is one search box over two result columns. **Each column has a
method picker above it**, so the comparison is any method against any other — MySQL against
the incumbent Elasticsearch query as before, or two Elasticsearch methods against each other.
Both choices are remembered in `localStorage`, and changing one re-runs only that column and
resets only that column's rolling median (a median mixing two methods would be a lie). The **?** next
to a column's method name explains in two or three plain sentences what that method actually
does, which is the difference between reading the comparison and guessing at it.

Per keystroke it shows, for each column:

- the engine's **own measured query time** (`took_ms`, what the API would report) and the
  browser **round-trip time** — the gap between them is HTTP plus PHP overhead, which is not
  the engine's fault but is real
- a **rolling median** over the last queries, so the judgement is not made on one noisy sample
- which results appear in **only one** engine's list (`only in mysql`), and for shared results
  the rank they hold in the other column (`es #4 ↑`) — disagreement is where the interesting
  differences are
- the per-result score and, behind the *why this rank* toggle, the scoring detail that engine
  reported: for MySQL the individual ranking components, which it computes anyway, and for
  Elasticsearch its `_explanation` tree — but only when the **Explain** checkbox in the header
  is ticked. It is off by default because the explanation is several times the size of the hit
  and Elasticsearch has to do extra work to produce it, so the latency numbers shown with it on
  are not comparable to the ones shown with it off

Limit, fuzzy matching and document types are switchable in the header, because the engines
diverge most sharply on typo tolerance. Arrow keys move between columns and rows, Enter pins
a suggestion so its full document stays visible while you keep typing, Esc clears.

The same data is available directly:

```bash
curl "http://localhost:8080/api/suggest?q=gent&limit=5"
curl "http://localhost:8080/api/suggest?q=kerkstraat&types=street,municipality&fuzzy=0"
curl "http://localhost:8080/api/health"
```

`q` is capped at 120 characters, `limit` at 50, `types` is a comma-separated subset of
`address,street,municipality,postcode`, `fuzzy` defaults to on and `explain` to off. One
failing engine degrades to an empty column carrying an `error` string rather than taking the
request down.

`explain=1` asks Elasticsearch for its `_explanation` tree and returns it under each
suggestion's `debug`. It makes the request measurably more expensive to serve, so it is off
unless asked for and should never be on while benchmarking:

```bash
curl "http://localhost:8080/api/suggest?q=goorbaan&limit=1&explain=1&engine=elasticsearch"
```

## The benchmark

Latency: warm-up iterations are discarded, then the engines are **interleaved per iteration**
so a transient system hiccup hits both equally. Reported as min / p50 / p90 / p95 / p99 / max,
plus mean and stddev, using the time each engine measured for itself.

Read the **percentile spread**, not the mean. A representative run at 82,643 documents:

| engine | p50 | p90 | p95 | p99 | max |
|---|---|---|---|---|---|
| mysql | 2.60 | 7.51 | 8.78 | 16.45 | 35.74 |
| elasticsearch | 2.38 | 3.71 | 4.26 | 7.89 | 17.27 |

The medians are a coin flip; the tail is not. For a type-ahead field, the tail is what the
user feels.

Quality, measured two ways:

1. **Agreement** — overlap@k, Jaccard and Spearman rank correlation between the two engines,
   plus the list of queries where they disagree most. Needs no ground truth, and points
   straight at the queries worth looking at by hand. Low overlap with an identical top-1 is
   usually harmless; a different top-1 is not.
2. **Golden set** — `benchmark/golden.json` holds curated expectations derived from the real
   data, scored as hit@1 / hit@3 / hit@10 and MRR, with a table of the entries neither engine
   ranked first. This is the only measure that can say which engine is *right* rather than
   merely different.

The closing **Verdict** block states which engine won on latency and on the golden set, with
the numbers behind the claim.

The query set in `benchmark/queries.txt` deliberately includes the cases that discriminate:
progressive prefixes, wrong word order, typos, bare postcodes, and street names like
*Kerkstraat* that exist in almost every municipality — the hardest ranking case in the dataset.

## How each engine works

Both index the **same folded text**. `src/Support/Normalizer.php` lowercases, strips
diacritics and drops punctuation; Elasticsearch reproduces exactly that chain with
`lowercase` + `asciifolding`. If the two folded differently, the benchmark would be measuring
preprocessing rather than the engines.

**MySQL** — one `location_suggestions` table with a `FULLTEXT` index over the folded
haystack, queried in boolean mode with `+token*` per token (prefix semantics, all tokens
required). `innodb_ft_min_token_size=1` is set in `docker/mysql/my.cnf`, without which any
one- or two-character prefix would return nothing, and the (English) stopword list is disabled
because Dutch street names contain words that are in it. Ranking combines the `MATCH` score, a
bonus when the *name* (not just the city) starts with what you typed, and a log-damped
popularity boost. MySQL has no native fuzzy matching, so typo tolerance is a deliberate
best-effort second pass.

**Elasticsearch** — edge-ngram analysis at index time with a plain folding analyzer at search
time, `must` clauses that gate recall (finished words against whole-word terms, the token
still being typed against the n-grams, and only the tokens that name a place at all), boosted
`should` clauses for precision, and a `function_score` applying the same log-damped
popularity. Fuzziness is native.

**Ordering across document types** is the one thing neither engine decides by score alone.
Results are grouped into two tiers first — `municipality`, `postcode` and `place` above
`street` and `address` — and score only decides the order *inside* a tier. The tier is one
decision, defined once in `SuggestionType::rankTier()`; MySQL renders it as a `CASE` in the
`ORDER BY` of every pass, Elasticsearch stores it as a `rank_tier` byte and sorts on it ahead
of `_score`.

It has to be a tier rather than a weight. A place and an address document can describe the
same address, and on text the address wins every time — it is *named* after the street you
typed, while the venue standing on it is not — so no boost small enough to be safe is large
enough to overturn it. A tier states the preference directly: for `markt 62 berlaar`,
`Baristik (Markt 62, 2590 Berlaar)` is listed above `Markt 62, 2590 Berlaar`. Municipality
and postcode share the top tier with `place` rather than sitting above it, because their
existing score prior already wins the case that matters — a bare `gent` answers with the city
and not with the 56 places called Gent — while a tier above would make `markt 62 berlaar`
lead with the town.

The asymmetry in that last sentence is the point of the POC. Read the benchmark output rather
than this paragraph.

That paragraph describes the **incumbent** Elasticsearch method. It is now one of five, all
querying the same index and all selectable per column in the UI and per run in the benchmark
— see [The five Elasticsearch methods](#the-five-elasticsearch-methods).

## The five Elasticsearch methods

Elasticsearch has more than one way to do type-ahead, and they differ mainly in *where* the
work happens: in the index, or in the query. The four alternatives to the incumbent come from
Elastic's own overview,
[Elasticsearch autocomplete search](https://www.elastic.co/search-labs/blog/elasticsearch-autocomplete-search)
— its *performance consideration* section is the one that matters here — plus `index_prefixes`,
which that article does not cover but which is the obvious native alternative to a hand-built
n-gram filter.

All five live in **one index over the same documents**, and they share everything except the
text-matching clauses: the same `doc_type` filter, `size`, `_source`, the same exact-name,
postcode, municipality, alias and house-number ranking signals, and the same `function_score`
with the same popularity damping and per-type weights. The retrieval method is the only
variable, which is the only reason the numbers below mean anything. Boosts were deliberately
**not** re-tuned per method: if a method ranks worse, that is the finding.

| key | method | extra index | how the trailing token is matched |
|---|---|---|---|
| `elasticsearch` | edge n-grams 1–20 at index time (**the incumbent**) | 12.9 MB | it is already a term — plain `match` |
| `es-prefixes` | `index_prefixes` 1–19 on a plain text field | 12.9 MB | term lookup in Lucene's hidden `_index_prefix` field |
| `es-sayt` | `search_as_you_type` (`._2gram`, `._3gram`, `._index_prefix`) | 38.8 MB | `multi_match` / `bool_prefix` across the shingle family |
| `es-bool-prefix` | **none** — reuses the plain whole-word fields | 0 MB | an honest prefix scan over the term dictionary |
| `es-completion` | `completion` field (FST) with a `doc_type` context | 6.1 MB | walks the FST from the first character |

`es-completion` is not a search and cannot pretend to be one. The suggest API takes no query
and no filter beyond its contexts, and ranks **only** by the weight indexed with the document
(popularity here), never by how well the text matched. It cannot reorder multi-token input,
cannot see house numbers, and returns no meaningful total. It is in the comparison as the
latency floor — the number that says what the other four are paying for their flexibility —
and not as a candidate.

### House numbers: `HOUSE_NUMBERS_INDEXED`

The locative/detail split in `SuggestQuery` — house numbers and box references are kept out of
the recall gate and only allowed to influence ranking — was written against a **street-level**
index, where it is not a heuristic but a necessity: no document carries a house number, so
requiring `12` returns nothing for `kerkstraat 12 gent`, which is a completely ordinary way to
write an address.

Build the index with `--level=address` or `--level=all` and that premise is simply false. The
number becomes the most selective thing the user typed, and demoting it to a ranking signal
buries the one document they asked for. So the logic is not dead code to delete; it belongs to
one shape of index. `HOUSE_NUMBERS_INDEXED` names which shape you have.

Measured on the 4,029,990-document index, top hit and number of candidates admitted by the
gate:

| query | `=0` (the default) | `=1` |
|---|---|---|
| `kerkstraat 12 gent` | 413 candidates → *Junior Argonauts Gentbrugge* | **2** → **Kerkstraat 12, 9050 Gent** |
| `veldstraat 10 9000 gent` | 112 → *Fnac Gent* | **1** → **Veldstraat 10, 9000 Gent** |
| `goorbaan 59 2230 herselt` | 45 → *Goorbaan* (the street) | **1** → **Goorbaan 59, 2230 Herselt** |
| `grote markt 1 2000 antwerpen` | 139 → *Antwerpen - Grote Markt* | **6** → **Grote markt 1, 2000 Antwerpen** |
| `goorbaan 59` | 45 → Goorbaan 59 (already correct) | 2 → Goorbaan 59 |
| `gent`, `12` | unchanged | unchanged |

**Box references are excluded from the gate either way**, and that is not an oversight:
`SuggestionDocument` never writes `box_number` into `search_text`, so gating on `bus 5` matches
nothing by construction. The first cut of this flag got that wrong and turned
`kerkstraat 12 bus 5 gent` into zero results — a worse failure than the one it was fixing.
`SuggestQuery::detailIndices()` now distinguishes the two: the house number is detail only
while the index lacks house numbers, the box reference is detail always, and the token after a
box marker goes with it.

Default `0`, because `--level=street` is the import default. Getting it wrong is not fatal in
either direction: `0` on an address index costs precision, `1` on a street index costs recall
on any query containing a number. Set it in `.env` or the environment:

```bash
HOUSE_NUMBERS_INDEXED=1 docker compose up -d
```

Nothing about this is Elasticsearch-specific — the flag lives in `SuggestQuery`, so all five
Elasticsearch methods and MySQL see the same split.

### What the methods cost to index

Measured twice: at **street level** (82,643 documents, `--level=street`) and at **address
level** (4,029,990 documents — the full house-number file plus the UiTdatabank places), both
force-merged to one segment and measured with
`POST /<index>/_disk_usage?run_expensive_tasks=true`.

| | street level, 82,643 docs | address level, 4,029,990 docs |
|---|---|---|
| index size, incumbent mapping only | 40.8 MB | — |
| index size, all five methods | 107.5 MB | **3.5 GB** |
| Elasticsearch write time | 39.3 s (2,102 docs/s) | **1,131.9 s (3,505 docs/s)** |
| MySQL write time | — | 305.0 s (13,007 docs/s) |

**This is the part worth pausing on.** 49× the documents costs 33× the index and 29× the
import. Elasticsearch writes *faster per document* at address level (3,505/s against 2,102/s)
because an address document carries far less text than an aggregated street document — the
cost is volume, not complexity. But it is still **3.7× slower than MySQL** on the same
documents in the same pass, and that gap is entirely the five methods' index-time machinery:
edge n-grams, two prefix indexes, the shingle family and the FST all have to be built for
every one of those four million documents. A nineteen-minute import is a different operational
proposition from a forty-second one.

Per method, the fields it alone needs:

| method | fields | street level | address level |
|---|---|---|---|
| `es-bool-prefix` | — (reuses `search_text.folded`, `primary_name.folded`) | **0.0 MB** | **0 MB** |
| `es-completion` | `suggest` | 6.1 MB | 253 MB |
| `elasticsearch` | `search_text` + `primary_name` | 12.9 MB | 376 MB |
| `es-prefixes` | the two `._index_prefix` structures | 12.9 MB | 376 MB |
| `es-sayt` | eight subfields; `search_text_sayt._index_prefix` alone is 904 MB | 38.8 MB | 1,299 MB |

The proportions survive the change of scale almost exactly, which is the useful part: these
ratios are a property of the methods, not of this corpus. `search_as_you_type` is a third of
the whole index at both sizes.

Two things worth stating plainly, because this README previously guessed at both:

- **`index_prefixes` is not the cheap option.** At 1–19 it stores very nearly what the edge
  n-gram filter stores, and costs the same to within 1%. The saving people expect from it
  comes from the *default* 2–5 range, not from the mechanism. What it does buy is native
  behaviour: no `MAX_GRAM` cliff and no `preserve_original` workaround, because a term longer
  than `max_chars` still matches, just by scanning.
- **`search_as_you_type` is expensive**, exactly as the article warns: three times the
  incumbent's index for this data, most of it the `_index_prefix` of the shingled catch-all
  field. It is the convenient option, not the cheap one.

### What the methods cost to query

106 queries × 20 iterations, engine-reported `took`, single node, single shard, warm, nothing
else running. Street level on the left, address level on the right — same queries, same
machine, 49× the documents:

| method | p50 | p95 | max | | p50 | p95 | max |
|---|---|---|---|---|---|---|---|
| | *82,643 docs* | | | | *4,029,990 docs* | | |
| `elasticsearch` | 2.41 | 5.04 | 62.20 | | 2.91 | 5.31 | 18.79 |
| `es-prefixes` | 2.29 | 5.12 | 36.62 | | 2.56 | 4.64 | 17.30 |
| `es-sayt` | 2.31 | 4.73 | 21.95 | | 2.58 | 4.88 | 23.18 |
| `es-bool-prefix` | 2.32 | 5.07 | 33.22 | | 2.70 | 5.85 | **39.25** |
| `es-completion` | **1.11** | 1.96 | 17.81 | | **0.69** | 0.96 | 8.16 |

**Fifty times the corpus costs about 15% at the median.** That is the headline, and it is the
opposite of the indexing story above: writing four million documents is a nineteen-minute job,
but querying them is barely distinguishable from querying eighty thousand. Inverted indexes do
not care much how many documents they do *not* have to look at.

**At both sizes the four search methods are within noise of each other at the median.** The
method with no prefix index at all is as fast as the one paying 376 MB for one. That is not
the result the article leads you to expect.

It is still not a licence to delete the n-grams, and scale sharpens the reason: `es-bool-prefix`
now has the worst p95 (5.85 against 4.64) and the worst tail by a factor of two (39.25 ms).
The scan it does instead of a lookup is invisible at the median and shows up exactly where the
article says it will — in the tail, and on the shortest prefixes:

*Street level, 82,643 documents:*

| query length | `elasticsearch` | `es-prefixes` | `es-sayt` | `es-bool-prefix` | `es-completion` |
|---|---|---|---|---|---|
| 1 char | 4.25 | 3.82 | 3.76 | **5.07** | 1.21 |
| 2 chars | 1.57 | 1.42 | 1.53 | 1.56 | 1.08 |
| 3–5 chars | 1.45 | 1.30 | 1.34 | 1.27 | 1.04 |
| 6+ chars | 2.52 | 2.38 | 2.45 | 2.32 | 1.08 |

*Address level, 4,029,990 documents:*

| query length | `elasticsearch` | `es-prefixes` | `es-sayt` | `es-bool-prefix` | `es-completion` |
|---|---|---|---|---|---|
| 1 char | 2.05 | 1.83 | 1.89 | **6.64** | 0.65 |
| 2 chars | 2.96 | 2.87 | 2.95 | **5.10** | 0.63 |
| 3–5 chars | 2.03 | 2.00 | 2.00 | 1.99 | 0.65 |
| 6+ chars | 3.05 | 2.69 | 2.73 | 2.69 | 0.71 |

**There it is.** On a one-character query the unindexed scan goes from 1.3× slower than the
indexed methods at street level to **3.2× slower** at address level — 6.64 ms against 1.83 —
while the three indexed methods actually get *faster* on short prefixes at the larger corpus.
Two characters show the same shape at 1.8×. Everything from three characters up is identical,
because by then the term dictionary walk is narrow enough not to matter.

The worst single query per method says the same thing: `es-bool-prefix` peaks at 12.77 ms on
`"k"`, while the other three peak on long multi-word queries where the scan plays no part.

So the article is right, and it is right about precisely the case it names: a prefix query
with nothing behind it degrades with corpus size, and it degrades first on the keystroke every
user types. It just costs far less than the framing suggests — 5 ms on four million documents,
on one shard, on a laptop.

### Whether the methods are any good

Against the hand-curated golden set (37 known-item queries with expected answers):

| method | hit@1 | hit@10 | MRR (street) | | hit@1 | hit@10 | MRR (address) |
|---|---|---|---|---|---|---|---|
| `es-bool-prefix` | **35 (95%)** | 37 (100%) | **0.957** | | 17 (46%) | 28 (76%) | **0.546** |
| `elasticsearch` | 34 (92%) | 37 (100%) | 0.943 | | 16 (43%) | 27 (73%) | 0.519 |
| `es-prefixes` | 34 (92%) | 37 (100%) | 0.943 | | 17 (46%) | 28 (76%) | 0.541 |
| `es-sayt` | 33 (89%) | 37 (100%) | 0.929 | | 17 (46%) | 28 (76%) | 0.542 |
| `es-completion` | 13 (35%) | 14 (38%) | 0.365 | | 9 (24%) | 10 (27%) | 0.257 |

**Do not read the address-level column as "the methods got worse".** The golden set expects
`street:` documents, and at address level two other things now outrank them, neither of which
is a property of any method: place documents sort above streets by design
(`SuggestionType::rankTier()`), and the actual house-number document is often a better answer
than the street it belongs to. Of the 23 entries the incumbent does not rank first, **22 are
outranked by a place**. The golden set predates places and is due a re-curation; until then
the column is only useful for comparing the methods *against each other*, which is what it is
for.

And against the incumbent as baseline, over the 105 comparable queries in `queries.txt`:

| method | Jaccard@10 (street) | identical top-1 | | Jaccard@10 (address) | identical top-1 |
|---|---|---|---|---|---|
| `es-bool-prefix` | 0.956 | 101 of 105 | | 0.937 | 97 of 105 |
| `es-sayt` | 0.929 | 100 of 105 | | 0.837 | 85 of 105 |
| `es-prefixes` | 0.846 | 94 of 105 | | 0.806 | 86 of 105 |
| `es-completion` | 0.280 | 42 of 105 | | 0.136 | 30 of 105 |

The methods agree slightly *less* at address level: more documents means more near-ties, and
near-ties are where the retrieval method's fingerprint shows.

The four search methods agree with each other far more than they differ, and where they
differ it is almost entirely on **one- and two-character prefixes** (`go`, `k`, `kerk`),
where nothing in the data justifies one ordering over another and the ranking is decided by
tie-breaks. `es-completion` fails the golden set exactly where its design says it must: every
"street + city" entry is a miss, because the FST only matches a prefix of a single indexed
name and "kerkstraat gent" is a prefix of nothing. It wins the three entries that are bare
locality names, because ranking by popularity alone is the right answer for those.

One recall asymmetry is by design and should not be "fixed": edge n-grams make *every* token
a prefix, while `match_bool_prefix` makes only the last one a prefix. So `korte linden`
matches 5 documents through the incumbent's gate and 3 through the others — higher recall,
lower precision. Which is better is a product question the numbers are meant to inform.

### What this says

- **`es-prefixes` is the method to ship.** It matches the incumbent everywhere, beats it
  slightly on both the golden set and latency at address level, costs the same index, and
  retires the `MAX_GRAM = 20` cliff and the `preserve_original` workaround that exists to
  paper over it. It is the incumbent with less to go wrong.
- **The hand-built edge-n-gram chain is not earning its complexity.** Nothing it does is
  unavailable natively, and two native options match it.
- **A prefix index does earn its keep, but only on the first two keystrokes.** That is the one
  place `es-bool-prefix` loses, and the one place it loses badly and worse with scale (3.2× at
  four million documents). Whether that matters is a product question: 6.6 ms is still fast,
  and it buys back 376 MB and nineteen minutes of import.
- `es-sayt` costs 3.4× the index of `es-prefixes` for the same behaviour. Convenience, not
  capability.
- `es-completion` is a genuine sub-millisecond floor (0.69 ms p50 on four million documents)
  and a genuinely different product: right for a "jump to a city or venue" box, wrong for
  address lookup, where it misses every street-plus-city query by construction.
- **Query latency barely notices the corpus; indexing does.** 49× the documents cost 15% at
  the p50 and 29× the import time. If anything here is going to hurt in production, it is the
  write path, not the read path.

Everything above is one shard, one node, a warm cache and no concurrency. Reproduce with:

```bash
# street level, the default import
make benchmark ARGS="--engine=es --iterations=20"

# address level: import with --level=all, then set the flag (see below)
HOUSE_NUMBERS_INDEXED=1 make benchmark ARGS="--engine=es --iterations=20"
```

## Layout

```
bin/console              CLI entrypoint (import, import-places, benchmark, health)
src/Command/             the four commands, the shared import pipeline and --csv option
src/Import/              CSV reading, aggregation, the two indexers
src/Suggest/             six suggesters behind one interface: MySQL, plus five
                         Elasticsearch methods over two abstract base classes
src/Model/               engine-agnostic query and result types
src/Support/Normalizer   the folding both engines share
src/Http/                the JSON API
public/                  front controller + the comparison UI
sql/schema.sql           readable reference for the MySQL table
benchmark/               query set and golden expectations
docker/                  php, nginx and mysql configuration
```

Configuration is environment variables only, read in `src/Config.php`; `docker-compose.yml`
injects them and `.env.example` documents them. The CSV path is the exception: it is a CLI
option (`--csv/-f`, defined once in `src/Command/CsvPathOption.php` and shared by `import`,
`import-places` and `health`), because it is the one setting that changes per run rather
than per environment.
It has no default — `CSV_PATH` can pin one for an environment that always reads the same
file, and otherwise the option is required. The other defaults are the compose values, so
`bin/console` also runs from the host against the forwarded ports if you copy `.env.example`
to `.env` and use the host values noted in it.

## Troubleshooting

**A port is already in use.** The stack binds `8080` (nginx), `3307` (MySQL — 3306 is usually
taken by a local install, hence the offset) and `9200` (Elasticsearch). Change the host side
of the mapping in `docker-compose.yml` (`"8081:80"`, `"3308:3306"`, `"9201:9200"`) and
`make up` again. Only the host side matters: the containers talk to each other over the
compose network on their internal ports, so nothing else needs changing.

**`health` says `documents: 0`.** The engine is up but nothing has been imported into it —
the volumes are fresh, or `make destroy` ran, or the import failed partway. Run
`make import CSV=...`.
If only one engine shows 0, that engine was down during the import; the importer continues
with whatever is reachable and warns, so re-run for that engine alone
(`make import-es CSV=...` / `make import-mysql CSV=...`).

**`health` says an engine is not ok.** `docker compose ps` — Elasticsearch in particular can
be killed by the OOM killer if Docker Desktop has less memory than its 1 GB heap plus
overhead. `make logs` shows why.

**The import fails immediately with a CSV error.** The message names the path it tried, or
says none was given. Pass the file with `--csv/-f`, or `make import CSV=...`. If the path is
right, the export's header changed — the
importer refuses to guess at column positions, so a changed export fails loud instead of
silently importing the wrong fields. Compare the printed header against
`src/Import/CsvColumns.php`.

**`make destroy` wipes the volumes.** Both the MySQL data directory and the Elasticsearch data
directory are named volumes; `down -v` deletes them and the next import starts from nothing
(another ~38 s). `make down` does not. `make reset CSV=...` is destroy + rebuild + import in
one go.

**Dependencies are not installed.** `bin/console` says so and exits; `make install`.

## Conclusion

**Build the endpoint on Elasticsearch.** Measured over the full 110-query set, 10 timed
iterations after 3 warm-ups, against the same 82,643 documents in both engines:

| | p50 | p95 | max | stddev | hit@10 | MRR |
|---|---|---|---|---|---|---|
| MySQL | 2.50 ms | 8.62 ms | 34.6 ms | 3.74 | 28/30 (93%) | 0.823 |
| Elasticsearch | 2.01 ms | 3.59 ms | 7.25 ms | 0.88 | 30/30 (100%) | 0.929 |

Three things decided it:

1. **Typo tolerance is the real gap, not speed.** On `goorban` Elasticsearch ranks *Goorbaan*
   first and MySQL seventh; on `gnet` MySQL returns nothing at all. MySQL has no edit
   distance, and the best honest workaround here — truncate the longest token, re-rank with
   `levenshtein()` in PHP — only catches errors near the end of a word. For a type-ahead field
   where people misspell Flemish street names constantly, that is not a detail.
2. **The tail matters more than the median.** MySQL is competitive at p50 but four times worse
   at p95 and five times worse at the maximum, because it sorts on a computed expression over
   the whole candidate set and cannot stop at top-k the way Lucene does. Autocomplete fires on
   every keystroke, so p95 is what users actually feel.
3. **Ranking is hand-written arithmetic in MySQL.** Every weight lives in one SQL expression;
   there are no analyzers, no per-field boosts, no way to tune relevance without editing and
   re-reading the whole formula. Elasticsearch gets the same popularity damping declaratively
   and leaves room to grow.

Worth keeping in mind: the two engines agree on only **4.2 of 10** results on average, so this
is a genuine behavioural difference and not two spellings of the same ranking. MySQL is not
disqualified — at street level it is fast and returns a sensible list for well-spelled input,
and it needs no extra infrastructure. If the endpoint ever had to ship without a new service
to operate, it would do. It just loses on the axis that matters most for this feature.

**"Elasticsearch" now means one of five methods.** The recommendation above was measured
with the incumbent edge-n-gram method and still stands, but the follow-up question — which
Elasticsearch method — has its own answer, and it is not the obvious one: the method with no
prefix index at all matches the incumbent on latency at both corpus sizes, and the native
`index_prefixes` beats the hand-built n-gram chain on every axis measured. See
[The five Elasticsearch methods](#the-five-elasticsearch-methods), measured at 82,643 and at
4,029,990 documents.

Neither result speaks to the two modelling questions still open — how UiTdatabank places should rank
against addresses, and whether house-number labels get indexed. Both land the same way on
either engine, so neither one changes the choice above, and both are worth settling before the
real implementation starts.

## Tuning headroom on Elasticsearch

The conclusion picks Elasticsearch partly because it *leaves room to tune*. This is that room,
audited against what the POC actually implements rather than against the manual. None of it is
needed for the comparison above to hold — the two engines are already configured symmetrically
and the numbers stand. It is the list to work through once the endpoint is real, ordered by
what it costs to try.

### Already mapped, never queried

Three fields existed in the mapping and did nothing in the query. Two of them are now wired
up; the third is the geo signal, which is a feature rather than a loose end.

- **`primary_name.keyword`** — *done.* It now carries the `folding_keyword` normaliser, so the
  single term it stores is byte-for-byte what `SuggestQuery::normalized()` sends, and
  `BOOST_NAME_EXACT` (12.0, above `BOOST_NAME_PHRASE_PREFIX`) fires on it. Without the
  normaliser the subfield holds `Goorbaan` while the query side sends `goorbaan`, so the
  "one clause" this used to describe would have matched nothing — the folding is the fix, the
  clause is the easy half.
- **`aliases`** — *done.* Now n-grammed like `primary_name` and carried by its own clause at
  `BOOST_ALIAS` (1.8). Deliberately *not* "scored like a name hit", as the old comment
  claimed: `DocumentSource::aliases()` also files the municipality name under aliases, so a
  name-weighted clause would count the city twice for every document in the index.
- **`location`** (`ElasticsearchMapping.php`) is a `geo_point` that contributes nothing to the
  score. See [Geo](#geo-the-unused-signal) below — it remains the largest single omission, and
  unlike the two above it needs an origin the API does not currently accept.

`label` and `street_name` are now `index: false`, and `house_number` and `box_number` are
`index: false` keywords; all four are read only out of `_source`. `street_name` keeps its
`.keyword` subfield, which is doc_values rather than an inverted index and is what a
`collapse` on the street would read. `label` lost its own — grouping or sorting on a rendered
display string means nothing that grouping on `street_name` does not mean better, and the
subfield was 3.8 MB of the index.

With all of the above applied the index is 40.8 MB, slightly *under* the 41.2 MB it took
before, despite gaining `search_text.folded`, n-grammed `aliases` and a normalised
`primary_name.keyword`.

### Precision, with the index as it stands

No reindex required for any of these.

**Completed tokens are matched as prefixes too** — *done.* The recall gate used to run
*every* token against the edge-n-grammed `search_text`, so in `meir antwerpen` the finished
token `meir` also admitted *Meirbrug*. `ElasticsearchSuggester::gateClauses()` now splits the
query the way `SuggestQuery::completeTokens()` and `::lastToken()` always described it: every
finished token has to match a whole word, against a new non-n-grammed `search_text.folded`
subfield, and only the token still being typed matches a prefix.

Note this needs the `.folded` subfield rather than `primary_name.folded` as first sketched
here — a completed token is very often the municipality or the postcode, which never appear
in the primary name, so gating on the name would have thrown away most multi-token queries.

Measured over the 106-query benchmark set, the narrower gate changes the candidate set for 5
queries, by one or two documents each, and takes no query to zero results. Golden-set MRR
went from 0.895 to 0.929 and hit@1 from 24 to 27 of 30.

One query in the set still comes back empty from Elasticsearch: `straat lange gent`, where
`straat` is a finished token and no document holds it as a whole word — *Straatje* does. That
is the honest cost of whole-word gating, and the fix for it is
[decompounding](#analysis-level-changes), not a looser gate.

**House numbers and box references were gating the search** — *done, and this one was a
plain defect rather than a tuning knob.* Every token had to appear in the document, so the
most ordinary way a Belgian writes an address returned **nothing at all** from
Elasticsearch:

| query | mysql | es before | es after |
|---|---|---|---|
| `kerkstraat 2 gent` | 3 | **0** | 3 — *Kerkstraat, 9050 Gent* |
| `goorbaan 59 herselt` | 1 | **0** | 1 — *Goorbaan, 2230 Herselt* |
| `kerkstraat 12 bus 5` | 3 | **0** | 3 |
| `meir 1 antwerpen` | 3 | **0** | 1 — *Meir, 2000 Antwerpen* |
| `veldstraat 10 9000` | 3 | **0** | 1 — *Veldstraat, 9000 Gent* |

A street document carries no house number, so demanding one finds nothing; `bus` is worse
still, because `SuggestionDocument::searchText()` never puts `box_number` into the haystack at
any level, so the word can never match in either engine.

`SuggestQuery` now splits the tokens into **locative** ones (the street, the postcode, the
municipality — what can narrow a search) and **address detail** (house number, box marker, box
number). Only the locative half reaches the gate; the detail half moves to a `should` at
`BOOST_ADDRESS_DETAIL`, which is what puts number 59 first on an address-level index and
harmlessly scores zero on a street-level one. Query-side only — **no reindex**.

Two details worth knowing. The token still being typed is only treated as a prefix while it is
still part of the place name: in `goorbaan 5` the street is finished, so it is gated as a whole
word. And a query made of *nothing but* detail does not split — on a bare `22` the number is
all the user has given us, so it has to keep narrowing. (That guard earns its keep: `bus` on
its own is a street in Eeklo.)

MySQL was never doing this properly either. Its boolean query for `kerkstraat 12 bus 5` is
`+kerkstraat* +bus*` — it drops `12` and `5` only because they are shorter than
`FULLTEXT_MIN_PREFIX`, keeps `bus`, fails its own fulltext pass, and is rescued by the
`fuzzy_prefix` fallback. It gets the right answer by luck, not by design, which is why the
Elasticsearch fix does not mirror it.

**`minimum_should_match` on the gate** (`"2<-1"`, or a percentage) instead of the hard
`operator: and`. Lowers the zero-result rate on three-token queries, and with it how often the
fuzzy second pass has to fire at all.

**`collapse` on `street_name`.** `kerkstraat` currently answers with ten Kerkstraats in ten
different municipalities. Field collapsing — or a diversifying rescore — is what turns that
top-10 back into something worth showing. Note this interacts with `track_total_hits`.

**`rank_feature` with a `saturation` function** instead of `field_value_factor` + `log1p` for
popularity. Cheaper to execute, and `pivot` is a far more legible knob than a factor whose
damping runs backwards from the intuition (as the comment at `ElasticsearchSuggester.php:437`
already has to apologise for).

### Geo, the unused signal

The briefing is *Zoek op route & locatie*, every street document carries the average
coordinate of its addresses, the field is mapped as a `geo_point` — and ranking ignores it
completely. A `distance_feature` query, or a `gauss` decay function with its origin set to the
user's position or the map viewport's centre, is the highest-value thing missing from the
Elasticsearch side.

`kerkstraat` near Herselt and `kerkstraat` near Gent are different answers to the same string.
Today they are identical, and no amount of boost tuning on the text clauses can separate them.

### Analysis-level changes

These need a reindex, with one exception.

| Change | Why |
|---|---|
| **Search-time synonyms** (`synonym_graph`) | `st↔sint`, `str↔straat`, `dr↔dokter`, `stwg↔steenweg`, `o l v↔onze lieve vrouw`. Belgian address search lives on these abbreviations. As a search-only analyzer this is the one item here that needs **no reindex**. |
| **Dutch decompounding** (`dictionary_decompounder`) | Edge n-grams are prefixes only, so `straat` never finds `Lindenstraat`. Dutch compounds make infix matching a real gap; a targeted street-suffix word list is much cheaper than a full n-gram field. |
| ~~**`index_prefixes`** instead of the edge-n-gram filter~~ **Done and measured** — it is the `es-prefixes` method. It does retire the `MAX_GRAM = 20` cliff and the `preserve_original` workaround, but "substantially smaller index" was wrong: at 1–19 it costs the same as the n-grams to within 1%. See [What the methods cost to index](#what-the-methods-cost-to-index). |
| **`similarity: boolean`** + `index_options: docs` on `search_text` | It is a pure recall gate, yet its BM25 term frequency still leaks into the final score as noise — and TF is close to meaningless on an n-grammed field anyway. |
| **Phonetic matching** (`analysis-phonetic`) | Cologne phonetic or Double Metaphone is a recall tier that is *more precise* than edit distance for proper names: `sint niklaas` / `sint niclaas`. |

Two that look tempting and are not. **Dutch stemming** has no business near proper names. And
**ICU folding** is more correct than the hand-rolled `Normalizer::FOLD` map, but adopting it
breaks the byte-for-byte folding parity with MySQL that this entire comparison rests on — if
it ever goes in, it goes in on both sides or the benchmark stops meaning anything.

### Alternative shapes worth a number

**Two of the three now have their number** — they are implemented as the `es-sayt` and
`es-completion` methods, and measured in
[The five Elasticsearch methods](#the-five-elasticsearch-methods). In short: the honest
control says the hand-built n-gram chain is *not* earning its complexity on this corpus, and
the FST floor is 1.11 ms at p50 against 2.41 ms for the incumbent. The geo context on the
completion field was left out — documents without coordinates become unreachable as soon as a
geo context is queried, which is a trap worth avoiding until geo is actually used.

Still unmeasured:

- **`rescore`** — cheap gate over the window, expensive phrase-prefix and geo clauses only
  across the top N. This is the mechanism that keeps a `--level=address` index of 3.9M
  documents viable rather than merely possible.

### Latency and ops

- **`track_total_hits: false`.** 1000 (`ElasticsearchSuggester.php:47`) still pays for
  collection the UI never reads; it shows ten rows and a "more" indicator.
- **`docvalue_fields`** instead of `_source` for the twelve flat fields in `SOURCE_FIELDS`,
  skipping `_source` decompression per hit.
- **`filter_path` on the response, plus HTTP keep-alive and compression.** The suggester
  already measures `overhead_ms` exceeding `es_took_ms` on small result sets
  (`ElasticsearchSuggester.php:171-176`) and says so in the debug payload. This is the lever
  for the half of the latency that is not search.
- **`refresh_interval: 30s`** in steady state instead of `1s`
  (`ElasticsearchMapping.php:231`). The address register is read-mostly and refreshed weekly;
  near-real-time visibility buys nothing and costs segments.
- **Index sorting by popularity**, which is what makes `terminate_after` safe on the
  one- and two-character prefixes that dominate the latency tail. Also `index.store.preload`.

Force-merging to a single segment after import is already done (`ElasticsearchIndexer.php:159`)
and is a large part of why the p95 above looks the way it does.

### Measuring instead of guessing

- **The `_rank_eval` API** computes precision@k, MRR and NDCG server-side.
  `benchmark/golden.json` is already in very nearly the shape it expects, so this is mostly a
  translation exercise — and it removes our own scoring code from the loop.
- **`profile: true`** gives per-clause timings, which tells you which of the six `should`
  clauses actually costs anything *before* you spend an afternoon tuning its boost.
- **A boost sweep.** The seven `BOOST_*` constants and the popularity factor are a small
  enough space to grid-search against MRR on the golden set. A `benchmark --sweep` mode would
  replace hand-tuning with a number, which is the whole spirit of this repo.

**If only three things get done:** the two loose ends above (`primary_name.keyword` and
`aliases`), the completed-token prefix leak, and geo. The first three are defects wearing the
costume of tuning knobs; the fourth is the feature the briefing is actually about.

**Status:** the first three are done. Geo is not. A fourth item that was not on this list at
all turned out to matter more than any of them — house numbers and box references were gating
the search, so `kerkstraat 2 gent` returned nothing; that is fixed too, and written up
[above](#precision-with-the-index-as-it-stands).

They were applied to **Elasticsearch only**, on purpose — the point was to fix the engine the
POC recommends, not to re-run the comparison. That makes the two engines asymmetric from here
on: the ES numbers in the Conclusion above are the post-fix ones, and the MySQL side still has
the wider `+meir* +antwerpen*` gate and no exact-name or alias boost. Read the agreement
figures accordingly — some of the remaining disagreement is now ours rather than the engines'.
If the comparison ever has to be defended again as a like-for-like measurement, either mirror
the three changes in `MysqlSuggester` or re-run it against the previous commit.

## Caveats and known issues

- Security is disabled on Elasticsearch and the MySQL credentials are in
  `docker-compose.yml`. This is a throwaway local stack.
- The dataset is Flanders only. The briefing's full scope also covers URBIS (Brussels) and
  ICAR (Wallonia) from the same combined export on data.gov.be.
- Place documents carry no coordinates and no NIS code, because the UiTdatabank export has
  neither. A `place` suggestion therefore resolves to its own id rather than to a point, and
  the geo signal described under *Geo, the unused signal* can never apply to one.
- A place's street line is stored as the export writes it (`Lakenhalle - Grote Markt 34`) and
  is never split into street plus house number. Splitting would be a guess that fails
  silently; the number is still searchable and still ranked, because it reaches both engines
  through the folded haystack.
- The place export is hand-maintained and shows it: 5,531 names are shared by more than one
  place (139 are called `locatie`, 61 `online`), and some street lines are whole sentences of
  directions. Nothing is filtered on quality — only on being a Belgian address.
