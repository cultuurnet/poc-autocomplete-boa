<?php

declare(strict_types=1);

namespace App\Import;

use App\Model\SuggestionType;
use App\Suggest\ElasticsearchMapping;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Transport\Exception\NoNodeAvailableException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Writes SuggestionDocuments into Elasticsearch 8.
 *
 * The import is the one place where the two engines are allowed to differ in
 * cost, so it is worth being explicit about what is being traded: the index is
 * put into a write-optimised mode (no refresh, no replicas) for the duration and
 * then deliberately pushed back into the state a production cluster would be in
 * before anything is measured. Otherwise the benchmark would be comparing a
 * freshly-bulk-loaded, hundred-segment index against a settled MySQL table.
 */
final class ElasticsearchIndexer implements IndexerInterface
{
    /**
     * 2000 documents is roughly a 2-5 MB bulk request for this data, which is
     * inside the range where Elasticsearch is happiest: big enough that the
     * per-request overhead disappears, small enough to stay well clear of the
     * default 100 MB http.max_content_length and of long GC pauses.
     */
    private const BATCH_SIZE = 2000;

    /**
     * How many failing bulk items to quote before giving up on detail. The point
     * is to make a partial import impossible to miss, not to print 4.2M reasons.
     */
    private const MAX_REPORTED_FAILURES = 5;

    /**
     * Bulk payload under construction: alternating action and source lines.
     *
     * @var list<array<string, mixed>>
     */
    private array $buffer = [];

    private int $buffered = 0;

    private int $indexed = 0;

