<?php

declare(strict_types=1);

namespace App\Import;

use App\Model\SuggestionType;
use App\Support\NameFormatter;
use RuntimeException;

/**
 * Reads openaddress-bevlg.csv and yields indexable documents.
 *
 * The file holds one row per individual address (4.2M of them for Flanders),
 * which is the wrong granularity for most autocomplete queries: nobody types a
 * house number to find a street. So the first pass aggregates rows into street,
 * municipality and postcode documents (~83k in total) with an average
 * coordinate and an address count that doubles as a popularity signal. A second
 * pass, only when house-number level is requested, streams the individual
 * addresses and reuses the counts from pass one for ranking.
 */
final class DocumentSource
{
    private const PROGRESS_EVERY = 250_000;

    public function __construct(private readonly string $csvPath)
    {
        if (!is_readable($this->csvPath)) {
            throw new RuntimeException(sprintf('CSV not readable: %s', $this->csvPath));
        }
    }

    /**
     * @param callable(string, int): void|null $progress receives a phase label and a row count
     *
     * @return iterable<SuggestionDocument>
     */
    public function documents(ImportOptions $options, ?callable $progress = null): iterable
    {
        $aggregates = $this->aggregate($options, $progress);

        if ($options->streets) {
            yield from $this->streetDocuments($aggregates['streets']);
        }

        if ($options->regions) {
            yield from $this->municipalityDocuments($aggregates['municipalities']);
            yield from $this->postcodeDocuments($aggregates['postcodes']);
        }

        if ($options->addresses) {
            yield from $this->addressDocuments($options, $aggregates['streets'], $progress);
        }
    }

    /**
     * Estimate of how many documents will be produced, for a progress bar.
     *
     * @return array{streets: int, municipalities: int, postcodes: int, addresses: int}
     */
    public function summarize(ImportOptions $options, ?callable $progress = null): array
    {
        $aggregates = $this->aggregate($options, $progress);

        return [
            'streets' => count($aggregates['streets']),
            'municipalities' => count($aggregates['municipalities']),
            'postcodes' => count($aggregates['postcodes']),
            'addresses' => $aggregates['rows'],
        ];
    }

    /**
     * Single pass over the CSV building the three aggregate tables.
     *
     * Accumulators are flat arrays rather than objects: at 83k streets the
     * object overhead would be tens of megabytes for no benefit.
     *
     * @return array{streets: array<string, array<int, mixed>>, municipalities: array<string, array<int, mixed>>, postcodes: array<string, array<int, mixed>>, rows: int}
     */
    private function aggregate(ImportOptions $options, ?callable $progress = null): array
    {
        $handle = $this->open();

        $streets = [];
        $municipalities = [];
        $postcodes = [];
        $rows = 0;

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($options->limit !== null && $rows >= $options->limit) {
                break;
            }

            if (!$this->isUsable($row, $options)) {
                continue;
            }

            ++$rows;

            $postcode = trim($row[CsvColumns::POSTCODE]);
            $nis = trim($row[CsvColumns::MUNICIPALITY_ID]);
            $streetId = trim($row[CsvColumns::STREET_ID]);
            $lat = (float) $row[CsvColumns::LAT];
            $lon = (float) $row[CsvColumns::LON];

            $streetKey = $streetId . '|' . $postcode;

            if (isset($streets[$streetKey])) {
                $streets[$streetKey][7] += $lat;
                $streets[$streetKey][8] += $lon;
                ++$streets[$streetKey][9];
            } else {
                $streets[$streetKey] = [
                    0 => trim($row[CsvColumns::STREET_NL]),
                    1 => trim($row[CsvColumns::STREET_FR]),
                    2 => trim($row[CsvColumns::STREET_DE]),
                    3 => $postcode,
                    4 => trim($row[CsvColumns::POSTNAME_NL]),
                    5 => $nis,
                    6 => trim($row[CsvColumns::MUNICIPALITY_NL]),
                    7 => $lat,
                    8 => $lon,
                    9 => 1,
                    10 => $streetId,
                ];
            }

            if (isset($municipalities[$nis])) {
                $municipalities[$nis][3] += $lat;
                $municipalities[$nis][4] += $lon;
                ++$municipalities[$nis][5];
            } else {
                $municipalities[$nis] = [
                    0 => trim($row[CsvColumns::MUNICIPALITY_NL]),
                    1 => trim($row[CsvColumns::MUNICIPALITY_FR]),
                    2 => trim($row[CsvColumns::MUNICIPALITY_DE]),
                    3 => $lat,
                    4 => $lon,
                    5 => 1,
                ];
            }

            if (isset($postcodes[$postcode])) {
                $postcodes[$postcode][3] += $lat;
                $postcodes[$postcode][4] += $lon;
                ++$postcodes[$postcode][5];
            } else {
                $postcodes[$postcode] = [
                    0 => trim($row[CsvColumns::POSTNAME_NL]),
                    1 => $nis,
                    2 => trim($row[CsvColumns::MUNICIPALITY_NL]),
                    3 => $lat,
                    4 => $lon,
                    5 => 1,
                ];
            }

            if ($progress !== null && $rows % self::PROGRESS_EVERY === 0) {
                $progress('reading', $rows);
            }
        }

