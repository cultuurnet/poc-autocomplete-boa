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
     * The address half of a label: "Goorbaan, 2230 Herselt", or
     * "Wolterslaan, 9040 Sint-Amandsberg (Gent)" when the postal locality
     * differs from the municipality.
     *
     * Shared by the address register and the place export so that the address
     * shown inside a place label reads exactly like a standalone address
     * suggestion; two spellings of the same address in one dropdown look like
     * two different places.
     */
    public static function addressLine(
        string $street,
        string $postcode,
        string $postName,
        string $municipality,
    ): string {
        $locality = $postName === '' ? $municipality : $postName;
        $label = trim(sprintf('%s %s', $postcode, $locality));

        // An empty street is a place-export situation, not a register one: the
        // register always knows the street, while placeLabel() passes '' here
        // every time it decides the street line is not worth printing. Without
        // this guard that produced the stray leading comma of
        // "Cruisterminal Terminal (, 2000 Antwerpen)".
        if ($street !== '') {
            $label = sprintf('%s, %s', $street, $label);
        }

        if ($municipality !== '' && mb_strtolower($locality, 'UTF-8') !== mb_strtolower($municipality, 'UTF-8')) {
            $label .= sprintf(' (%s)', $municipality);
        }

        return $label;
    }

    /**
     * The label for a place: the name, with the register address behind it in
     * brackets - "Yper Museum (Grote Markt 34, 8900 Ieper)" - minus whatever
     * of those brackets the name already said.
     *
     * A place name in the export is whatever an organiser typed into a name
     * field, and thousands of them are not venue names at all but localities
     * ("Brussel"), postcode-and-locality pairs ("1000 Brussel") or entire
     * addresses ("Urselweg 47, 9990 Maldegem"). Appending the address to those
     * gave "1000 Brussel (1000 Brussel, 1000 Brussel)": one fact spelled three
     * times, and a row that is all noise to skim past.
     *
     * So the two halves of the bracket - the street line, and the
     * "1000 Brussel" tail - are each printed only when they carry something
     * the name does not, and the brackets disappear when neither does. The
     * test is deliberately one-directional: a half is dropped because the name
     * already shows it, never because it looks unimportant, so this can shorten
     * a label but can never remove the only mention of something.
     */
    public static function placeLabel(
        string $name,
        string $street,
        string $postcode,
        string $postName,
        string $municipality,
    ): string {
        $locality = $postName === '' ? $municipality : $postName;

        $showStreet = self::streetAddsToName($name, $street, $postcode, $locality, $municipality);
        $showLocality = self::localityAddsToName($name, $postcode, $locality, $municipality);

        if (!$showStreet && !$showLocality) {
            return $name;
        }

        if (!$showLocality) {
            return sprintf('%s (%s)', $name, $street);
        }

        return sprintf(
            '%s (%s)',
            $name,
            self::addressLine($showStreet ? $street : '', $postcode, $postName, $municipality),
        );
    }

    /**
     * Whether the street line says anything this name does not.
     *
     * Three ways it does not. It can be blank or pure punctuation - the export
     * is full of "-", ".", "/ /", "???" typed to get past a required field, all
     * of which fold away to nothing. It can be the locality over again
     * ("Brussel", "1000 Brussel"), which is the tail's job and the shape behind
     * the reported "1000 Brussel (1000 Brussel, 1000 Brussel)". Or the name can
     * already spell it out, as in "LDC De Boei - Vaartstraat 1 - 9000 Gent".
     *
     * That last test matches whole words of the folded string, so it is the
     * full street line that has to be present, house number included: a name
     * that stops at "Sporthal Kerkstraat" keeps its brackets when the register
     * says "Kerkstraat 12", because the number is then the one new thing in
     * them and it is what tells two entrances on a street apart.
     */
    private static function streetAddsToName(
        string $name,
        string $street,
        string $postcode,
        string $locality,
        string $municipality,
    ): bool {
        $folded = Normalizer::normalize($street);

        if ($folded === '') {
            return false;
        }

        $echoes = [
            $locality,
            $municipality,
            $postcode,
            $postcode . ' ' . $locality,
            $postcode . ' ' . $municipality,
            $locality . ' ' . $postcode,
            $locality . ' ' . $municipality,
        ];

        foreach ($echoes as $echo) {
            if ($folded === Normalizer::normalize($echo)) {
                return false;
            }
        }

        return !self::carries($name, $street);
    }

    /**
     * Whether the "1000 Brussel" tail says anything this name does not.
     *
     * Only when the name already carries the postcode and the locality as one
     * adjacent run, which is what an organiser who typed an address into the
     * name field produced. The postcode is doing the work in that pair: a bare
     * "Brussel" shares its label with a municipality suggestion and with every
     * other place someone named after the city, so
     * "Brussel (Brussel, 1000 Brussel)" is shortened to "Brussel (1000 Brussel)"
     * and not to a naked "Brussel". The repetition worth deleting is the
     * locality; the postcode appears nowhere else in that row and is half of
     * what the user typed to get there.
     *
     * When the postal locality is not the municipality, the municipality rides
     * along in the tail ("9040 Sint-Amandsberg (Gent)") and is the one thing
     * that places Sint-Amandsberg for someone who only knows Gent, so the name
     * has to carry that too before the tail can go.
     */
    private static function localityAddsToName(
        string $name,
        string $postcode,
        string $locality,
        string $municipality,
    ): bool {
        if (!self::carries($name, $postcode . ' ' . $locality)) {
            return true;
        }

        if ($municipality === '' || Normalizer::normalize($locality) === Normalizer::normalize($municipality)) {
            return false;
        }

        return !self::carries($name, $municipality);
    }

    /**
     * Whether $phrase occurs in $name on word boundaries, both folded.
     *
     * Folded because the export agrees with the register on almost nothing
     * else: "9040 Sint-Amandsberg" against "9040 SINT AMANDSBERG", "Kaïrostraat
     * 91" against "kairostraat 91". Comparing raw text would find the duplicate
     * only when the two happened to be typed identically, which is the rarer
     * case. The folding is the one both engines already index through, so a
     * label hides exactly what a search would have treated as the same words.
     *
     * The spaces around both sides are what keep "Markt" from matching inside
     * "Marktplein": a shared prefix is not the same street.
     */
    private static function carries(string $name, string $phrase): bool
    {
        $folded = Normalizer::normalize($phrase);

        if ($folded === '') {
            return false;
        }

        return str_contains(' ' . Normalizer::normalize($name) . ' ', ' ' . $folded . ' ');
    }

    /**
     * Collapse every whitespace run into a single space.
     *
     * The place export is hand-entered and regularly carries newlines and runs
     * of dozens of spaces inside a name or a street line. Left alone they reach
     * the dropdown verbatim and blow past the column widths for nothing.
     */
    public static function collapseWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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