    public function __construct(
        private readonly Client $client,
        private readonly string $index,
    ) {
        if (preg_match('/^[a-z0-9_\-]+$/', $this->index) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Invalid Elasticsearch index name: "%s"', $this->index),
            );
        }
    }

    public function name(): string
    {
        return 'elasticsearch';
    }

    public function prepare(bool $recreate): void
    {
        if ($recreate && $this->indexExists()) {
            $this->client->indices()->delete(['index' => $this->index]);
        }

        if ($this->indexExists()) {
            // Re-importing into an existing index: the mapping is already there,
            // but the write-optimised settings still have to be applied.
            $this->client->indices()->putSettings([
                'index' => $this->index,
                'body' => ElasticsearchMapping::importSettings(),
            ]);

            return;
        }

        $definition = ElasticsearchMapping::definition();
        // Import-mode settings are merged into the create call rather than sent
        // as a follow-up PUT, so the index never spends a moment refreshing.
        $definition['settings']['index'] = [
            ...$definition['settings']['index'],
            ...ElasticsearchMapping::importSettings()['index'],
        ];

        $this->client->indices()->create([
            'index' => $this->index,
            'body' => $definition,
        ]);
    }

    public function deleteType(SuggestionType $type): int
    {
        // See MysqlIndexer::deleteType(): buffered documents belong to the type
        // about to be deleted.
        $this->flush();

        if (!$this->indexExists()) {
            return 0;
        }

        $response = $this->client->deleteByQuery([
            'index' => $this->index,
            // The import runs with refresh_interval -1, so without this the
            // deletes stay invisible and a follow-up count() reports the old
            // number. conflicts=proceed because a concurrent write is not a
            // reason to abandon a reset.
            'refresh' => true,
            'conflicts' => 'proceed',
            'body' => ['query' => ['term' => ['doc_type' => $type->value]]],
        ])->asArray();

        return (int) ($response['deleted'] ?? 0);
    }

    public function add(SuggestionDocument $document): void
    {
        // Using the document's own id as _id makes a re-import an upsert instead
        // of a duplication, so an interrupted import can simply be re-run.
        $this->buffer[] = ['index' => ['_index' => $this->index, '_id' => $document->id]];
        $this->buffer[] = $this->source($document);
        ++$this->buffered;

        if ($this->buffered >= self::BATCH_SIZE) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $body = $this->buffer;
        $count = $this->buffered;
        $this->buffer = [];
        $this->buffered = 0;

        $response = $this->client->bulk([
            'index' => $this->index,
            'body' => $body,
        ])->asArray();

        // A bulk request answers 200 even when individual documents were
        // rejected. Skipping this check is how an import silently loses a slice
        // of the corpus and poisons every recall number in the comparison.
        if (($response['errors'] ?? false) === true) {
            throw new RuntimeException($this->describeFailures($response));
        }

        $this->indexed += $count;
    }

    public function finish(): void
    {
        $this->flush();

        $this->client->indices()->putSettings([
            'index' => $this->index,
            'body' => ElasticsearchMapping::steadyStateSettings(),
        ]);

        $this->client->indices()->refresh(['index' => $this->index]);

        // A bulk-loaded index is spread over many segments, and every query pays
        // for each of them (term dictionary lookup per segment, then a merge of
        // the per-segment hits). Production indices settle into few large
        // segments on their own; forcing that here means the benchmark measures
        // the steady state instead of the load state. This is also why it is not
        // fatal when it fails: the merge is an optimisation, and once the request
        // has reached Elasticsearch it keeps running server-side even if our HTTP
        // client gives up waiting on a multi-gigabyte index.
        try {
            $this->client->indices()->forcemerge([
                'index' => $this->index,
                'max_num_segments' => 1,
            ]);
        } catch (Throwable) {
            // Intentionally swallowed; see above.
        }
    }

    public function count(): int
    {
        try {
            return (int) ($this->client->count(['index' => $this->index])->asArray()['count'] ?? 0);
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return 0;
            }

            throw $e;
        }
    }

    /**
     * Documents handed to Elasticsearch so far in this run.
     */
    public function indexed(): int
    {
        return $this->indexed;
    }

    /**
     * @return array<string, mixed>
     */
    private function source(SuggestionDocument $document): array
    {
        $source = [
            'id' => $document->id,
            'doc_type' => $document->type->value,
            'label' => $document->label,
            'place_name' => $document->placeName,
            'street_name' => $document->streetName,
            'municipality_name' => $document->municipalityName,
            'post_name' => $document->postName,
            'aliases' => $document->aliases,
            'postcode' => $document->postcode,
            'house_number' => $document->houseNumber,
            'box_number' => $document->boxNumber,
            'nis_code' => $document->nisCode,
            'popularity' => $document->popularity,
            'rank_tier' => $document->type->rankTier(),
            // Pre-folded in PHP so MySQL FULLTEXT and this field hold byte-identical
            // text; the analyser then only has to n-gram it.
            'search_text' => $document->searchText(),
            'primary_name' => $document->primaryName(),
        ];

        // geo_point rejects a null, and a half-null pair is meaningless anyway, so
        // the field is omitted rather than emptied when either side is missing.
        if ($document->lat !== null && $document->lon !== null) {
            $source['location'] = ['lat' => $document->lat, 'lon' => $document->lon];
        }

        return $source;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function describeFailures(array $response): string
    {
        $items = $response['items'] ?? [];
        $failed = 0;
        $reported = [];

        foreach (is_array($items) ? $items : [] as $item) {
            $operation = $item['index'] ?? $item['create'] ?? $item['update'] ?? null;

            if (!is_array($operation) || !isset($operation['error'])) {
                continue;
            }

            ++$failed;

            if (count($reported) < self::MAX_REPORTED_FAILURES) {
                $reported[] = sprintf(
                    '%s -> %s: %s',
                    (string) ($operation['_id'] ?? '?'),
                    (string) ($operation['error']['type'] ?? 'unknown'),
                    (string) ($operation['error']['reason'] ?? 'no reason given'),
                );
            }
        }

        return sprintf(
            'Elasticsearch bulk index reported %d failed document(s). First %d: %s',
            $failed,
            count($reported),
            $reported === [] ? '(none readable in response)' : implode(' | ', $reported),
        );
    }

    private function indexExists(): bool
    {
        try {
            // exists() is a HEAD request; the 8.x client returns the status as a
            // boolean rather than throwing on the 404, but the catch is kept so a
            // client that does throw behaves the same way.
            return $this->client->indices()->exists(['index' => $this->index])->asBool();
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return false;
            }

            throw $e;
        } catch (NoNodeAvailableException | ServerResponseException $e) {
            throw new RuntimeException(
                sprintf('Elasticsearch is not reachable: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }
}