        fclose($handle);

        if ($progress !== null) {
            $progress('read', $rows);
        }

        return [
            'streets' => $streets,
            'municipalities' => $municipalities,
            'postcodes' => $postcodes,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, array<int, mixed>> $streets
     *
     * @return iterable<SuggestionDocument>
     */
    private function streetDocuments(array $streets): iterable
    {
        foreach ($streets as $key => $s) {
            $name = $this->pickName((string) $s[0], (string) $s[1], (string) $s[2]);

            if ($name === '') {
                continue;
            }

            $postcode = (string) $s[3];
            $municipality = NameFormatter::titleCase((string) $s[6]);
            $parts = NameFormatter::postNameParts((string) $s[4]);
            $postName = NameFormatter::displayPostName($parts, $municipality);
            $count = (int) $s[9];

            yield new SuggestionDocument(
                id: 'street:' . $key,
                type: SuggestionType::Street,
                label: $this->placeLabel($name, $postcode, $postName, $municipality),
                streetName: $name,
                houseNumber: null,
                boxNumber: null,
                postcode: $postcode,
                postName: $postName,
                municipalityName: $municipality,
                nisCode: (string) $s[5],
                lat: $s[7] / $count,
                lon: $s[8] / $count,
                popularity: $count,
                aliases: $this->aliases([(string) $s[1], (string) $s[2]], $parts, $municipality),
            );
        }
    }

    /**
     * @param array<string, array<int, mixed>> $municipalities
     *
     * @return iterable<SuggestionDocument>
     */
    private function municipalityDocuments(array $municipalities): iterable
    {
        foreach ($municipalities as $nis => $m) {
            $name = NameFormatter::titleCase($this->pickName((string) $m[0], (string) $m[1], (string) $m[2]));

            if ($name === '') {
                continue;
            }

            $count = (int) $m[5];
            $aliases = array_values(array_filter([
                NameFormatter::titleCase((string) $m[1]),
                NameFormatter::titleCase((string) $m[2]),
            ], static fn (string $a): bool => $a !== '' && $a !== $name));

            yield new SuggestionDocument(
                id: 'municipality:' . $nis,
                type: SuggestionType::Municipality,
                label: $name,
                streetName: null,
                houseNumber: null,
                boxNumber: null,
                postcode: null,
                postName: null,
                municipalityName: $name,
                nisCode: (string) $nis,
                lat: $m[3] / $count,
                lon: $m[4] / $count,
                popularity: $count,
                aliases: $aliases,
            );
        }
    }

    /**
     * @param array<string, array<int, mixed>> $postcodes
     *
     * @return iterable<SuggestionDocument>
     */
    private function postcodeDocuments(array $postcodes): iterable
    {
        foreach ($postcodes as $postcode => $p) {
            $municipality = NameFormatter::titleCase((string) $p[2]);
            $parts = NameFormatter::postNameParts((string) $p[0]);
            $postName = NameFormatter::displayPostName($parts, $municipality);
            $count = (int) $p[5];

            $label = $postName === '' ? (string) $postcode : $postcode . ' ' . $postName;

            yield new SuggestionDocument(
                id: 'postcode:' . $postcode,
                type: SuggestionType::Postcode,
                label: $label,
                streetName: null,
                houseNumber: null,
                boxNumber: null,
                postcode: (string) $postcode,
                postName: $postName,
                municipalityName: $municipality,
                nisCode: (string) $p[1],
                lat: $p[3] / $count,
                lon: $p[4] / $count,
                popularity: $count,
                aliases: $this->aliases([], $parts, $municipality),
            );
        }
    }

    /**
     * Second pass: one document per house number.
     *
     * @param array<string, array<int, mixed>> $streets
     * @param callable(string, int): void|null $progress
     *
     * @return iterable<SuggestionDocument>
     */
    private function addressDocuments(ImportOptions $options, array $streets, ?callable $progress): iterable
    {
        $handle = $this->open();
        $rows = 0;

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($options->limit !== null && $rows >= $options->limit) {
                break;
            }

            if (!$this->isUsable($row, $options)) {
                continue;
            }

            ++$rows;

            $name = $this->pickName(
                trim($row[CsvColumns::STREET_NL]),
                trim($row[CsvColumns::STREET_FR]),
                trim($row[CsvColumns::STREET_DE]),
            );

            if ($name === '') {
                continue;
            }

            $postcode = trim($row[CsvColumns::POSTCODE]);
            $municipality = NameFormatter::titleCase(trim($row[CsvColumns::MUNICIPALITY_NL]));
            $parts = NameFormatter::postNameParts(trim($row[CsvColumns::POSTNAME_NL]));
            $postName = NameFormatter::displayPostName($parts, $municipality);
            $houseNumber = trim($row[CsvColumns::HOUSE_NUMBER]);
            $boxNumber = trim($row[CsvColumns::BOX_NUMBER]);
            $streetKey = trim($row[CsvColumns::STREET_ID]) . '|' . $postcode;

            $street = $name . ($houseNumber === '' ? '' : ' ' . $houseNumber)
                . ($boxNumber === '' ? '' : ' bus ' . $boxNumber);

            yield new SuggestionDocument(
                id: 'address:' . trim($row[CsvColumns::ADDRESS_ID]),
                type: SuggestionType::Address,
                label: $this->placeLabel($street, $postcode, $postName, $municipality),
                streetName: $name,
                houseNumber: $houseNumber === '' ? null : $houseNumber,
                boxNumber: $boxNumber === '' ? null : $boxNumber,
                postcode: $postcode,
                postName: $postName,
                municipalityName: $municipality,
                nisCode: trim($row[CsvColumns::MUNICIPALITY_ID]),
                lat: (float) $row[CsvColumns::LAT],
                lon: (float) $row[CsvColumns::LON],
                // Streets with many addresses are more likely to be what someone
                // means, so the street's size is a decent prior for its addresses.
                popularity: isset($streets[$streetKey]) ? (int) $streets[$streetKey][9] : 1,
                aliases: $this->aliases(
                    [trim($row[CsvColumns::STREET_FR]), trim($row[CsvColumns::STREET_DE])],
                    $parts,
                    $municipality,
                ),
            );

            if ($progress !== null && $rows % self::PROGRESS_EVERY === 0) {
                $progress('addresses', $rows);
            }
        }

        fclose($handle);
    }

