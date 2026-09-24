<?php

declare(strict_types=1);

namespace App\Import;

use App\Model\SuggestionType;
use App\Support\NameFormatter;
use App\Support\Normalizer;
use RuntimeException;

/**
 * Reads export_places_udb.csv and yields one document per UiTdatabank place.
 *
 * Unlike the address register this export needs no aggregation pass: it is
 * already one row per thing a user would pick, ~64k of them, so the file is
 * streamed once and every usable row becomes a document.
 *
 * What it does need is unpacking and repair. Name and address are JSON blobs
 * keyed by language; the text is hand-entered and carries newlines, runs of
 * spaces and the occasional whole sentence in the streetAddress field; and a
 * meaningful slice of the rows are not Belgian addresses at all despite what
 * their addressCountry says. Every rejection is counted and reported by the
 * command rather than silently dropped, because "why is my place missing"
 * has to be answerable without re-reading the CSV by hand.
 */
final class PlaceDocumentSource implements DocumentSourceInterface
{
    private const PROGRESS_EVERY = 10_000;

    /**
     * The popularity prior given to every place.
     *
     * Both engines damp popularity logarithmically and both treat it as a
     * ranking prior rather than a sort key, so a document needs *some* value:
     * at zero the Elasticsearch field_value_factor (log1p) multiplies the whole
     * score by log(1) = 0 and the place disappears entirely.
     *
     * The export carries no usage signal of any kind - no event count, no
     * popularity, nothing - so any value here is a statement about how a place
     * should compare to an address document, not a measurement. 24 is the
     * median number of addresses on a Flemish street in openaddress-bevlg.csv
     * (81,841 streets; p25 10, p75 53), which puts a place exactly level with a
     * typical street on the popularity term and leaves the doc_type prior - the
     * one place where the ordering is stated on purpose - to do the ranking.
     */
    public const POPULARITY = 24;

    /**
     * Why rows were skipped, for the import summary.
     *
     * @var array<string, int>
     */
    private array $skipped = [
        'unparseable' => 0,
        'no address' => 0,
        'not in belgium' => 0,
        'unusable postcode' => 0,
        'no name' => 0,
        'postcode filter' => 0,
    ];

    public function __construct(private readonly string $csvPath)
    {
        if (!is_readable($this->csvPath)) {
            throw new RuntimeException(sprintf('CSV not readable: %s', $this->csvPath));
        }
    }

    /**
     * @param callable(string, int): void|null $progress
     *
     * @return iterable<SuggestionDocument>
     */
    public function documents(ImportOptions $options, ?callable $progress = null): iterable
    {
        $handle = $this->open();
        $rows = 0;

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($options->limit !== null && $rows >= $options->limit) {
                break;
            }

            ++$rows;

            $document = $this->document($row, $options);

            if ($document !== null) {
                yield $document;
            }

            if ($progress !== null && $rows % self::PROGRESS_EVERY === 0) {
                $progress('places', $rows);
            }
        }

        fclose($handle);

