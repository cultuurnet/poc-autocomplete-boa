# Address autocomplete POC — MySQL vs Elasticsearch 8

A proof of concept for the "locatie suggesties" autocomplete endpoint described in
*Zoek op route & locatie* (briefing 17/09, in the repo root). It indexes the Flemish address
register and answers type-ahead queries on street, postcode and municipality — the same data,
the same request, through **two independent engines** so their speed and their result quality
can be compared side by side.

The POC exists to answer one question: **which engine do we build the endpoint on?** It is
not a prototype of the endpoint itself. There is no UDB3 place data, no geocoding waterfall,
no Search API integration — only the two stores, loaded identically, and the instrumentation
needed to tell them apart.

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
make up                                  # build the php image, start mysql + es + nginx, wait for healthy, composer install
make import CSV=openaddress-bevlg.csv    # street + municipality + postcode documents into both engines
make health                              # confirm both engines hold the same document count
open http://localhost:8080
```

| Step | What it does | How long |
|---|---|---|
| `make up` | Builds the PHP image, pulls MySQL 8.4 and Elasticsearch 8.19.12, starts all four containers, blocks until their healthchecks pass, then runs `composer install` if `vendor/` is missing | Several minutes cold (image pulls dominate); **~4 s** warm |
| `make import CSV=...` | One pass over the 591 MiB CSV, aggregating it into 82,643 documents, written to both engines. `CSV=` is required — there is no default filename | **~38 s** |
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

Write time is reported per engine and excludes the CSV pass, which is shared: the file is read
**once** and every document is fanned out to both indexers. Running the pipeline twice would
double the slowest part of the job and — worse — could let the two engines see different input.

## What gets indexed

The source file holds **4,215,562 rows**, one per individual address in Flanders, of which
**3,884,621** are usable (status `current`, with a postcode and coordinates). That is the
wrong granularity for autocomplete — nobody types a house number to find a street — so the
import aggregates it:

| Document type  | Count     | Search API filter | Example label                              |
|----------------|-----------|-------------------|--------------------------------------------|
| `street`       | 81,841    | `coordinates`     | `Wolterslaan, 9040 Sint-Amandsberg (Gent)` |
| `municipality` | 285       | `region`          | `Gent`                                     |
| `postcode`     | 517       | `region`          | `9040 Sint-Amandsberg`                     |
| `address`      | 3,884,621 | `coordinates`     | `Goorbaan 59, 2230 Herselt`                |

The first three are what `--level=street` (the default) produces: 82,643 documents. `address`
documents are off by default and exist only to measure what the two engines do at ~3.9M
documents instead of 83k.

Street documents carry the **average coordinate** of their addresses and an **address count**
that doubles as a popularity signal — the register has no population data, and without some
prior the ranking cannot tell a 3-address alley from a main road.

The `coordinates` / `region` split on every suggestion is the distinction the briefing asks
for: point suggestions feed the Search API `coordinates` filter, area suggestions feed
`region`. Municipality and postcode documents carry the NIS code for that mapping.

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
| `--recreate` | flag | off | Drop and rebuild the table / index before writing. Without it, documents are upserted into whatever is already there. |
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

### `benchmark`

Runs the same query set against both engines and reports latency and result quality.

| Option | Default | What it does |
|---|---|---|
| `--queries` | `benchmark/queries.txt` | Query file, one per line; `#` comments and blanks ignored, duplicates dropped. |
| `--golden` | `benchmark/golden.json` | Curated expectations to score against. |
| `--iterations` | `20` | Timed runs per query per engine. |
| `--warmup` | `5` | Runs discarded before timing starts. |
| `--limit` | `10` | Suggestions requested per query — also the *k* in overlap@k and hit@k. |
| `--engine` | `all` | Restrict to one engine; the agreement report then has nothing to compare and is skipped. |
| `--no-fuzzy` | off | Disable fuzzy matching on both engines. The single most interesting switch: MySQL has no native fuzziness, so this is where the two diverge. |
| `--format` | `table` | `table`, `json` or `csv`. |

```bash
make benchmark                          # aggregate latency + quality
make benchmark ARGS="-v"                # per-query breakdown
make benchmark ARGS="--no-fuzzy"        # how much of ES's lead is fuzziness
make benchmark ARGS="--format=json"     # machine readable
docker compose exec php php bin/console benchmark --iterations=5 --warmup=2   # fast pass
```

Defaults run 106 queries × 2 engines × 25 calls = 5,300 requests; `--iterations=5 --warmup=2`
cuts that to 1,484 and finishes in a few seconds.

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
| `make reset` | `destroy` + `up` + `install` + `import`. The full from-scratch rebuild. Passes `CSV=` and `ARGS=` through to the import. |
| `make destroy` | Stop the stack **and delete the volumes** — this wipes every imported document. |

## The comparison UI

`http://localhost:8080` is one search box over two result columns. Per keystroke it shows,
for each engine:

- the engine's **own measured query time** (`took_ms`, what the API would report) and the
  browser **round-trip time** — the gap between them is HTTP plus PHP overhead, which is not
  the engine's fault but is real
