<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Text normalisation shared by both engines.
 *
 * Both the MySQL and the Elasticsearch side must fold text in exactly the same
 * way, otherwise the comparison measures our preprocessing instead of the
 * engines. MySQL gets the folded string stored in a column; Elasticsearch gets
 * the same folding through its `lowercase` + `asciifolding` analyzer chain, and
 * the importer stores the folded string as well so the two stay in lockstep.
 */
final class Normalizer
{
    /**
     * Diacritics that occur in Belgian street and municipality names.
     * Kept as an explicit map so we do not depend on ext-intl and so the result
     * is byte-for-byte predictable across PHP builds.
     */
    private const FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ğ' => 'g',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i',
        'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o',
        'œ' => 'oe',
        'ř' => 'r',
        'ś' => 's', 'š' => 's', 'ş' => 's', 'ß' => 'ss',
        'ť' => 't', 'ţ' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    /**
     * Lowercase, strip diacritics, drop punctuation, collapse whitespace.
     *
     * "Sint-Genesius-Rode" -> "sint genesius rode"
     * "Rue de l'Église"    -> "rue de l eglise"
     */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        if ($value === '') {
            return '';
        }

        $value = strtr($value, self::FOLD);

        // Anything that is not a latin letter or a digit becomes a separator.
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * @return list<string> normalised tokens, empty tokens removed
     */
    public static function tokenize(string $value): array
    {
        $normalized = self::normalize($value);

        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $normalized), static fn (string $t): bool => $t !== ''));
    }

    /**
     * Build the single searchable haystack that gets stored per document.
     * Duplicate tokens are removed to keep the MySQL FULLTEXT index compact and
     * to avoid term-frequency inflating the score of documents that happen to
     * repeat a name (e.g. street "Gent" in municipality "Gent").
     *
     * @param array<int, string|null> $parts
     */
    public static function haystack(array $parts): string
    {
        $tokens = [];

        foreach ($parts as $part) {
            if ($part === null || $part === '') {
                continue;
            }

            foreach (self::tokenize($part) as $token) {
                $tokens[$token] = true;
            }
        }

        return implode(' ', array_keys($tokens));
    }

    /**
     * True when the token looks like a Belgian postcode (4 digits, 1000-9999).
     */
    public static function isPostcodeToken(string $token): bool
    {
        return preg_match('/^[1-9][0-9]{3}$/', $token) === 1;
    }

    /**
     * True when the token looks like a house number ("59", "12a", "3bis").
     */
    public static function isHouseNumberToken(string $token): bool
    {
        return preg_match('/^[0-9]{1,4}[a-z]{0,3}$/', $token) === 1 && !self::isPostcodeToken($token);
    }

    /**
     * True for the word that introduces a box number in a Belgian address:
     * "Kerkstraat 12 bus 5". NL "bus", FR "bte"/"boite".
     *
     * The bare "b" of "12 b 5" is deliberately absent: on its own it is the
     * first keystroke of far too many street and municipality names to spend on
     * a box marker. SuggestQuery reads it as one only directly after a house
     * number, where that ambiguity does not exist.
     */
    public static function isBoxMarkerToken(string $token): bool
    {
        return in_array($token, ['bus', 'bte', 'boite'], true);
    }
}