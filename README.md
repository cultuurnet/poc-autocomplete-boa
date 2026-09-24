# Address autocomplete POC — MySQL vs Elasticsearch 8

A proof of concept for the "locatie suggesties" autocomplete endpoint described in
*Zoek op route & locatie*. It indexes the Flemish address register plus the UiTdatabank place export, and answers type-ahead queries on place, street, postcode and municipality — the same data, the same request, through **six independent retrieval methods** — MySQL, and
five different Elasticsearch approaches — so their speed and their result quality can be
compared side by side.

The POC exists to answer two questions: **which engine do we build the endpoint on**, and
then **which Elasticsearch method**. It is not a prototype of the endpoint itself. There is no
geocoding waterfall and no Search API integration — only the two stores, loaded identically,
and the instrumentation needed to tell them apart.

Plain PHP 8.3, no framework. Composer for autoloading and two client libraries. Everything
runs in Docker.

> **Looking for the results?** They live in **[CONCLUSIONS.md](CONCLUSIONS.md)** — how each
> engine works, what the five Elasticsearch methods cost to index and to query at both corpus
> sizes, how they score, and what to build on. This file is about installing the thing and
> finding your way around it.

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
| `make import CSV=...` | One pass over the 591 MiB CSV, aggregating it into 82,643 documents, written to both engines. `CSV=` is required — there is no default filename | **~45 s** |
| `make import-places CSV=...` | One pass over the 21 MiB place export, 62,709 documents, written to both engines. Optional: the address register stands on its own | **~10 s** |
| `make health` | Reads the configured paths and asks both engines for a document count | < 1 s |

`make up` is idempotent and is the only command needed to spin the stack up — rerunning it on
a running stack is a no-op that costs a few seconds and touches no data.

A full street-level import, measured on the stack as configured:

| | |
|---|---|
| documents produced | **82,643** (81,841 street, 285 municipality, 517 postcode) |
| MySQL write time | 6.2 s (13,284 docs/s) |
| Elasticsearch write time | 38.9 s (2,102 docs/s) |
| peak PHP memory | 85 MiB |
| stored size | 36 MiB MySQL table, 107 MiB Elasticsearch index |

The Elasticsearch figures are **five times** what they were before this index carried five
autocomplete methods at once (23.9 s and 41 MiB for the incumbent mapping alone). That is the
price of being able to compare them on identical documents, not the price of shipping any one
of them — see
[What the methods cost to index](CONCLUSIONS.md#what-the-methods-cost-to-index) for the per-method split.

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
which **3,884,638** are usable (status `current`, with a postcode and coordinates). That is
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
| `address`      | 3,884,638 | `coordinates`     | `Goorbaan 59, 2230 Herselt`                   |

`street`, `municipality` and `postcode` are what `--level=street` (the default) produces:
82,643 documents, so the default working set is **145,352 documents** — the street level plus
the places. `address` documents are off by default; with them the corpus is **4,029,990
documents**, which is what the measurements in
[CONCLUSIONS.md](CONCLUSIONS.md) were taken on and what
[`HOUSE_NUMBERS_INDEXED`](#house-numbers-and-the-gate) exists for.

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

## House numbers and the gate

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
| `address` | 3,884,638 — one per house number, no aggregates | two CSV passes (the aggregate pass still runs, because address documents borrow their street's popularity), **~19 min into Elasticsearch, ~5 min into MySQL**, 3.5 GB on disk |
| `all` | 3,967,281 — both of the above | same two passes, everything written |

`street` is the default and the cheap option. **`all` is no longer only a load test**: it is
the level at which a typed house number can actually narrow the search (see
[`HOUSE_NUMBERS_INDEXED`](#house-numbers-and-the-gate)), and the level at which the
MySQL-versus-Elasticsearch question stops being close — 1,071 ms against 8 ms at the median.
If the endpoint is meant to resolve `Kerkstraat 12`, this is the level it runs at.

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

It **opens on `es-prefixes` against `es-bool-prefix`**, because those are the two methods
still worth arguing about: the best indexed method against the best method with no prefix
index at all, which is the same question as "is 376 MB and nineteen minutes of import worth
5 ms on one-character queries?". MySQL and the incumbent edge-n-gram query are one click away
in either picker; they are just not open questions any more — MySQL is 134× slower at
house-number level, and the incumbent is matched or beaten by `es-prefixes` on every axis
measured.
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
- **`benchmark/golden.json` predates places and is due a re-curation.** Its expectations are
  mostly `street:` document ids, and at address level two other things legitimately outrank
  them: place documents sort above streets by design, and the house-number document is often
  the better answer. 22 of the 23 entries the incumbent does not rank first are outranked by
  a place. The golden scores in [CONCLUSIONS.md](CONCLUSIONS.md) are therefore only useful for
  comparing methods *against each other*, not as an absolute quality figure.
- **Elasticsearch accepts values MySQL rejects, and that hid a partial import.** Three rows in
  3.9M carry a box reference that is a phrase rather than a number (`gemeenschappelijk`); at
  `VARCHAR(16)` those three aborted the MySQL import two thirds of the way through while
  Elasticsearch indexed them without complaint, leaving the two engines with quietly different
  corpora. The columns are `VARCHAR(64)` now, but the shape of the failure is worth
  remembering: only an address-level import surfaces it.
- The place export is hand-maintained and shows it: 5,531 names are shared by more than one
  place (139 are called `locatie`, 61 `online`), and some street lines are whole sentences of
  directions. Nothing is filtered on quality — only on being a Belgian address.
