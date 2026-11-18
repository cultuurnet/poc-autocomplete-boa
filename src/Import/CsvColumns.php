<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Column positions in openaddress-bevlg.csv.
 *
 * The header is verified against these on open, so a changed export fails loud
 * instead of silently importing the wrong fields.
 */
final class CsvColumns
{
    public const LAT = 2;
    public const LON = 3;
    public const ADDRESS_ID = 4;
    public const BOX_NUMBER = 5;
    public const HOUSE_NUMBER = 6;
    public const MUNICIPALITY_ID = 7;
    public const MUNICIPALITY_DE = 8;
    public const MUNICIPALITY_FR = 9;
    public const MUNICIPALITY_NL = 10;
    public const POSTCODE = 11;
    public const POSTNAME_FR = 12;
    public const POSTNAME_NL = 13;
    public const STREET_ID = 14;
    public const STREET_DE = 15;
    public const STREET_FR = 16;
    public const STREET_NL = 17;
    public const REGION_CODE = 18;
    public const STATUS = 19;

    public const EXPECTED_HEADER = [
        'EPSG:31370_x', 'EPSG:31370_y', 'EPSG:4326_lat', 'EPSG:4326_lon', 'address_id', 'box_number',
        'house_number', 'municipality_id', 'municipality_name_de', 'municipality_name_fr',
        'municipality_name_nl', 'postcode', 'postname_fr', 'postname_nl', 'street_id', 'streetname_de',
        'streetname_fr', 'streetname_nl', 'region_code', 'status',
    ];

    /**
     * The header with its two projected-coordinate columns renamed to the
     * EXPECTED_HEADER names, whatever projection the export uses.
     *
     * BOSA moved those columns from Lambert 72 (EPSG:31370) to Lambert 2008
     * (EPSG:3812). Nothing reads them - coordinates come from the EPSG:4326
     * pair - so the projection is not something worth refusing a file over;
     * every other column is still checked by name and position.
     *
     * @param list<string|null> $header
     *
     * @return list<string|null>
     */
    public static function normalizeHeader(array $header): array
    {
        foreach ([0 => 'x', 1 => 'y'] as $index => $axis) {
            if (is_string($header[$index] ?? null) && preg_match('/^EPSG:\d+_' . $axis . '$/', $header[$index]) === 1) {
                $header[$index] = self::EXPECTED_HEADER[$index];
            }
        }

        return $header;
    }
}
