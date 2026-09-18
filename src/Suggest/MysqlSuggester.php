<?php

declare(strict_types=1);

namespace App\Suggest;

use App\Model\Suggestion;
use App\Model\SuggestionType;
use App\Model\SuggestQuery;
use App\Model\SuggestResult;
use App\Support\Normalizer;
use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * Type-ahead over the flat MySQL table.
 *
 * MySQL has one text retrieval mechanism worth using here (InnoDB FULLTEXT in
 * boolean mode) and it only answers "which rows contain these terms". It has no
 * usable notion of which hit is the better suggestion: boolean-mode ranks are a
 * crude TF-IDF sum, and with wildcard terms they mostly reflect how many
 * expanded terms happened to hit. So the interesting part of this class is not
 * the matching but the scoring expression that runs on top of it, which encodes
 * the things a human means by "the best suggestion":
 *
 *   - a hit whose own name starts with what you are typing beats a hit that
 *     only matches because you named its city;
 *   - earlier tokens read as context (city, postcode), the last token reads as
 *     the thing being typed;
 *   - big places win ties, but only ties: popularity enters logarithmically so
 *     Antwerpen cannot bury an exact street match;
 *   - a bare city name should return the city, not three thousand streets in it.
 *
 * Two candidate passes feed that expression. The fulltext pass answers the
 * multi-token "street + city" queries; a plain indexed prefix scan on
 * primary_name_norm answers "the name starts with what I typed", which is both
 * the most common autocomplete case and the one where a one or two character
 * boolean-mode wildcard would otherwise expand into millions of postings.
 */
final class MysqlSuggester implements SuggesterInterface
{
    private const ENGINE = 'mysql';
    private const FULLTEXT_INDEX = 'ft_search_text';

    /** Columns every pass returns, in addition to the scoring components. */
    private const BASE_COLUMNS = '`id`, `doc_type`, `label`, `street_name`, `house_number`, `postcode`, '
        . '`post_name`, `municipality_name`, `nis_code`, `lat`, `lon`, `popularity`, `primary_name_norm`';

    /**
     * Shorter terms are not sent to the fulltext index. innodb_ft_min_token_size
     * is 1 so they would work, but `+a*` expands to every term in the index and
     * unions their posting lists before anything can be filtered. Short tokens
     * become a LIKE over the fulltext candidate set instead, which costs
     * nothing once a longer token has narrowed the set down.
     */
    private const FULLTEXT_MIN_PREFIX = 3;

    /**
     * InnoDB boolean-mode ranks are unbounded and dominated by term rarity, so
     * they are capped and used as weak evidence rather than as the ranking.
     */
    private const FT_SCORE_CAP = 10.0;
    private const FT_WEIGHT = 1.0;

    /** The whole typed string is the head of this document's name. */
    private const W_QUERY_PREFIX = 60.0;
    /** ...and there is nothing after it: "gent" -> the municipality Gent. */
    private const W_EXACT_NAME = 25.0;
    /** The token being typed is the head of the name: "gent kort" -> Kortrijksepoortstraat. */
    private const W_LAST_TOKEN_PREFIX = 28.0;
    /** An earlier token is the head of the name: "kort gent" reads the other way round. */
    private const W_FIRST_TOKEN_PREFIX = 18.0;
    /** The typed token starts some later word of the name: "linden" -> Korte Lindenstraat. */
    private const W_WORD_PREFIX = 8.0;
    /** Per token that lands on this document's municipality or postal locality. */
    private const W_CONTEXT = 10.0;
    private const W_POSTCODE_HIT = 18.0;
    private const W_HOUSE_NUMBER_HIT = 20.0;
    private const W_POPULARITY = 2.0;

    /**
     * Candidates fetched per pass. Every pass orders by the same final score,
     * so merging the top N of each still yields the true global top `limit`.
     */
    private const POOL_FACTOR = 10;
    private const POOL_MIN = 50;
    private const POOL_MAX = 300;

