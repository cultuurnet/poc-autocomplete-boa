<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turns the raw register values into something displayable.
 *
 * The source CSV is inconsistent about casing (`HERSELT` next to `Ramsel`) and
 * packs several sub-localities into one `postname` field separated by slashes
 * (`Grimminge/Idegem/Nieuwenhove/...`). Both need cleaning up before a label
 * ends up in a dropdown.
 */
final class NameFormatter
{
    /**
     * Lowercase words that stay lowercase inside a Dutch/French place name.
     */
    private const PARTICLES = ['de', 'den', 'der', 'het', 'ten', 'ter', 'van', 'op', 'aan', 'bij', 'sur', 'les', 'le', 'la', 'aux', 'au', 'sous'];

    /**
     * Title-case a name that the register stored in all caps, leaving mixed
     * case names untouched (they are already correct, and re-casing would break
     * things like "'s-Gravenwezel" or "De Haan").
     */
    public static function titleCase(string $name): string
    {
        $name = trim($name);

        if ($name === '' || $name !== mb_strtoupper($name, 'UTF-8')) {
            return $name;
        }

        $lower = mb_strtolower($name, 'UTF-8');

        // Capitalise after a space, a hyphen or an apostrophe.
        $result = preg_replace_callback(
            '/(^|[\s\-\'])([\p{L}])/u',
            static fn (array $m): string => $m[1] . mb_strtoupper($m[2], 'UTF-8'),
            $lower,
        ) ?? $name;

        // Put the particles back to lowercase when they are not the first word.
        return preg_replace_callback(
            '/(?<=[\s\-])(\p{L}+)/u',
            static function (array $m): string {
                $word = mb_strtolower($m[1], 'UTF-8');

                return in_array($word, self::PARTICLES, true) ? $word : $m[1];
            },
            $result,
        ) ?? $result;
    }

    /**
     * Split a `postname` into its individual sub-localities.
     *
     * @return list<string>
     */
    public static function postNameParts(?string $postName): array
    {
        if ($postName === null || trim($postName) === '') {
            return [];
        }

        $parts = array_map(
            static fn (string $p): string => self::titleCase(trim($p)),
            explode('/', $postName),
        );

        return array_values(array_unique(array_filter($parts, static fn (string $p): bool => $p !== '')));
    }

    /**
     * Pick the single locality name to show next to a postcode.
     *
     * A postcode that covers several sub-localities has no meaningful single
     * name, so we fall back to the municipality. A postcode that maps to one
     * sub-locality (9040 -> Sint-Amandsberg) shows that sub-locality, because
     * that is the name people actually use and search for.
     *
     * @param list<string> $parts
     */
    public static function displayPostName(array $parts, string $municipalityName): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        foreach ($parts as $part) {
            if (mb_strtolower($part, 'UTF-8') === mb_strtolower($municipalityName, 'UTF-8')) {
                return self::titleCase($municipalityName);
            }
        }

        return self::titleCase($municipalityName);
    }
}
