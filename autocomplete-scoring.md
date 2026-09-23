# Autocomplete POC — over de score

Antwoord op twee vragen over de `_score` die de POC bij elk resultaat toont:

1. Is er een maximum? Kunnen we de score herleiden naar een confidence in %?
2. Waarom zakt de score wanneer je een correcte postcode en gemeentenaam toevoegt?

Alle cijfers hieronder zijn gereproduceerd tegen de draaiende index
(`location_suggestions`, 82.643 documenten: 81.841 straten, 517 postcodes,
285 gemeenten) met `explain=1` op `/api/suggest`.

---

## Vraag 2 eerst: waarom zakt de score

Deze verklaart meteen een groot stuk van vraag 1.

De twee zwaarste clauses in `ElasticsearchSuggester::precisionClauses()` worden
gevoed met de **volledige querystring**, niet met het straatnaam-gedeelte ervan:

- `term` op `primary_name.keyword` (boost 12) — vuurt alleen als de hele getypte
  string *letterlijk gelijk* is aan de straatnaam;
- `match_phrase_prefix` op `primary_name.folded` (boost 8) — vuurt alleen als de
  hele string een phrase-prefix van de naam is.

Bij `"henricus wittebolsstraat"` ís de hele string de straatnaam, dus beide
vuren. Zodra je `3018 wijgmaal` toevoegt is de string geen straatnaam meer, en
vallen ze **allebei naar nul**.

Uit de Elasticsearch `explain` van beide queries, voor hetzelfde document
(Henricus Wittebolsstraat, 3018 Wijgmaal (Leuven)):

| clause | `henricus wittebolsstraat` | `+ 3018 wijgmaal` |
|---|---:|---:|
| recall gate (`search_text`) | 33,04 | 55,48 |
| `primary_name.keyword` exact (×12) | **131,00** | **0** |
| `match_phrase_prefix` (×8) | **133,57** | **0** |
| `primary_name` los, operator OR (×3) | 96,81 | 96,81 |
| `postcode` exact (×6) | – | 45,71 |
| `aliases` (×1,8) | – | 22,63 |
| `post_name` (×1,2) | – | 9,79 |
| `municipality_name` (×1,5) | – | 0 |
| fuzzy (×0,4) | 35,61 | 50,90 |
| **tekstsom** | **430,03** | **281,31** |
| × populariteitsfactor (log1p) | ×1,7745 | ×1,7745 |
| **totale score** | **763,09** | **499,20** |

Je verliest 264,6 punten en wint er 116 terug → netto −149, maal 1,77 = de ~264
punten die je ziet zakken. De populariteitsfactor is in beide gevallen identiek
(zelfde document), dus het verschil zit volledig in het tekstdeel.

### Twee details die opvallen

- `municipality_name` scoort **0**. De gemeente van dit document is *Leuven*;
  "Wijgmaal" zit in `post_name`. De correct getypte gemeentenaam werd dus niet
  beloond zoals bedoeld — hij komt alleen via `aliases` en `post_name` binnen.
- De fuzzy clause stijgt van 35,61 naar 50,90 puur omdat er meer tokens zijn.
  Dat is ruis in de score, geen extra bewijs van een betere match.

### Te repareren

Door de naam-clauses alleen de niet-locatie-tokens te voeren — `SuggestQuery`
splitst al half in locative/detail tokens — wordt de score weer monotoon.
Doorgerekend tegen dezelfde index:

```
huidige query  "henricus wittebolsstraat 3018 wijgmaal"  ->  499,20
naam-clauses gevoed met alleen "henricus wittebolsstraat" ->  968,68
ter vergelijking "henricus wittebolsstraat"               ->  763,09
```

Meer correcte informatie geeft dan ook een hogere score, zoals je zou verwachten.

---

## Vraag 1: is er een max, en kan het naar %

Nee, er is geen maximum score, de score is altijd relatief aan de query die je
stelt en aan de index waarin je zoekt. Elasticsearch telt per query de
bijdragen op van alle clauses die vuren, en dat aantal groeit gewoon mee met wat
je typt: elk extra token kan een extra postcode of andere clause activeren, en
elke clause draagt zijn eigen waarde bij. Die waarde is op haar beurt
geen vast getal, maar hangt af van  hoe zeldzaam een termis des te meer waard het is.
Als ik het goed begrijp levert een langere query met zeldzamere woorden eenvoudigweg een
grotere som op, zonder plafond. Scores vanuit verschillende queryies kan je dus niet zo makkelijk vergelijken.