    /**
     * "Goorbaan, 2230 Herselt" or "Wolterslaan, 9040 Sint-Amandsberg (Gent)"
     * when the postal locality differs from the municipality.
     */
    private function placeLabel(string $street, string $postcode, string $postName, string $municipality): string
    {
        $locality = $postName === '' ? $municipality : $postName;
        $label = sprintf('%s, %s %s', $street, $postcode, $locality);

        if ($municipality !== '' && mb_strtolower($locality) !== mb_strtolower($municipality)) {
            $label .= sprintf(' (%s)', $municipality);
        }

        return $label;
    }

    /**
     * @param list<string> $translations
     * @param list<string> $postNameParts
     *
     * @return list<string>
     */
    private function aliases(array $translations, array $postNameParts, string $municipality): array
    {
        $aliases = [...$translations, ...$postNameParts];

        if ($municipality !== '') {
            $aliases[] = $municipality;
        }

        return array_values(array_unique(array_filter($aliases, static fn (string $a): bool => $a !== '')));
    }

    private function pickName(string $nl, string $fr, string $de): string
    {
        return $nl !== '' ? $nl : ($fr !== '' ? $fr : $de);
    }

    /**
     * @param list<string|null> $row
     */
    private function isUsable(array $row, ImportOptions $options): bool
    {
        if (count($row) < 20) {
            return false;
        }

        if (trim((string) $row[CsvColumns::STATUS]) !== 'current') {
            return false;
        }

        $postcode = trim((string) $row[CsvColumns::POSTCODE]);

        if ($postcode === '' || !$options->matchesPostcode($postcode)) {
            return false;
        }

        return trim((string) $row[CsvColumns::LAT]) !== '' && trim((string) $row[CsvColumns::LON]) !== '';
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

        if ($header !== CsvColumns::EXPECTED_HEADER) {
            fclose($handle);

            throw new RuntimeException(
                'Unexpected CSV header. Expected: ' . implode(',', CsvColumns::EXPECTED_HEADER),
            );
        }

        return $handle;
    }
}