        if ($progress !== null) {
            $progress('read', $rows);
        }
    }

    /**
     * Rejected rows by reason. Only meaningful after documents() has been
     * fully consumed.
     *
     * @return array<string, int>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * @param list<string|null> $row
     */
    private function document(array $row, ImportOptions $options): ?SuggestionDocument
    {
        if (count($row) < 4) {
            ++$this->skipped['unparseable'];

            return null;
        }

        $id = trim((string) $row[PlaceCsvColumns::ID]);
        $names = $this->decode((string) $row[PlaceCsvColumns::NAME]);
        $addresses = $this->decode((string) $row[PlaceCsvColumns::ADDRESS]);

        if ($id === '') {
            ++$this->skipped['unparseable'];

            return null;
        }

        $address = $this->pickAddress($addresses);

        if ($address === null) {
            ++$this->skipped['no address'];

            return null;
        }

        // Country first, then the postcode shape. Both are needed: 239 rows
        // claim addressCountry BE while carrying a postcode this index cannot
        // represent ("59000 Lille", "5521 ND Bergeik", "5113BV Baarle-Nassau"),
        // and a four-digit Belgian postcode is what the postcode column, the
        // --postcode filter and the postcode suggestions are all built on.
        if (($address['addressCountry'] ?? '') !== 'BE') {
            ++$this->skipped['not in belgium'];

            return null;
        }

        $postcode = NameFormatter::collapseWhitespace((string) ($address['postalCode'] ?? ''));

        if (!Normalizer::isPostcodeToken($postcode)) {
            ++$this->skipped['unusable postcode'];

            return null;
        }

        if (!$options->matchesPostcode($postcode)) {
            ++$this->skipped['postcode filter'];

            return null;
        }

        $name = $this->pickName($names);

        if ($name === '') {
            ++$this->skipped['no name'];

            return null;
        }

        $street = NameFormatter::collapseWhitespace((string) ($address['streetAddress'] ?? ''));
        [$postName, $municipality] = $this->locality(
            NameFormatter::collapseWhitespace((string) ($address['addressLocality'] ?? '')),
        );

        return new SuggestionDocument(
            id: 'place:' . $id,
            type: SuggestionType::Place,
            // "Yper Museum (Grote Markt 34, 8900 Ieper)". The parenthesised half
            // is built by the same helper the address documents use, so the two
            // spell the same address the same way - and is dropped where this
            // export's hand-typed names have already said it.
            label: NameFormatter::placeLabel($name, $street, $postcode, $postName, $municipality),
            placeName: $name,
            streetName: $street === '' ? null : $street,
            // The export gives one free-text street line ("Lakenhalle - Grote
            // Markt 34"), not a split number. Guessing the split would be wrong
            // often and silently; the number reaches the query through
            // search_text, which is where both engines rank house numbers from
            // anyway, so there is nothing to gain by inventing it.
            houseNumber: null,
            boxNumber: null,
            postcode: $postcode,
            postName: $postName === '' ? null : $postName,
            municipalityName: $municipality,
            nisCode: null,
            lat: null,
            lon: null,
            popularity: self::POPULARITY,
            aliases: $this->aliases($names, $name, $postName, $municipality),
        );
    }

    /**
     * Split "Onkerzele (Geraardsbergen)" into its sub-locality and its
     * municipality; 9,557 of the Belgian rows are written that way.
     *
     * Storing them apart rather than as one string is what makes a place
     * findable by either name and what lets the label render the pair the same
     * way an address suggestion does.
     *
     * @return array{0: string, 1: string} post name (may be empty), municipality
     */
    private function locality(string $locality): array
    {
        if (preg_match('/^(.+?)\s*\((.+)\)$/u', $locality, $matches) === 1) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return ['', $locality];
    }

    /**
     * Every other language's name, plus the locality names, as alternatives.
     *
     * @param array<string, mixed> $names
     *
     * @return list<string>
     */
    private function aliases(array $names, string $chosen, string $postName, string $municipality): array
    {
        $aliases = [];

        foreach ($names as $value) {
            if (!is_string($value)) {
                continue;
            }

            $alias = NameFormatter::collapseWhitespace($value);

            if ($alias !== '' && $alias !== $chosen) {
                $aliases[] = $alias;
            }
        }

        foreach ([$postName, $municipality] as $localityName) {
            if ($localityName !== '') {
                $aliases[] = $localityName;
            }
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @param array<string, mixed> $names
     */
    private function pickName(array $names): string
    {
        foreach (PlaceCsvColumns::LANGUAGE_PREFERENCE as $language) {
            if (isset($names[$language]) && is_string($names[$language])) {
                $name = NameFormatter::collapseWhitespace($names[$language]);

                if ($name !== '') {
                    return $name;
                }
            }
        }

        // 183 Belgian rows carry no Dutch name; a handful carry none of the
        // four preferred languages either ("pt", "cn", "sp"). Whatever is there
        // is still the name of a real place.
        foreach ($names as $value) {
            if (is_string($value)) {
                $name = NameFormatter::collapseWhitespace($value);

                if ($name !== '') {
                    return $name;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $addresses
     *
     * @return array<string, string>|null
     */
    private function pickAddress(array $addresses): ?array
    {
        foreach (PlaceCsvColumns::LANGUAGE_PREFERENCE as $language) {
            if (isset($addresses[$language]) && is_array($addresses[$language])) {
                return $this->stringValues($addresses[$language]);
            }
        }

        foreach ($addresses as $value) {
            if (is_array($value)) {
                return $this->stringValues($value);
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $address
     *
     * @return array<string, string>
     */
    private function stringValues(array $address): array
    {
        $values = [];

        foreach ($address as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return resource
     */
    private function open()
    {
        $handle = fopen($this->csvPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot open %s', $this->csvPath));
        }

        $header = fgetcsv($handle, 0, ',', '"', '');

        if ($header !== PlaceCsvColumns::EXPECTED_HEADER) {
            fclose($handle);

            throw new RuntimeException(
                'Unexpected CSV header. Expected: ' . implode(',', PlaceCsvColumns::EXPECTED_HEADER),
            );
        }

        return $handle;
    }
}