- a **rolling median** over the last queries, so the judgement is not made on one noisy sample
- which results appear in **only one** engine's list (`only in mysql`), and for shared results
  the rank they hold in the other column (`es #4 ↑`) — disagreement is where the interesting
  differences are
- the per-result score and, behind the *why this rank* toggle, the scoring detail that engine
  reported: for MySQL the individual ranking components, for Elasticsearch its explain-style
  breakdown

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
`address,street,municipality,postcode`, `fuzzy` defaults to on. One failing engine degrades
to an empty column carrying an `error` string rather than taking the request down.

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
time, a `must` clause that gates recall, boosted `should` clauses for precision, and a
`function_score` applying the same log-damped popularity. Fuzziness is native.

The asymmetry in that last sentence is the point of the POC. Read the benchmark output rather
than this paragraph.

## Layout

```
bin/console              CLI entrypoint (import, benchmark, health)
src/Command/             the three commands + the shared --csv option
src/Import/              CSV reading, aggregation, the two indexers
src/Suggest/             the two suggesters behind one interface
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
option (`--csv/-f`, defined once in `src/Command/CsvPathOption.php` and shared by `import`
and `health`), because it is the one setting that changes per run rather than per environment.
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
| Elasticsearch | 2.01 ms | 3.59 ms | 7.25 ms | 0.88 | 30/30 (100%) | 0.895 |

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

Neither result speaks to the two modelling questions still open — how UDB3 places should rank
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

Three fields exist in the mapping and do nothing in the query. These are not tuning knobs so
much as loose ends, and they are the cheapest wins in this section.

- **`primary_name.keyword`** (`ElasticsearchMapping.php:189`) is never searched. An exact
  full-name term boost, ranked above `BOOST_NAME_PHRASE_PREFIX`, would make a fully typed
  `goorbaan` outrank every street that merely *starts* with it. One clause.
- **`aliases`** (`ElasticsearchMapping.php:142`) is never searched either. The comment there
  claims an alias hit is "scored like a name hit"; it is not — aliases only reach the query
  through `search_text`, where they are unboosted and un-n-grammed. FR/DE names and
  sub-localities are effectively second-class citizens in the ranking today.
- **`location`** (`ElasticsearchMapping.php:164`) is a `geo_point` that contributes nothing to
  the score. See [Geo](#geo-the-unused-signal) below — it is the largest single omission.

`label`, `street_name`, `house_number` and `box_number` are also analysed and indexed but only
ever read back out of `_source`. `index: false` on those four costs nothing and shrinks the
index.

### Precision, with the index as it stands

No reindex required for any of these.

**Completed tokens are matched as prefixes too.** The recall gate
(`ElasticsearchSuggester.php:285`) runs *every* token against `search_text`, which is
edge-n-grammed at index time. So in `gent kort` the finished token `gent` also matches
`gentbrugge` and `gentse`, and recall is quietly wider than the docblock claims. Type-ahead
semantics are that the last token is a prefix and every earlier one is a complete word —
`SuggestQuery::completeTokens()` and `::lastToken()` already draw exactly that line, and
nothing in the ES suggester uses either. Gating complete tokens against `primary_name.folded`
and n-gramming only the last one is the single biggest precision change available here.

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
| **`index_prefixes`** instead of the edge-n-gram filter | Native, substantially smaller index, and it retires both the `MAX_GRAM = 20` cliff and the `preserve_original` workaround that exists to paper over it (`ElasticsearchMapping.php:28`, `:95`). |
| **`similarity: boolean`** + `index_options: docs` on `search_text` | It is a pure recall gate, yet its BM25 term frequency still leaks into the final score as noise — and TF is close to meaningless on an n-grammed field anyway. |
| **Phonetic matching** (`analysis-phonetic`) | Cologne phonetic or Double Metaphone is a recall tier that is *more precise* than edit distance for proper names: `sint niklaas` / `sint niclaas`. |

Two that look tempting and are not. **Dutch stemming** has no business near proper names. And
**ICU folding** is more correct than the hand-rolled `Normalizer::FOLD` map, but adopting it
breaks the byte-for-byte folding parity with MySQL that this entire comparison rests on — if
it ever goes in, it goes in on both sides or the benchmark stops meaning anything.

### Alternative shapes worth a number

Not improvements to the current query so much as baselines it should be measured against.

- **`search_as_you_type` + `multi_match: bool_prefix`** replaces the whole hand-built n-gram
  setup with one field type. This is an honest control: if it scores the same, the custom
  analysis chain in `ElasticsearchMapping.php` is not earning its complexity.
- **The completion suggester (FST), with contexts** on `doc_type` and geo, plus per-document
  weights. Sub-millisecond — the latency floor. Not shippable on its own (no multi-token
  reordering, no filtering beyond contexts), but it is the number that shows what the flexible
  query actually costs.
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

## Caveats and known issues

- Security is disabled on Elasticsearch and the MySQL credentials are in
  `docker-compose.yml`. This is a throwaway local stack.
- The dataset is Flanders only. The briefing's full scope also covers URBIS (Brussels) and
  ICAR (Wallonia) from the same combined export on data.gov.be.
- UDB3 place names and coordinates are not part of this POC — it only answers the question of
  which engine to build the endpoint on.