Schematisch:

```
score = (som van de BM25-bijdragen van alle vurende clauses)
        × populariteitsfactor          (log1p, hier 0,4 – 12,1; max_boost 15)
```

### Waarom een drempel op de ruwe score niet werkt

Meting over een set van 20 realistische queries, top-1 score:

| query | score | hits | wat het is |
|---|---:|---:|---|
| `antwerpen` | 2363,5 | 1000+ | volledig ambigu |
| `gent` | 2031,7 | 1000+ | volledig ambigu |
| `2230` | 1622,1 | 175 | volledig ambigu |
| `kerkstraat` | 447,3 | 251 | volledig ambigu |
| `henricus wittebolsstraat 3018 wijgmaal` | 499,2 | 1 | exact wat je wil |
| `korte lindenstraat 9300 aalst` | 240,2 | 1 | exact wat je wil |
| `veldstraat 9000 gent` | 195,5 | 1 | exact wat je wil |

Een drempel die `gent` (2031) accepteert, verwerpt élke volledig
gespecificeerde straat. Er bestaat geen waarde die hier goed valt: de ruwe score
correleert niet met "is dit het juiste antwoord", maar met termzeldzaamheid,
querylengte en populariteit.

En zoals hierboven: omdat `idf` met de corpusgrootte meebeweegt, schuiven bij
elke her-import **alle** scores mee en verloopt een hardgecodeerde drempel
stilletjes. Precies het langetermijnprobleem dat de vraag aanstipt.

### Wat wel werkt voor de geocoding-usecase

Bereken een eigen confidence uit deterministische signalen, los van `_score`.
De bouwstenen zijn er al:

- straatnaam van de hit is (genormaliseerd) exact gelijk aan het naamdeel van de
  query;
- postcode in de query == postcode van de hit;
- gemeente / `post_name` in de query == die van de hit;
- de strikte pass volstond, er was geen fuzzy fallback nodig — staat vandaag al
  in `debug.fuzzy_fallback`;
- `total == 1`, of een grote marge tussen hit 1 en hit 2.

Dat levert een uitlegbare 0–100 op die stabiel blijft over re-imports, en waar
je wél een zinnige drempel op kan leggen — bijvoorbeeld: vanaf 80 de coördinaten
van de suggestie gebruiken, daaronder naar de externe geocoder.

Ruwe `_score` blijft dan waar hij voor bedoeld is: de volgorde bepalen binnen
één resultatenlijst.

---

## Bijvangst: een echte bug

`"kerkstraat 12 9000 gent"` geeft **0 resultaten**, terwijl
`"kerkstraat 9000 gent"` er 3 geeft.

```
kerkstraat 12 9000 gent    hits 0   fuzzy_fallback True    (niets)
kerkstraat 9000 gent       hits 3   fuzzy_fallback True    Kerkstraat, 9050 Gent
kerkstraat 12 9050 gent    hits 2   fuzzy_fallback False   Kerkstraat, 9050 Gent
kerkstraat 9050 gent       hits 2   fuzzy_fallback False   Kerkstraat, 9050 Gent
```

Oorzaak: de fuzzy-fallback tak van `ElasticsearchSuggester::gateClauses()` gooit
de volledige tekst door één fuzzy clause, **inclusief het huisnummer**. Daarmee
wordt het huisnummer in die pass weer verplicht — precies wat commit `c93ecc8`
had opgelost voor de strikte pass. Het probleem treedt alleen op wanneer de
strikte pass leeg terugkomt én er een huisnummer in de query staat.

---

## Los hiervan: Google Maps API key

Niet gerelateerd aan de POC, maar wel opgelijst omdat het in dezelfde vraag zat.

Volgorde van rotatie, in deze volgorde:

1. nieuwe key aanmaken;
2. secret vervangen in Vault;
3. devs verwittigen dat ze moeten syncen;
4. **pas daarna** de oude key deactiveren.

Aandachtspunt: als acc, test en lokale omgevingen dezelfde productiekey
gebruiken, maak dan afzonderlijke keys voor acc en test, en zet de acc-key in de
lokale config.

Merk op: een productiekey die ook in acc/test/lokale configs staat, moet als
gecompromitteerd beschouwd worden. Lokale omgevingen en gedeelde configs zijn
precies de plek waar zo'n key uitlekt. De rotatie is dan geen hygiëne maar een
incident, en de oude key moet na de overgang ook effectief gedeactiveerd worden,
niet enkel vervangen.
