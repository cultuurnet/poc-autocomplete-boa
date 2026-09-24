# Conclusions — what the measurements say

The results half of this POC. [README.md](README.md) covers installing it, loading the data
and driving the UI; this file is what came out of it.

Everything here was measured on the stack as configured: one Elasticsearch node, one shard,
one MySQL container, a warm cache, no concurrency, on a laptop. Two corpus sizes are used
throughout — **street level** (82,643 documents, the default import) and **address level**
(4,029,990 documents: every house number in Flanders plus the UiTdatabank places) — because
several of the findings only appear at one of them.

**The short version.** Build on Elasticsearch: at house-number level MySQL is 134× slower at
the median, for the same result quality. Of the five Elasticsearch methods, ship
**`es-prefixes`** — it matches or beats the hand-built edge-n-gram incumbent on every axis
measured, for the same index, without the `MAX_GRAM` cliff. The rest of this file is the
evidence.

---

## How each engine works

Every method indexes the **same folded text**, and both engines are handed the same parsed
query — see [What the code does before the engine sees it](#what-the-code-does-before-the-engine-sees-it).
If the two sides folded or parsed differently, the benchmark would be measuring preprocessing
rather than the engines.

### MySQL

One `location_suggestions` table with a `FULLTEXT` index over the folded haystack, queried in
boolean mode with `+token*` per token (prefix semantics, all tokens required).
`innodb_ft_min_token_size=1` is set in `docker/mysql/my.cnf`, without which any one- or
two-character prefix would return nothing, and the (English) stopword list is disabled because
Dutch street names contain words that are in it. Ranking combines the `MATCH` score, a bonus
when the *name* (not just the city) starts with what you typed, and a log-damped popularity
boost. MySQL has no native fuzzy matching, so typo tolerance is a deliberate best-effort
second pass.

### Elasticsearch — five methods over one index

There is no single "the Elasticsearch query". Five methods answer the same request from the
same documents, and **the retrieval method is the only thing that differs between them**:

| key | how it finds the half-typed token |
|---|---|
| `elasticsearch` | Edge n-grams at index time (1–20 characters, `preserve_original`), whole words at search time. Every prefix is already a term, so matching is an ordinary term lookup. The incumbent. |
| `es-prefixes` | The same idea, native: `index_prefixes` on a plain text field, so Lucene maintains the prefix terms itself in a hidden subfield. |
| `es-sayt` | The `search_as_you_type` field type, which adds `._2gram`, `._3gram` and `._index_prefix` subfields and cross-matches them with a `bool_prefix` `multi_match`. |
| `es-bool-prefix` | Nothing at index time at all. `match_bool_prefix` over the plain analysed field: finished tokens as terms, the last one as a prefix query that walks the term dictionary. |
| `es-completion` | A `completion` field — an in-memory FST of names with a weight, queried through the suggest API. Not a search: no filtering beyond its `doc_type` context, and ranking is the indexed weight alone. |

Everything around that is held constant, which is what makes the comparison mean anything.
All four *search* methods share, byte for byte: the `doc_type` filter, `size`,
`track_total_hits`, the `_source` list, the same exact-name / postcode / municipality / alias
/ house-number `should` clauses, the same `function_score` (log-damped popularity plus
per-type weights), and the same two-pass fuzzy fallback. Boosts were deliberately **not**
re-tuned per method: if one ranks worse, that is the finding.

The gate is decomposed once, centrally — see
[What the code does before the engine sees it](#what-the-code-does-before-the-engine-sees-it)
— so no two methods can disagree about *which* token the user is still typing, or about what
to do with a house number. `es-completion` is the exception to all of this and cannot be
otherwise — the suggest API takes no query, no filter
and no sort — which is why it is in the comparison as a latency floor rather than as a
candidate.

## What the code does before the engine sees it

Elasticsearch is not left to be clever on its own. A large part of what makes the results good
happens in PHP — once per document at import, once per request at query time — and none of it
is Elasticsearch-specific: both engines are handed the same folded text and the same parsed
query, which is what keeps the comparison honest.

**This section is the port list.** When this POC is rebuilt inside a real codebase, these are
the steps to reproduce, in this order; anything left out has a named symptom, which is written
next to it. Dropping one of them does not degrade the results gracefully — most of them are
the difference between "a result" and "no result at all".

**The test cases live in [`benchmark/golden.json`](benchmark/golden.json).** Every rule below
that can be stated as a known-item query has an entry there — the punctuation folding of step
1, the house-number gate and the postcode-prefix trap of step 4, the fuzzy rung of step 5 —
each with a note naming the rule it guards and what breaks without it. Port the steps, then
run the set against the new implementation: an entry that stops being a hit names the step
that was dropped. Its `_readme` documents the id format and the matching rule.

### Step 1 — one folding, applied to both sides

`src/Support/Normalizer.php` lowercases, strips diacritics through an explicit map (no
`ext-intl`, so the output is byte-for-byte predictable across PHP builds), replaces every
non-alphanumeric run with a separator and collapses whitespace: `Rue de l'Église` becomes
`rue de l eglise`. The importer stores that folded string on the document; the query side
folds the typed string with the exact same function.

Elasticsearch has to reproduce that chain, not approximate it
(`ElasticsearchMapping::settings()`):

| piece | what it is | why it exists |
|---|---|---|
| `strip_punctuation` char filter | `pattern_replace` on `[^\p{L}\p{N}]+` → space | Looks redundant next to the `standard` tokenizer; is not, in two places — see below. It matches on `\p{L}\p{N}` and not `[a-z0-9]` because it runs *before* folding and would otherwise eat the accented characters before they can be folded. |
| `folding` analyzer | char filter → `standard` → `lowercase` + `asciifolding` | The analyser equivalent of `Normalizer::normalize()`. `asciifolding` covers the multi-character expansions (`ß`→`ss`, `æ`→`ae`) too. |
| `autocomplete_index` analyzer | the same chain plus `edge_ngram` 1–20, `preserve_original` | Index time only. `preserve_original` exists because a token longer than `max_gram` would otherwise only be indexed as its first 20 characters, and typing the whole word would stop matching it — `Kortrijksepoortstraat` is 21. |
| `folding_keyword` normalizer | the same chain, `trim`, applied to the value as a whole | Folds the whole value into *one* term instead of tokenising it. |

**Why the char filter, when the tokenizer already drops punctuation?** Because it only drops
it *between* words. The `standard` tokenizer implements Unicode word boundaries, where `'` and
`.` are word-internal, and a *normalizer* has no tokenizer at all. Measured on the cluster:

| value | ES without the char filter | with it — and what PHP produces |
|---|---|---|
| `Sint-Genesius-Rode`, text field | `sint, genesius, rode` | same — the tokenizer needs no help here |
| `Rue de l'Église` | `rue, de, `**`l'eglise`** | `rue, de, l, eglise` |
| `O.L.V.-straat` | **`o.l.v`**`, straat` | `o, l, v, straat` |
| `Sint-Genesius-Rode`, `primary_name.keyword` | **`sint-genesius-rode`** | `sint genesius rode` |

The last row is the one that costs something: the query side sends `sint genesius rode`, so
without the char filter the exact-name clause below never matches anything. (The obvious
alternative, the `apostrophe` token filter, is wrong here — it truncates at the apostrophe, so
`l'eglise` becomes `l` and the street name is lost.)

Two more rules that come with it, and are easy to lose in a port:

- **Index n-grams, search whole words.** Every n-grammed field sets `search_analyzer` back to
  `folding`. If the query were analysed with the index analyser too, typing `gent` would
  search for `g`, `ge`, `gen` *and* `gent` — every street starting with `g` matches, and
  precision collapses while the result list still looks plausible.
- **`norms: false` on every n-grammed field.** Edge n-grams make the token count a function of
  the number of *characters*, so BM25 length normalisation would punish long street names for
  being long rather than for being a worse match. The length signal is kept on the
  whole-word `.folded` subfields.

The `folding_keyword` normaliser is what makes the single strongest clause in the query
possible at all: `primary_name.keyword` then holds `sint genesius rode`, byte-for-byte what
`SuggestQuery::normalized()` sends. Without it the subfield holds `Sint-Genesius-Rode` and the
exact-name clause (`BOOST_NAME_EXACT`, 12.0 — the highest boost in the file) silently never
fires.

### Step 2 — one deduplicated haystack per document

`SuggestionDocument::searchText()` folds place name, street, house number, postcode, post
name, municipality and aliases into a **single deduplicated string**, and that one string is
what the recall gate reads in both engines (`search_text` in Elasticsearch, the `FULLTEXT`
column in MySQL).

Deduplication is not cosmetic: a street called Gent in the municipality Gent would otherwise
repeat a token, and term frequency would reward it for saying the same thing twice.

The catch-all is mapped twice on purpose — the n-grammed parent field for the token still
being typed, and a whole-word `search_text.folded` subfield for the tokens already finished.
Type-ahead needs both; gating a *finished* word against the n-grammed parent is what made
`gent kort` also match Gentbrugge and Gentse.

It has to be a subfield of the catch-all and not of the name: a finished token is very often
the municipality or the postcode, which never appear in `primary_name`, so gating finished
words against `primary_name.folded` throws away most multi-token queries.

The completion suggester is fed something else entirely (`completionInputs()`): an FST only
matches a prefix of a *whole* input, so the joined haystack would mean `gent` completes only
documents whose haystack happens to start with `gent`. Each name is its own input, aliases
listed separately — `Liège` and `Luik` are two things a user types, not one.

### Step 3 — clean the source data before it becomes a document

The register and the place export are both dirty, in different ways, and every repair below
exists because of something that was visibly wrong without it.

**Aggregate the register — always, whatever you end up indexing.** The openaddress files hold
one row per individual address, which is the wrong granularity for most of autocomplete:
nobody types a house number to find a street. One pass folds the 3,884,638 usable Flemish rows
into 82,643 street, municipality and postcode documents, each with an averaged coordinate and
an address count.

That pass runs at **every** `--level`, and not only at street level, because the address count
is the popularity prior for *every* document type — an individual house number inherits the
count of the street it stands on, and without it the ranking cannot tell a 3-address alley
from a main road. What `--level` decides is only which of the outputs are emitted as
documents: the aggregates (`street`, the default), one document per house number (`address`),
or both (`all`). The 4,029,990-document corpus everything here is measured on is `--level=all`
plus the place export — 82,643 + 3,884,638 + 62,709.

Rows that are not `current`, or that carry no postcode or no coordinates, are dropped before
any of that.

**Re-case the names.** The register mixes `HERSELT` with `Ramsel`. `NameFormatter::titleCase()`
re-cases the all-caps values only, leaving already-mixed names like `'s-Gravenwezel` and
`De Haan` alone, and puts Dutch/French particles (`de`, `van`, `sur`, `les`) back to lowercase
when they are not the first word.

**Unpack `postname`.** One field packs several sub-localities behind slashes
(`Grimminge/Idegem/Nieuwenhove`). One is shown next to the postcode — the sub-locality when
the postcode maps to exactly one, the municipality otherwise — and all of them become aliases.

**Fill aliases deliberately.** Every document carries the other languages' spelling of its
name, the sub-localities behind its postcode, and its municipality name. They are n-grammed
like the primary name and scored by their own clause at `BOOST_ALIAS` (1.8). Without the
municipality translations, `rue de seraing liege` matches the street and then loses on the
city token it cannot find, because the label says `Luik`. The boost sits deliberately *below*
`BOOST_NAME_MATCH`: the municipality is filed under aliases too, so a name-weighted clause
would count the city twice for every document in the index.

**Repair the place export, and count what you throw away.** UiTdatabank names and addresses
are hand-entered JSON blobs per language. Whitespace runs and newlines are collapsed
(otherwise they reach the dropdown verbatim), `Onkerzele (Geraardsbergen)` is split into
sub-locality and municipality so the place is findable by either, and rows that are not in
Belgium or whose postcode is not four digits are rejected — with the reason counted and
reported, so "why is my place missing" is answerable without re-reading the CSV by hand.

**Do not let a label repeat itself.** A place name in the export is whatever an organiser
typed into a name field, and thousands of them are not venue names at all but localities
(`Brussel`), postcode-and-locality pairs (`1000 Brussel`) or entire addresses. Appending the
register address to those produced `1000 Brussel (1000 Brussel, 1000 Brussel)` — one fact
spelled three times. `NameFormatter::placeLabel()` prints each half of the bracket only when
it carries something the name does not, testing on **folded word boundaries**: folded because
the export agrees with the register on nothing else (`9040 Sint-Amandsberg` against
`9040 SINT AMANDSBERG`), on word boundaries because a shared prefix is not the same street
(`Markt` must not match inside `Marktplein`). The test is one-directional — a half is dropped
because the name already shows it, never because it looks unimportant — so it can shorten a
label but never remove the only mention of something.

**Give every document a popularity value.** For register documents it is the number of
addresses behind them, which doubles as the ranking prior. Places have no usage signal in the
export at all, so they get a flat 24 — the median number of addresses on a Flemish street,
which puts a place level with a typical street on the popularity term and leaves the ordering
to the tiers in step 6. It cannot be zero: `log1p(0) = 0` multiplies the whole score away and
the document disappears.

### Step 4 — read numbers as numbers

This is the part that had the most defects in it, and the one most likely to be re-introduced
by a reimplementation that treats a query as a bag of words. A Belgian address query mixes two
kinds of number that look identical and mean opposite things: `kerkstraat 12 gent` and
`kerkstraat 9000 gent`.

**Classify the tokens** (`Normalizer`):

| predicate | matches | used for |
|---|---|---|
| `isPostcodeToken()` | exactly 4 digits, `1000`–`9999` | the exact-postcode term clause |
| `isHouseNumberToken()` | 1–4 digits plus up to 3 letters (`59`, `12a`, `3bis`), not a postcode | the locative/detail split |
| `isNumericToken()` | digits plus up to 3 letters, *unbounded* in length | deciding whether a token may be prefix-matched at all |
| `isBoxMarkerToken()` | `bus`, `bte`, `boite` | the box reference |

The bare `b` of `12 b 5` is deliberately **not** a box marker on its own — it is the first
keystroke of far too many street and municipality names — and is only read as one directly
after a house number, where the ambiguity does not exist.

**Split the query into locative and detail tokens** (`SuggestQuery`). `kerkstraat 12 bus 5
gent` is two questions in one string: *which* Kerkstraat (street, postcode, municipality) and
*where on it* (number, box). Only the locative half reaches the recall gate. A street document
carries no house number and `searchText()` never contains `box_number` at any level, so
requiring them finds **nothing at all** for the most ordinary way a Belgian writes an address.
That was not a tuning issue, it was zero results on `kerkstraat 2 gent`.

Two guards belong to the split:

- **A query made of nothing but detail does not split.** On a bare `22` the number is all the
  user gave us, so it has to keep narrowing; otherwise the gate is empty and matches the whole
  index. (The guard earns its keep in both directions: `bus` on its own is a street in Eeklo.)
- **The house number stops being detail once the index contains house numbers.** At address
  level the number is the most selective token in the query and belongs in the gate:
  `kerkstraat 12 gent` gates down to 413 candidates with the split and **2** without, and the
  two are the right ones. The `HOUSE_NUMBERS_INDEXED` flag names the corpus, not the
  mechanism, because the corpus is the thing an operator actually knows. The box reference is
  never in the gate, whatever the index holds.

**Do not prefix-match a trailing number.** The trailing token is normally treated as a prefix,
because it is the one still being typed. A trailing *number* is the exception, and this is the
single most expensive defect the POC found. Because `searchText()` folds street, number,
postcode and municipality into one string, a prefix cannot know which of them it landed on,
and every Belgian postcode is four digits:

```
"anspachlaan 100"  ->  gate term "100"  ->  edge n-grams of "1000"  =  1, 10, 100, 1000
```

`kerkstraat 90` admitted 885 documents — every place in 9050 Gentbrugge, 9060 Zelzate, 9080
Zaffelare and 9000 Gent — and **not one** of the 46 documents actually standing at Kerkstraat
90 was reachable. The gate is the one stage nothing downstream can undo, so it had turned the
most selective token in the query into the least selective one. The rule
(`SuggestQuery::isTypingLocative()`, `::isBareNumeric()`):

- **A number is read as a postcode being typed only when it is the whole query.** A bare `22`
  really is someone typing a postcode — there is no street for it to be a number *of* — and
  there the prefix reading is the only one that means anything.
- **A number standing next to a name is a house number by construction.** It is gated as a
  whole word and gets no partial-postcode boost. A complete four-digit postcode still matches
  exactly, because it is a whole word either way.

**Score numbers, separately from gating them** (`postcodeClauses()`, `addressDetailClauses()`):

| clause | boost | field | note |
|---|---|---|---|
| exact postcode | 6.0 | `postcode` (keyword term) | a complete 4-digit token the document carries |
| partial postcode | 3.0 | `postcode.text` (n-grammed) | a 1–3 digit token, **only** on a bare numeric query and only when trailing |
| house number / box | 4.0 | `search_text.folded` | whole-word, never a prefix: prefixing would let `5` score 59, 5A and 512 alike |

There is deliberately no clause on the `house_number` field: it is an unfolded keyword holding
the raw register value (`12A`) while query tokens arrive lowercased, so a term lookup would
miss exactly the numbers that have a letter suffix. Numbers reach the query through
`search_text`, which is folded on both sides.

### Step 5 — recover from an empty result set with a ladder, not a looser gate

The gate is strict by design, so one wrong token yields zero results rather than a slightly
worse list. A looser `should` clause cannot fix that — a `should` can only reorder documents
the `must` already admitted — so every recovery is its own query, run only when the previous
one came back empty. The common path stays at one round trip.

| pass | gate | answers |
|---|---|---|
| `strict` | every locative token, trailing one as a prefix | the common case |
| `no_number` | every *name* token exactly, number demoted to ranking | a number that is not on the street typed |
| `fuzzy` | fuzziness itself, numbers dropped entirely | a misspelt street |

**The order is the point.** The number is given up before the name, because the number is
exact evidence and the name is not. And fuzziness has to come last: an n-grammed field is
loose enough to reach *Goormansdijk* from `goorbaan`, so a fuzzy pass promoted above
`no_number` buries the street the user actually typed. `goorbaan 22` (no number 22 on the
Goorbaan) answers with *Goorbaan, 2230 Herselt* and the numbers it does have.

Numbers are **dropped** from the fuzzy pass rather than fuzzified: edit distance over digits
is not a typo model but a random number generator — `100` is one edit from 110, 200, 180 *and*
10, and on an n-grammed field it reaches every `100x` postcode as well. Conversely
`fuzzy_transpositions` must be on and `prefix_length` 1: `antwrepen` is one transposition from
`antwerpen` but two plain edits, and that is the difference between the fallback working and
not working for the most common class of typo. Note also that Elasticsearch does **not** apply
fuzziness to the trailing prefix term of a `match_bool_prefix`, which is why the fuzzy pass is
a plain `match` for every method rather than each method's own prefix query.

### Step 6 — ordering across document types

This is the one thing no method decides by score alone, and it is two mechanisms, not one.

**The hard part is a tier.** Results are grouped into three tiers, and score only decides the
order *inside* a tier:

| tier | types | why |
|---|---|---|
| 0 | `municipality`, `postcode` | A bare `gent` is a question about the city. |
| 1 | `place` | A venue is a more specific answer than the street it stands on. |
| 2 | `street`, `address` | |

The tier is one decision, defined once in `SuggestionType::rankTier()`. MySQL renders it as a
`CASE` in the `ORDER BY` of every pass; Elasticsearch denormalises it onto the document as a
`rank_tier` byte and sorts `rank_tier asc, _score desc`. It is denormalised rather than
derived from `doc_type` because sorting needs doc values on a numeric field — the alternative
is a script or runtime field evaluated per matching document, which turns a sub-millisecond
sort into tens of milliseconds on a query like `straat` that matches most of the index. One
byte per document buys that. Two details worth porting with it: `unmapped_type: byte` on the
sort, so an index built before the field existed degrades to score order instead of returning
a 400; and keeping `_score` as the secondary key, which is also what keeps `_score` populated
on every hit under a field sort.

**The soft part is a per-type weight**, inside the `function_score`: municipality 1.6,
postcode 1.35, place 1.2, street 1.0, address 0.75, multiplied by a `log1p`-damped popularity
factor. These fix a structural problem rather than express a preference — a municipality
document competes against the 3,000 street documents inside it, every one of which also
contains the city name, so on text alone a city can lose to its own streets. Popularity is
damped hard on purpose (factor 0.5 makes a large city worth about 1.8× a village rather than
100×; counter-intuitively a *larger* factor damps harder, because under a logarithm the ratio
between two values shrinks as both are scaled up) and the whole function is capped at
`max_boost: 15.0`, so no amount of popularity can overrun a genuinely better textual match.

**Why the cross-type ordering has to be a tier and not a weight.** A place and an address
document can describe the same address, and on text the address wins every time — it is
*named* after the street you typed, while the venue standing on it is not. Scores here are
unbounded (a good name match reaches four figures), so no weight small enough to be safe is
large enough to overturn that. A tier states the preference directly: for `markt 62 berlaar`,
`Baristik (Markt 62, 2590 Berlaar)` is listed above `Markt 62, 2590 Berlaar`.

**Place earns a tier of its own rather than sharing tier 0.** While it shared the top tier
with municipality and postcode, *any* matching place outranked *every* street and address
unconditionally — on `kerkstraat gent` the Kerkstraat itself came fifth, behind four venues
standing in it that scored a third as well (89.8 against 153.1), because `rank_tier` sorts
before `_score`. A separate tier keeps the intended ordering without that side effect: a venue
beats the street it stands on, but never the town it stands in. Sharing tier 2 would not work
either — the choice would fall to the 1.2 weight, which a strong street match overruns.

One consequence to keep in mind when reading the quality numbers below: places above streets
is a deliberate product choice, and `benchmark/golden.json` predates it. Its expectations are
`street:` ids, so a place ranking first counts as a miss.

### If only part of this gets ported

In the order the results degrade, worst first:

1. **The locative/detail split** (step 4). Without it every ordinary `street number city`
   query returns zero results.
2. **The whole-word gating of a trailing number** (step 4). Without it the most selective
   token in the query becomes the least selective one, silently — the query still returns
   results, they are simply the wrong postcode's.
3. **Folding parity between index and query side** (step 1), including the keyword
   normaliser. Without it the exact-name clause never fires and accented or apostrophised
   names match inconsistently.
4. **The search-time analyser split** (step 1). Without it precision collapses while the
   result list still looks plausible, which is the hardest of these to notice.
5. **The tier sort** (step 6). Without it the ordering across document types is decided by
   whichever text match happened to score higher.
6. **The recovery ladder** (step 5) and the label/name repairs (step 3). These are quality,
   not correctness — the results are right, they are merely thinner or uglier.

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

**At 4,029,990 documents it stops being a coin flip.** The same query set, both engines
holding the same corpus (the full house-number file plus the UiTdatabank places):

| engine | p50 | p90 | p95 | p99 | max |
|---|---|---|---|---|---|
| mysql | **1,071.70** | 1,928.34 | 2,125.40 | 2,758.65 | 3,783.60 |
| elasticsearch | **7.98** | 19.50 | 25.56 | 40.17 | 81.23 |

134× at the median, 83× at p95. A second per keystroke is not a slow autocomplete, it is not
an autocomplete. Quality stays comparable — MySQL scores 0.540 MRR against Elasticsearch's
0.519 on the golden set, and actually finds more of it at k=10 — so this is purely the
retrieval cost of a `FULLTEXT` scan over four million rows against an inverted index.

(The Elasticsearch column here is higher than the per-method numbers below because the two
engines are interleaved in one run: MySQL saturating the machine for a second at a time is
part of what Elasticsearch is being timed against. The clean Elasticsearch figure on the same
index is 2.91 ms at p50.)

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

### What the methods cost to index

Measured three times, each force-merged to one segment and measured with
`POST /<index>/_disk_usage?run_expensive_tasks=true`:

- **street level**, 82,643 documents (`--level=street`, Flanders only)
- **address level, Flanders**, 4,029,990 documents (the Flemish house-number file plus the
  UiTdatabank places)
- **address level, all three regions**, 6,863,736 documents (adding
  `openaddress-bebru.csv` and `openaddress-bewal.csv`)

| | street, 82,643 | address FL, 4,029,990 | address BE, 6,863,736 |
|---|---|---|---|
| index size, incumbent mapping only | 40.8 MB | — | — |
| index size, all five methods | 107.5 MB | 3.5 GB | **6.31 GB** |

Note what that 6.31 GB is: **one index carrying all five methods at once**, which is the price
of comparing them on identical documents and not the price of shipping any one of them. The
per-method split is what matters:

| method | fields | street | address FL | address BE |
|---|---|---|---|---|
| `es-bool-prefix` | — (reuses `search_text.folded`, `primary_name.folded`) | **0.0 MB** | **0 MB** | **0 MB** |
| `es-completion` | `suggest` | 6.1 MB | 253 MB | 436 MB |
| `elasticsearch` | `search_text` + `primary_name` | 12.9 MB | 376 MB | 615 MB |
| `es-prefixes` | the two `._index_prefix` structures | 12.9 MB | 376 MB | 615 MB |
| `es-sayt` | eight subfields; `search_text_sayt._index_prefix` alone is 1,648 MB | 38.8 MB | 1,299 MB | 2,434 MB |

The three largest single fields on the full index are `search_text_sayt._index_prefix`
(1,648 MB), `_source` (944 MB) and `search_text` (439 MB) — and `_source` outweighing every
method but one is worth remembering before optimising any of them.

**"0 MB" is marginal, not absolute.** Every method rides on fields the index has to carry
anyway — `_source`, doc values, the keyword and whole-word fields, the geo point.
`es-bool-prefix` is the only one that adds nothing at all on top of those: it queries
`search_text.folded` and `primary_name.folded`, which exist for the other methods' ranking
clauses regardless. The figures above are each method's own addition to the bill.

The proportions survive two changes of scale almost exactly, which is the useful part: these
ratios are a property of the methods, not of this corpus. `search_as_you_type` is a third of
the whole index at all three sizes, and `es-prefixes` tracks the edge-n-gram incumbent to
within 0.05% at 6.9M documents just as it did at 82k.

Two things worth stating plainly, because this README previously guessed at both:

- **`index_prefixes` is not the cheap option.** At 1–19 it stores very nearly what the edge
  n-gram filter stores, and costs the same to within 1%. The saving people expect from it
  comes from the *default* 2–5 range, not from the mechanism. What it does buy is native
  behaviour: no `MAX_GRAM` cliff and no `preserve_original` workaround, because a term longer
  than `max_chars` still matches, just by scanning.
- **`search_as_you_type` is expensive**, exactly as the article warns: three times the
  incumbent's index for this data, most of it the `_index_prefix` of the shingled catch-all
  field. It is the convenient option, not the cheap one — and it scales worst of the five:
  from 4.03M to 6.86M documents it grew by 1,135 MB while the incumbent grew by 239 MB.

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
  and it buys back the 376 MB `es-prefixes` adds.
- `es-sayt` costs 3.4× the index of `es-prefixes` for the same behaviour. Convenience, not
  capability.
- `es-completion` is a genuine sub-millisecond floor (0.69 ms p50 on four million documents)
  and a genuinely different product: right for a "jump to a city or venue" box, wrong for
  address lookup, where it misses every street-plus-city query by construction.
- **Query latency barely notices the corpus.** 49× the documents cost about 15% at the p50.
  Index size does scale with it — 33× — so that, not response time, is what to plan capacity
  around.

Everything above is one shard, one node, a warm cache and no concurrency. Reproduce with:

```bash
# street level, the default import
make benchmark ARGS="--engine=es --iterations=20"

# address level: import with --level=all, then set the flag (see below)
HOUSE_NUMBERS_INDEXED=1 make benchmark ARGS="--engine=es --iterations=20"
```

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
disqualified *at street level* — there it is fast, returns a sensible list for well-spelled
input, and needs no extra infrastructure. If the endpoint ever had to ship without a new
service to operate, it would do.

**At house-number level it is disqualified outright.** Measured on the full 4,029,990-document
corpus, MySQL's median query is **1,071.70 ms** against Elasticsearch's 7.98 ms in the same
run — 134× — with the tail at two seconds. Its result *quality* holds up fine (0.540 MRR
against 0.519), so this is not a ranking problem that could be tuned away; it is what a
`FULLTEXT` scan over four million rows costs. Since house-number level is where this feature
is actually going, that settles the question the street-level numbers left open.

**"Elasticsearch" now means one of five methods.** The recommendation above was measured
with the incumbent edge-n-gram method and still stands, but the follow-up question — which
Elasticsearch method — has its own answer, and it is not the obvious one: the method with no
prefix index at all matches the incumbent on latency at both corpus sizes, and the native
`index_prefixes` beats the hand-built n-gram chain on every axis measured. See
[The five Elasticsearch methods](#the-five-elasticsearch-methods), measured at 82,643 and at
4,029,990 documents.

The two modelling questions that used to be listed here as open have both been answered since,
and neither changed the choice above:

- **How UiTdatabank places rank against addresses** — by tier, not by score. A place outranks
  the street it stands on and is outranked by the town it stands in; see
  [Step 6](#step-6--ordering-across-document-types). It is a product choice
  rather than a measurement, and `benchmark/golden.json` still predates it.
- **Whether house-number labels get indexed** — yes, and it is what
  [`HOUSE_NUMBERS_INDEXED`](README.md#house-numbers-and-the-gate) exists for. At address level
  the number gates the search instead of merely ranking it, which takes `kerkstraat 12 gent`
  from 413 candidates to 2.

## Tuning headroom on Elasticsearch

The conclusion picks Elasticsearch partly because it *leaves room to tune*. This is that room,
audited against what the POC actually implements rather than against the manual. None of it is
needed for the comparison above to hold — the two engines are already configured symmetrically
and the numbers stand. It is the list to work through once the endpoint is real, ordered by
what it costs to try.

### Already mapped, never queried

Three fields existed in the mapping and did nothing in the query. Two of them are now wired
up; the third is the geo signal, which is a feature rather than a loose end.

- **`primary_name.keyword`** — *done.* It carries the `folding_keyword` normaliser, which is
  what lets `BOOST_NAME_EXACT` (12.0) ever fire; the folding was the fix, the clause was the
  easy half. See [Step 1](#step-1--one-folding-applied-to-both-sides).
- **`aliases`** — *done.* N-grammed like `primary_name` and carried by its own clause at
  `BOOST_ALIAS` (1.8), deliberately below the name boosts. See
  [Step 3](#step-3--clean-the-source-data-before-it-becomes-a-document).
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

**Completed tokens are matched as prefixes too** — *done*, and it is now the two-field gate of
[Step 2](#step-2--one-deduplicated-haystack-per-document). The gate used to run *every* token
against the edge-n-grammed `search_text`, so in `meir antwerpen` the finished token `meir`
also admitted *Meirbrug*.

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

The fix is the locative/detail split of [Step 4](#step-4--read-numbers-as-numbers), and it is
query-side only — **no reindex**.

MySQL was never doing this properly either. Its boolean query for `kerkstraat 12 bus 5` is
`+kerkstraat* +bus*` — it drops `12` and `5` only because they are shorter than
`FULLTEXT_MIN_PREFIX`, keeps `bus`, fails its own fulltext pass, and is rescued by the
`fuzzy_prefix` fallback. It gets the right answer by luck, not by design, which is why the
Elasticsearch fix does not mirror it.

**A typed house number was matching the postcode** — *done, and the most expensive defect
found so far.* The sequel to the fix above, and it only exists *because* of it: once
`HOUSE_NUMBERS_INDEXED=1` puts the number back in the gate, the number was gated as a prefix
and prefix-matched every postcode beginning with it — the mechanism, and the rule that now
prevents it, are [Step 4](#step-4--read-numbers-as-numbers). On top of the wrongly widened
gate, `postcodeClauses()` then paid the wrongly-admitted documents a
`BOOST_POSTCODE_PARTIAL` of 3.0 for a postcode the user never typed:

| query | candidates before | of those, at the number typed | candidates after |
|---|---|---|---|
| `kerkstraat 90` | 885 | **0** | 46 |
| `meir 20` | 224 | **0** | 5 |
| `anspachlaan 100` | 24 | **0** | 24 *(via the new second pass)* |
| `goorbaan 22` | 45 | **0** | 45 *(via the new second pass)* |

`kerkstraat 90` is the clearest case: 46 documents in Flanders stand at Kerkstraat 90 and
**not one of them was reachable**. The 885 that answered instead were places in 9050
Gentbrugge, 9060 Zelzate, 9080 Zaffelare and 9000 Gent — every postcode beginning `90` — and
because `Place` sorts in `rank_tier` 1 and `Address` in tier 2, the places filled the top ten
before an address could be considered. All four query methods returned the same 885, and
MySQL the same class of defect through `+90*` in its boolean query — identical counts across
methods is the tell that this lived in the shared layer and not in any one method's gate.
(`es-completion` was never affected and is no consolation: it answers none of these queries
at all, because the FST only matches a prefix of a whole input.)

The fix is the shape the user reporting it proposed, and it is query-side only — **no
reindex**: the number is gated as a whole word unless it is the entire query
([Step 4](#step-4--read-numbers-as-numbers)). That alone fixes recall but leaves a new
question — what should answer a number that is *not* on the street typed — and the answer is
the second rung of the recovery ladder
([Step 5](#step-5--recover-from-an-empty-result-set-with-a-ladder-not-a-looser-gate)).
`goorbaan 22` (no number 22 on the Goorbaan) now answers with *Goorbaan, 2230 Herselt* and the
numbers it does have; before the ladder its fuzzy pass led with two places on the
*Goormansdijk*.

MySQL got the same `no_number` pass, and two smaller corrections that only became visible
once its gate was exact. Numbers are no longer eligible as its `fuzzyToken()` target — in
`ab 1000` the longest token is the postcode, so the fuzzy pass was relaxing the one token
that was certainly correct. And its fuzzy pass **tops up a short list** rather than rescuing
an empty one, which is fine while the gate is loose and wrong the moment it is exact: there
are legitimately only two `Kerkstraat 12` in Gent, and topping those two up to ten dragged in
a hundred places that ignore the number and that MySQL's ranking puts above them. The top-up
is now skipped when a house number matched exactly; non-numeric queries keep it untouched.

**What it cost.** Every ES method's golden-set MRR went up and every method got faster,
because a gate that admits 885 documents instead of 46 has to score 885 documents. Measured
on the **address-level** index (37 golden entries, 178-query set, 2 iterations) — not
comparable with the 30-entry street-level figures quoted earlier in this section:

| method | MRR before | MRR after | hit@10 | p50 before | p50 after |
|---|---|---|---|---|---|
| `elasticsearch` | 0.510 | **0.541** | 26 → 28 | 25.7 ms | 19.4 ms |
| `es-prefixes` | 0.537 | **0.555** | 27 → 29 | 12.2 ms | 9.2 ms |
| `es-sayt` | 0.537 | **0.555** | 27 → 29 | 17.1 ms | 13.1 ms |
| `es-bool-prefix` | 0.537 | **0.555** | 27 → 29 | 7.0 ms | 5.3 ms |
| `es-completion` | 0.257 | 0.257 | 10 → 10 | 3.2 ms | 2.2 ms |
| `mysql` | 0.535 | 0.513 | 30 → 27 | 1236 ms | 1162 ms |

MySQL's drop is **three stale golden expectations, not a regression.** All three
(`goorbaan 59`, `goorbaan 59 2230 herselt`, `veldstraat 10 9000 gent`) expect the *street*
document, and say why: *"No street document carries a number, so the only right answer is the
street itself."* That was true when the entries were written against a street-level index. On
the address-level index it is not: both engines now return exactly **one** document for
`goorbaan 59` — `address:200001`, *Goorbaan 59, 2230 Herselt* — which is the better answer
being scored as a miss. `benchmark/golden.json` needs a second set of expectations for
address level before its numbers mean anything on this corpus; it has deliberately not been
edited to flatter this change.

Two things the same report asked for that the **corpus** cannot give, and no amount of query
tuning will:

- *Ancienne Belgique* is at **Anspachlaan 110**, not 100. `anspachlaan 110` already answered
  correctly before this fix and still does; `anspachlaan 100` now answers through the
  `no_number` pass, with Ancienne Belgique third.
- `anspachlaan 1000` cannot answer with the *street* `Anspachlaan, 1000 Brussel`, because
  **Brussels has no street or address documents at all**. `openaddress-bevlg.csv` is
  Flanders; of the 3,904 documents with a `10xx` postcode, 3,904 are UiTdatabank places and
  none is a street. The `Anspachlaan (Anspachlaan 0, 1000 Brussel)` that does appear is a
  *place* named after the street. Loading the Brussels and Walloon address registers is the
  only fix, and it is worth knowing before the next report of this shape arrives.

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

**Status:** the first three are done. Geo is not. Two items that were not on this list at all
turned out to matter more than any of them. House numbers and box references were gating the
search, so `kerkstraat 2 gent` returned nothing; that is fixed, written up
[above](#precision-with-the-index-as-it-stands), and has since grown a second half — with an
address-level index the house number *should* gate, which is what
[`HOUSE_NUMBERS_INDEXED`](README.md#house-numbers-and-the-gate) switches on. And the four
alternative retrieval methods in this file were built and measured, which answered the
"alternative shapes worth a number" item above and retired the recommendation to keep
hand-rolling edge n-grams.

They were applied to **Elasticsearch only**, on purpose — the point was to fix the engine the
POC recommends, not to re-run the comparison. That makes the two engines asymmetric from here
on: the ES numbers in the Conclusion above are the post-fix ones, and the MySQL side still has
the wider `+meir* +antwerpen*` gate and no exact-name or alias boost. Read the agreement
figures accordingly — some of the remaining disagreement is now ours rather than the engines'.
If the comparison ever has to be defended again as a like-for-like measurement, either mirror
the three changes in `MysqlSuggester` or re-run it against the previous commit.