    /**
     * Fuzzy hits are damped by their edit distance and then pushed below the
     * exact ones: a misspelling is a guess, and the UI should show it as the
     * tail of the list rather than as a competitor to a literal match.
     */
    private const FUZZY_PENALTY = 15.0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'location_suggestions',
    ) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $this->table) !== 1) {
            throw new InvalidArgumentException(sprintf('Unsafe MySQL table name: %s', $this->table));
        }
    }

    public function name(): string
    {
        return self::ENGINE;
    }

    public function suggest(SuggestQuery $query): SuggestResult
    {
        if ($query->isEmpty()) {
            return new SuggestResult(self::ENGINE, [], 0.0, 0, ['skipped' => 'empty query, no statement sent']);
        }

        $tokens = $this->sanitizeTokens($query->tokens);

        if ($tokens === []) {
            return new SuggestResult(self::ENGINE, [], 0.0, 0, ['skipped' => 'query held no usable tokens']);
        }

        $limit = max(1, $query->limit);
        $pool = min(self::POOL_MAX, max(self::POOL_MIN, $limit * self::POOL_FACTOR));
        $types = $query->typeValues();
        $terms = $this->scoringTerms($tokens);

        $booleanQuery = $this->booleanQuery($tokens);
        $shortTokens = $this->shortTokens($tokens);
        $namePrefix = implode(' ', $tokens) . '%';

        /** @var array<string, array{row: array<string, mixed>, score: float, pass: string, debug: array<string, mixed>}> $candidates */
        $candidates = [];
        /** @var list<array<string, mixed>> $passDebug */
        $passDebug = [];
        $engineNs = 0;

        if ($booleanQuery !== '') {
            [$sql, $params] = $this->fulltextPassSql($terms, $booleanQuery, $shortTokens, $types, $pool);
            $engineNs += $this->runPass('fulltext', $sql, $params, $terms, $candidates, $passDebug);
        }

        // The prefix pass never returns address documents: their name is the
        // street name, so a prefix scan would return the same street a thousand
        // times over with only the house number differing. Addresses are only
        // reachable through the fulltext pass, which also sees the number.
        $prefixTypes = array_values(array_filter(
            $types,
            static fn (string $t): bool => $t !== SuggestionType::Address->value,
        ));

        if ($prefixTypes !== []) {
            [$sql, $params] = $this->prefixPassSql($terms, $namePrefix, $prefixTypes, $pool);
            $engineNs += $this->runPass('name_prefix', $sql, $params, $terms, $candidates, $passDebug);
        }

        $fuzzyDebug = ['enabled' => $query->fuzzy, 'used' => false];
        $rerankNs = 0;

        if ($query->fuzzy && count($candidates) < $limit) {
            [$engineNs, $rerankNs, $fuzzyDebug] = $this->fuzzyFallback(
                $tokens,
                $terms,
                $types,
                $prefixTypes,
                $pool,
                $engineNs,
                $candidates,
                $passDebug,
            );
        }

        $suggestions = $this->rank($candidates, $limit);

        return new SuggestResult(
            engine: self::ENGINE,
            suggestions: $suggestions,
            tookMs: $engineNs / 1_000_000,
            // MySQL cannot give an exact hit count without running the same
            // match a second time under COUNT(*), which would double the cost
            // of every keystroke. The candidate count is what we report.
            total: count($candidates),
            debug: [
                'table' => $this->table,
                'tokens' => $tokens,
                'boolean_query' => $booleanQuery,
                'short_token_filters' => $shortTokens,
                'name_prefix' => $namePrefix,
                'doc_types' => $types,
                'candidate_pool' => $pool,
                'fuzzy' => $fuzzyDebug,
                'php_rerank_ms' => round($rerankNs / 1_000_000, 3),
                'passes' => $passDebug,
            ],
        );
    }

    public function health(): array
    {
        try {
            // An exact count, not information_schema.TABLE_ROWS: the estimate
            // there is routinely off by double digit percentages and a wrong
            // document count in a side-by-side comparison is worse than the
            // index scan this costs. Health is checked once per page load.
            $statement = $this->pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $this->table));
            $documents = $statement === false ? 0 : (int) $statement->fetchColumn();
        } catch (PDOException $e) {
            // The comparison UI renders both engines side by side and must
            // survive one of them being down, so this never throws. Covers a
            // dead connection and a missing table alike; the driver message
            // says which.
            return ['ok' => false, 'detail' => 'MySQL not usable: ' . $e->getMessage(), 'documents' => 0];
        }

        if ($documents === 0) {
            return ['ok' => false, 'detail' => sprintf('`%s` is empty, run the import', $this->table), 'documents' => 0];
        }

        try {
            $ready = $this->hasFulltextIndex();
        } catch (PDOException $e) {
            return ['ok' => false, 'detail' => 'MySQL not usable: ' . $e->getMessage(), 'documents' => $documents];
        }

        if (!$ready) {
            return [
                'ok' => false,
                // The index is added by MysqlIndexer::finish(); its absence
                // means the import was interrupted, not that MySQL is broken.
                'detail' => 'FULLTEXT index is missing, the import did not finish',
                'documents' => $documents,
            ];
        }

        return ['ok' => true, 'detail' => 'ready', 'documents' => $documents];
    }

    // ---------------------------------------------------------------- passes

    /**
     * @param list<array{alias: string, expr: string, params: list<string>, weight: float}> $terms
     * @param list<string>                                                                  $shortTokens
     * @param list<string>                                                                  $types
     *
     * @return array{0: string, 1: list<string>}
     */
    private function fulltextPassSql(
        array $terms,
        string $booleanQuery,
        array $shortTokens,
        array $types,
        int $limit,
    ): array {
        $match = 'MATCH(`search_text`) AGAINST (? IN BOOLEAN MODE)';

        // The same MATCH has to appear in the WHERE clause as well: MySQL only
        // uses the fulltext index for the predicate, never for a select-list
        // expression, so dropping it there would turn this into a table scan.
        $where = [$match];
        $whereParams = [$booleanQuery];

        foreach ($shortTokens as $token) {
            // search_text is single-space separated, so "some word starts with
            // ab" is "ab%" at the head or "% ab%" anywhere after it. No index
            // can serve this, but it only ever runs over the rows the fulltext
            // predicate already kept.
            $where[] = '(`search_text` LIKE ? OR `search_text` LIKE ?)';
            $whereParams[] = $token . '%';
            $whereParams[] = '% ' . $token . '%';
        }

        [$typeSql, $typeParams] = $this->typeCondition($types);

        if ($typeSql !== null) {
            $where[] = $typeSql;
            $whereParams = [...$whereParams, ...$typeParams];
        }

        return $this->buildSql($terms, $match, [$booleanQuery], $where, $whereParams, $limit);
    }

    /**
     * @param list<array{alias: string, expr: string, params: list<string>, weight: float}> $terms
     * @param list<string>                                                                  $types
     *
     * @return array{0: string, 1: list<string>}
     */
    private function prefixPassSql(array $terms, string $namePrefix, array $types, int $limit): array
    {
        $where = ['`primary_name_norm` LIKE ?'];
        $whereParams = [$namePrefix];

        [$typeSql, $typeParams] = $this->typeCondition($types);

        if ($typeSql !== null) {
            $where[] = $typeSql;
            $whereParams = [...$whereParams, ...$typeParams];
        }

        return $this->buildSql($terms, '0', [], $where, $whereParams, $limit);
    }

    /**
     * Assemble one pass.
     *
     * The scoring components are selected individually in a derived table and
     * summed in the outer query. That is not only for the debug panel: writing
     * the sum once keeps the weights in one place, and MySQL 8 merges the
     * derived table away so it costs nothing in the plan.
     *
     * @param list<array{alias: string, expr: string, params: list<string>, weight: float}> $terms
     * @param list<string>                                                                  $scoreParams
     * @param list<string>                                                                  $where
     * @param list<string>                                                                  $whereParams
     *
     * @return array{0: string, 1: list<string>}
     */
    private function buildSql(
        array $terms,
        string $scoreExpr,
        array $scoreParams,
        array $where,
        array $whereParams,
        int $limit,
    ): array {
        $select = [self::BASE_COLUMNS, sprintf('%s AS ft_score', $scoreExpr)];
        $params = $scoreParams;
        $sum = sprintf('LEAST(c.ft_score, %.2F) * %.2F', self::FT_SCORE_CAP, self::FT_WEIGHT);

        foreach ($terms as $term) {
            // COALESCE because every nullable column involved (post_name,
            // postcode, house_number) would otherwise turn its comparison into
            // NULL and propagate that straight through the sum.
            $select[] = sprintf('COALESCE(%s, 0) AS %s', $term['expr'], $term['alias']);
            $params = [...$params, ...$term['params']];
            $sum .= sprintf(' + %.2F * c.%s', $term['weight'], $term['alias']);
        }

        $params = [...$params, ...$whereParams];

        // The limit is an int from config/request handling, never a string, so
        // interpolating it avoids the PDO dance of binding LIMIT as PARAM_INT.
        $sql = sprintf(
            "SELECT c.*, (%s) AS score\nFROM (\n    SELECT %s\n    FROM `%s`\n    WHERE %s\n) AS c\n"
                . "ORDER BY score DESC, c.popularity DESC, c.id ASC\nLIMIT %d",
            $sum,
            implode(",\n           ", $select),
            $this->table,
            implode("\n      AND ", $where),
            $limit,
        );

        return [$sql, $params];
    }

    /**
     * @param list<string>                                                                                          $params
     * @param list<array{alias: string, expr: string, params: list<string>, weight: float}>                          $terms
     * @param array<string, array{row: array<string, mixed>, score: float, pass: string, debug: array<string, mixed>}> $candidates
     * @param list<array<string, mixed>>                                                                            $passDebug
     *
     * @return int nanoseconds spent inside MySQL
     */
    private function runPass(
        string $pass,
        string $sql,
        array $params,
        array $terms,
        array &$candidates,
        array &$passDebug,
    ): int {
        $started = hrtime(true);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();
        $elapsed = hrtime(true) - $started;

        foreach ($rows as $row) {
            $this->collect($candidates, $row, (float) $row['score'], $pass, [
                'components' => $this->components($row, $terms),
            ]);
        }

        $passDebug[] = [
            'pass' => $pass,
            'sql' => $sql,
            'params' => $params,
            'rows' => count($rows),
            'took_ms' => round($elapsed / 1_000_000, 3),
        ];

        return (int) $elapsed;
    }

    // ----------------------------------------------------------------- fuzzy

    /**
     * MySQL has no fuzzy matching. What it does have is a prefix index and a
     * fulltext index, so the honest approximation is: assume the typo is not in
     * the first half of the word, search on a truncated prefix of the token
     * most likely to carry it, and sort the result out in PHP with
     * levenshtein(). That is visibly weaker than a real edit-distance search -
     * a typo in the second character is simply not found - and that gap is one
     * of the things this POC is meant to show, so it is not papered over.
     *
     * @param list<string>                                                                                          $tokens
     * @param list<array{alias: string, expr: string, params: list<string>, weight: float}>                          $terms
     * @param list<string>                                                                                          $types
     * @param list<string>                                                                                          $prefixTypes
     * @param array<string, array{row: array<string, mixed>, score: float, pass: string, debug: array<string, mixed>}> $candidates
     * @param list<array<string, mixed>>                                                                            $passDebug
     *
     * @return array{0: int, 1: int, 2: array<string, mixed>}
     */
    private function fuzzyFallback(
        array $tokens,
        array $terms,
        array $types,
        array $prefixTypes,
        int $pool,
        int $engineNs,
        array &$candidates,
        array &$passDebug,
    ): array {
        $target = $this->fuzzyToken($tokens);

        if ($target === null) {
            return [$engineNs, 0, ['enabled' => true, 'used' => false, 'reason' => 'no token long enough to relax']];
        }

        // Half the token, never below three characters: shorter than that and
        // the relaxed prefix matches so much that the levenshtein pass ends up
        // ranking noise.
        $relaxed = substr($target, 0, max(self::FULLTEXT_MIN_PREFIX, intdiv(strlen($target), 2)));
        $maxDistance = $this->maxDistance($target);

        /** @var array<string, array{row: array<string, mixed>, score: float, pass: string, debug: array<string, mixed>}> $fuzzyCandidates */
        $fuzzyCandidates = [];

        // Anchor on every other token exactly; only the suspect one is relaxed.
        $anchors = array_values(array_filter($tokens, static fn (string $t): bool => $t !== $target));
        $relaxedBoolean = $this->booleanQuery([...$anchors, $relaxed]);

        if ($relaxedBoolean !== '') {
            [$sql, $params] = $this->fulltextPassSql(
                $terms,
                $relaxedBoolean,
                $this->shortTokens($anchors),
                $types,
                $pool,
            );
            $engineNs += $this->runPass('fuzzy_fulltext', $sql, $params, $terms, $fuzzyCandidates, $passDebug);
        }

        // Single-token misspellings ("antwrpen") never reach the fulltext pass
        // because the relaxed term is all there is; the prefix index answers
        // them directly and cheaply.
        if ($prefixTypes !== []) {
            [$sql, $params] = $this->prefixPassSql($terms, $relaxed . '%', $prefixTypes, $pool);
            $engineNs += $this->runPass('fuzzy_prefix', $sql, $params, $terms, $fuzzyCandidates, $passDebug);
        }

        $started = hrtime(true);
        $kept = 0;

        foreach ($fuzzyCandidates as $id => $candidate) {
            if (isset($candidates[$id])) {
                continue; // already found exactly; the exact score is the better one
            }

            $distance = $this->distance($target, (string) $candidate['row']['primary_name_norm']);

            if ($distance > $maxDistance) {
                continue;
            }

            $similarity = max(0.0, 1.0 - $distance / max(1, strlen($target)));
            $score = $candidate['score'] * $similarity - self::FUZZY_PENALTY;

            $this->collect($candidates, $candidate['row'], $score, $candidate['pass'], [
                ...$candidate['debug'],
                'fuzzy_token' => $target,
                'fuzzy_distance' => $distance,
                'fuzzy_similarity' => round($similarity, 3),
                'score_before_fuzzy' => round($candidate['score'], 3),
            ]);
            ++$kept;
        }

        return [
            $engineNs,
            (int) (hrtime(true) - $started),
            [
                'enabled' => true,
                'used' => true,
                'token' => $target,
                'relaxed_prefix' => $relaxed,
                'max_distance' => $maxDistance,
                'boolean_query' => $relaxedBoolean,
                'candidates' => count($fuzzyCandidates),
                'accepted' => $kept,
            ],
        ];
    }

    /**
     * The longest token is the one a typo is most likely to hide in, and it is
     * also the one whose truncated prefix stays selective. Ties go to the last
     * token, which is the one still under the cursor.
     *
     * @param list<string> $tokens
     */
    private function fuzzyToken(array $tokens): ?string
    {
        $target = null;

        foreach ($tokens as $token) {
            if (strlen($token) <= self::FULLTEXT_MIN_PREFIX) {
                continue;
            }

            if ($target === null || strlen($token) >= strlen($target)) {
                $target = $token;
            }
        }

        return $target;
    }

    private function maxDistance(string $token): int
    {
        return match (true) {
            strlen($token) <= 5 => 1,
            strlen($token) <= 9 => 2,
            default => 3,
        };
    }

    /**
     * Smallest edit distance between the token and any word of the candidate's
     * name, comparing against that word truncated to the token's length: the
     * user is mid-word, so "goorb" must read as a good match for "goorbaan"
     * rather than as four deletions away from it.
     *
     * levenshtein() is byte based and refuses strings over 255 bytes. Both
     * sides come out of Normalizer, so they are ASCII (one byte per character)
     * and primary_name_norm is capped at 190 by the column definition.
     */
    private function distance(string $token, string $primaryName): int
    {
        $best = PHP_INT_MAX;

        foreach (explode(' ', $primaryName) as $word) {
            if ($word === '') {
                continue;
            }

            $best = min($best, levenshtein($token, substr($word, 0, strlen($token))));
        }

        return $best === PHP_INT_MAX ? strlen($token) : $best;
    }

    // --------------------------------------------------------------- scoring

    /**
     * The ranking model, one term per line.
     *
     * @param list<string> $tokens
     *
     * @return list<array{alias: string, expr: string, params: list<string>, weight: float}>
     */
    private function scoringTerms(array $tokens): array
    {
        $joined = implode(' ', $tokens);
        $first = $tokens[0];
        $last = $tokens[array_key_last($tokens)];
        $multi = count($tokens) > 1;

        $terms = [];

        // Everything typed so far is the head of this name. By far the
        // strongest signal there is, and the reason "korte linden" finds Korte
        // Lindenstraat before any street merely located in a Linden.
        $terms[] = [
            'alias' => 'hit_query_prefix',
            'expr' => '`primary_name_norm` LIKE ?',
            'params' => [$joined . '%'],
            'weight' => self::W_QUERY_PREFIX,
        ];

        // Stacked on the previous one: the name is exactly what was typed and
        // nothing more. Without it "gent" ranks Gentbrugge next to Gent.
        $terms[] = [
            'alias' => 'hit_exact_name',
            'expr' => '`primary_name_norm` = ?',
            'params' => [$joined],
            'weight' => self::W_EXACT_NAME,
        ];

        if ($multi) {
            // For a single token these two collapse onto hit_query_prefix and
            // would just triple-count it.
            //
            // The last token outweighs the first because of how people type:
            // "gent kort" means a street starting with "kort" in Gent, not a
            // street starting with "gent" in Kortrijk.
            $terms[] = [
                'alias' => 'hit_last_token',
                'expr' => '`primary_name_norm` LIKE ?',
                'params' => [$last . '%'],
                'weight' => self::W_LAST_TOKEN_PREFIX,
            ];
            $terms[] = [
                'alias' => 'hit_first_token',
                'expr' => '`primary_name_norm` LIKE ?',
                'params' => [$first . '%'],
                'weight' => self::W_FIRST_TOKEN_PREFIX,
            ];
        }

        // Weak, and deliberately only about later words: the head of the name
        // is already covered above, so this is what puts Korte Lindenstraat in
        // the list for "linden" without letting it compete with Lindenlaan.
        $terms[] = [
            'alias' => 'hit_word_prefix',
            'expr' => '`primary_name_norm` LIKE ?',
            'params' => ['% ' . $last . '%'],
            'weight' => self::W_WORD_PREFIX,
        ];

        // Tokens that land on the place rather than on the name. This is what
        // separates "Kortrijksepoortstraat in Gent" from a street called
        // Gentstraat that merely happens to contain both words somewhere in its
        // haystack. Compared against the display columns, which carry the
        // accent- and case-insensitive server collation; a hyphenated
        // municipality ("Sint-Genesius-Rode") only matches on its first part,
        // which is good enough for a context bonus.
        $contextParts = [];
        $contextParams = [];

        foreach ($tokens as $token) {
            if (ctype_digit($token)) {
                continue; // numbers are handled by the postcode/house number terms
            }

            $contextParts[] = "(`municipality_name` LIKE ? OR COALESCE(`post_name`, '') LIKE ?)";
            $contextParams[] = $token . '%';
            $contextParams[] = $token . '%';
        }

        if ($contextParts !== []) {
            $terms[] = [
                'alias' => 'hit_context',
                'expr' => '(' . implode(' + ', $contextParts) . ')',
                'params' => $contextParams,
                'weight' => self::W_CONTEXT,
            ];
        }

        // "2230 goorb": the 2230 matching the postcode column is meaningful,
        // the same digits matching a house number somewhere else are not.
        foreach ($tokens as $token) {
            if (Normalizer::isPostcodeToken($token)) {
                $terms[] = [
                    'alias' => 'hit_postcode',
                    'expr' => "COALESCE(`postcode`, '') = ?",
                    'params' => [$token],
                    'weight' => self::W_POSTCODE_HIT,
                ];

                break;
            }
        }

        $houseNumber = null;

        foreach ($tokens as $token) {
            if (Normalizer::isHouseNumberToken($token)) {
                $houseNumber = $token;
                $terms[] = [
                    'alias' => 'hit_house_number',
                    'expr' => "COALESCE(`house_number`, '') = ?",
                    'params' => [$token],
                    'weight' => self::W_HOUSE_NUMBER_HIT,
                ];

                break;
            }
        }

        $terms[] = [
            'alias' => 'type_weight',
            'expr' => $this->typeWeightExpr($houseNumber !== null),
            'params' => [],
            'weight' => 1.0,
        ];

        // Damped on purpose. Linear popularity would rank every street in
        // Antwerpen above the exact street you asked for in Herselt; LOG10
        // compresses 1..200000 into 1..5.3, which is enough to break ties
        // between comparable candidates and never enough to overturn a
        // name match.
        $terms[] = [
            'alias' => 'popularity_boost',
            'expr' => 'LOG10(10 + `popularity`)',
            'params' => [],
            'weight' => self::W_POPULARITY,
        ];

        return $terms;
    }

    /**
     * Per document type, the prior for "is this the kind of thing meant here".
     *
     * A municipality outranks the three thousand streets inside it, otherwise
     * typing "herselt" returns an arbitrary alphabet of Herselt streets and
     * never Herselt itself. Addresses are the mirror image: without a house
     * number in the query they are strictly worse than the street they belong
     * to and would otherwise fill the list with Goorbaan 1, 2, 3, so they are
     * pushed down hard until a number shows up, and promoted above the street
     * once one does.
     */
    private function typeWeightExpr(bool $houseNumberTyped): string
    {
        return $houseNumberTyped
            ? "CASE `doc_type` WHEN 'municipality' THEN 12 WHEN 'postcode' THEN 10 WHEN 'street' THEN 2 ELSE 14 END"
            : "CASE `doc_type` WHEN 'municipality' THEN 12 WHEN 'postcode' THEN 10 WHEN 'street' THEN 4 ELSE -20 END";
    }

    /**
     * @param array<string, mixed>                                                         $row
     * @param list<array{alias: string, expr: string, params: list<string>, weight: float}> $terms
     *
     * @return array<string, mixed> the weighted contribution of every term, for the UI
     */
    private function components(array $row, array $terms): array
    {
        $components = [
            'fulltext' => round(min((float) $row['ft_score'], self::FT_SCORE_CAP) * self::FT_WEIGHT, 3),
        ];

        foreach ($terms as $term) {
            $components[$term['alias']] = round((float) $row[$term['alias']] * $term['weight'], 3);
        }

        return $components;
    }

    // ----------------------------------------------------------------- utils

    /**
     * @param array<string, array{row: array<string, mixed>, score: float, pass: string, debug: array<string, mixed>}> $candidates
     * @param array<string, mixed>                                                                                    $row
     * @param array<string, mixed>                                                                                    $debug
     */
    private function collect(array &$candidates, array $row, float $score, string $pass, array $debug): void
    {
        $id = (string) $row['id'];

        // A document can surface in several passes; keep the best explanation.
        if (isset($candidates[$id]) && $candidates[$id]['score'] >= $score) {
            return;
        }

        $candidates[$id] = ['row' => $row, 'score' => $score, 'pass' => $pass, 'debug' => $debug];
    }

    /**
     * @param array<string, array{row: array<string, mixed>, score: float, pass: string, debug: array<string, mixed>}> $candidates
     *
     * @return list<Suggestion>
     */
    private function rank(array $candidates, int $limit): array
    {
        $candidates = array_values($candidates);

        // Popularity and id as tie-breakers so two runs of the benchmark over
        // the same data produce the same list.
        usort($candidates, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score']
                ?: (int) $b['row']['popularity'] <=> (int) $a['row']['popularity']
                ?: strcmp((string) $a['row']['id'], (string) $b['row']['id']);
        });

        $suggestions = [];

        foreach (array_slice($candidates, 0, $limit) as $candidate) {
            $row = $candidate['row'];

            $suggestions[] = new Suggestion(
                id: (string) $row['id'],
                type: SuggestionType::from((string) $row['doc_type']),
                label: (string) $row['label'],
                streetName: $row['street_name'] === null ? null : (string) $row['street_name'],
                houseNumber: $row['house_number'] === null ? null : (string) $row['house_number'],
                postcode: $row['postcode'] === null ? null : (string) $row['postcode'],
                postName: $row['post_name'] === null ? null : (string) $row['post_name'],
                municipalityName: (string) $row['municipality_name'],
                nisCode: (string) $row['nis_code'],
                lat: $row['lat'] === null ? null : (float) $row['lat'],
                lon: $row['lon'] === null ? null : (float) $row['lon'],
                score: $candidate['score'],
                popularity: (int) $row['popularity'],
                debug: ['pass' => $candidate['pass'], ...$candidate['debug']],
            );
        }

        return $suggestions;
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    private function sanitizeTokens(array $tokens): array
    {
        $sanitized = [];

        foreach ($tokens as $token) {
            // The Normalizer already reduces input to [a-z0-9 ], but boolean
            // mode reads + - > < ( ) ~ * " @ as operators and a single stray
            // one silently turns the query into a different query. Never trust
            // a caller to have normalised.
            $token = preg_replace('/[^a-z0-9]/', '', strtolower($token)) ?? '';

            if ($token !== '') {
                // No name in the table is longer than the column, and
                // levenshtein() refuses strings over 255 bytes, so a pasted
                // wall of text is clipped rather than allowed to blow up.
                $sanitized[] = substr($token, 0, 190);
            }
        }

        return $sanitized;
    }

    /**
     * Every token becomes a required prefix term: `+gent* +kort*`. The leading
     * `+` is what makes boolean mode an AND, the trailing `*` is what makes it
     * type-ahead.
     *
     * @param list<string> $tokens
     */
    private function booleanQuery(array $tokens): string
    {
        $terms = [];

        foreach ($tokens as $token) {
            if (strlen($token) < self::FULLTEXT_MIN_PREFIX) {
                continue; // handled by shortTokenPatterns()
            }

            $terms[] = '+' . $token . '*';
        }

        return implode(' ', $terms);
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<string> the tokens too short to hand to the fulltext index
     */
    private function shortTokens(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            static fn (string $token): bool => strlen($token) < self::FULLTEXT_MIN_PREFIX,
        ));
    }

    /**
     * @param list<string> $types
     *
     * @return array{0: string|null, 1: list<string>}
     */
    private function typeCondition(array $types): array
    {
        $types = array_values(array_unique($types));

        // All four types means no filter at all; adding the predicate anyway
        // only tempts the optimizer into idx_doc_type, which is useless here.
        if (count($types) >= count(SuggestionType::values())) {
            return [null, []];
        }

        $placeholders = implode(', ', array_fill(0, count($types), '?'));

        return [sprintf('`doc_type` IN (%s)', $placeholders), $types];
    }

    private function hasFulltextIndex(): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
        );
        $statement->execute([$this->table, self::FULLTEXT_INDEX]);

        return (int) $statement->fetchColumn() > 0;
    }
}
