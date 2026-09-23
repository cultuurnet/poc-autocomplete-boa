<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Column positions in export_places_udb.csv.
 *
 * Verified against the header on open, exactly like CsvColumns, so a changed
 * export fails loud instead of silently importing the wrong fields.
 *
 * Three of the four columns hold JSON rather than a scalar, keyed by language:
 *
 *   name        {"nl": "Yper Museum", "fr": "Musée Yper"}
 *   description {"nl": "Het Yper Museum brengt je elf eeuwen ..."}
 *   address     {"nl": {"addressCountry": "BE", "addressLocality": "Ieper",
 *                       "postalCode": "8900", "streetAddress": "Grote Markt 34"}}
 *
 * DESCRIPTION is deliberately listed but never read: it is prose about what
 * happens at a place, not a name anyone types to find it, and feeding it into
 * search_text would make every document match nearly every query.
 */
final class PlaceCsvColumns
{
    public const ID = 0;
    public const NAME = 1;
    public const DESCRIPTION = 2;
    public const ADDRESS = 3;

    public const EXPECTED_HEADER = ['place_cdbid', 'name', 'description', 'address'];

    /**
     * Preference order for the language to display.
     *
     * Dutch first because the export is overwhelmingly Flemish (63,224 of
     * 64,301 rows carry an "nl" name); the rest is a fallback chain rather
     * than a policy, and every other language a row happens to carry is
     * indexed as an alias regardless of which one wins here.
     *
     * @var list<string>
     */
    public const LANGUAGE_PREFERENCE = ['nl', 'fr', 'de', 'en'];
}
