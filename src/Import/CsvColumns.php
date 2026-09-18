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
}
