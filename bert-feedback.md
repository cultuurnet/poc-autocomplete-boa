Hey Bert,

1° Voor de google maps API keyheb ik in #ops geregeld met Catarina - is gebeurd.
2° De score wordt niet door een simpele field-lookup gemaakt, maar is een combinatie van verschillende factoren, met boosts getweaked.

Zie alle code in de functie `precisionClauses`: https://github.com/cultuurnet/poc-autocomplete-boa/blob/main/src/Suggest/ElasticsearchSuggester.php#L296
Al die boosts kan natuurlijk getweaked worden.

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

Ik vind door je vraag een probleem gevonden, de `municipality_name` scoort **0**. De gemeente van dit document is *Leuven*;
"Wijgmaal" zit in `post_name`. De correct getypte gemeentenaam werd dus niet beloond zoals bedoeld, hij komt alleen via `aliases` en `post_name` binnen.

Is er een max, en kan het naar %
Nee, er is geen maximum score, de score is altijd relatief aan de query die je
stelt en aan de index waarin je zoekt. Elasticsearch telt per query de
bijdragen op van alle clauses die vuren, en dat aantal groeit gewoon mee met wat
je typt: elk extra token kan een extra postcode of andere clause activeren, en
elke clause draagt zijn eigen waarde bij. Die waarde is op haar beurt
geen vast getal, maar hangt af van  hoe zeldzaam een termis des te meer waard het is.
Als ik het goed begrijp levert een langere query met zeldzamere woorden eenvoudigweg een
grotere som op, zonder plafond. Scores vanuit verschillende queryies kan je dus niet zo makkelijk vergelijken.

Nu, ik ga eerlijk zijn, dat echt algo van de score erachter is ook voor mij wat magie, ik heb het meeste gehaald uit dit artikel (en claude) https://medium.com/@sany2k8dev/tf-idf-vs-bm25-in-elasticsearch-whats-the-difference-96c126d47394
