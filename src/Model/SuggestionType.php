<?php

declare(strict_types=1);

namespace App\Model;

/**
 * What kind of thing a suggestion points at.
 *
 * The briefing splits suggestions in two consumer-facing categories: the ones
 * that resolve to a point ("coordinates" filter in the Search API) and the ones
 * that resolve to an administrative area ("region" filter). Keep that split
 * explicit in the response so the consuming UI can pick the right filter.
 *
 * Place is a third family. It comes from the UiTdatabank place export rather
 * than from the address register, and it does not resolve to a point at all:
 * the export carries no coordinates, and a place already *is* an identified
 * entity, so the consumer filters on its id instead of on a box around it.
 */
enum SuggestionType: string
{
    case Address = 'address';
    case Street = 'street';
    case Municipality = 'municipality';
    case Postcode = 'postcode';
    case Place = 'place';

    /**
     * Search API filter family this suggestion feeds into.
     */
    public function filter(): string
    {
        return match ($this) {
            self::Address, self::Street => 'coordinates',
            self::Municipality, self::Postcode => 'region',
            // The id of a Place suggestion is "place:<cdbid>"; the cdbid is what
            // a location.id filter expects.
            self::Place => 'place',
        };
    }

    /**
     * Ordering tier. Results are grouped by this first and only then by score,
     * so nothing in tier 1 can ever be listed above something in tier 0.
     *
     * The split is "a destination in its own right" against "somewhere inside
     * one": a municipality, a postcode and a place are things you can mean on
     * their own, while a street and a house number only exist within one. That
     * is also what makes a place outrank an address at the very same address -
     * asked for explicitly, and the reason the tier exists at all, because the
     * scoring alone could not deliver it: an address document is *named* after
     * the street you typed, so on text it legitimately beats the venue standing
     * on it.
     *
     * Municipality and postcode deliberately share tier 0 with place rather
     * than sitting in a tier above it. Their existing score prior already wins
     * the case that matters - a bare "gent" answers with the city, not with the
     * 56 places called Gent - and a tier above would make "markt 62 berlaar"
     * lead with the town, which is the opposite of what was typed.
     */
    public function rankTier(): int
    {
        return match ($this) {
            self::Municipality, self::Postcode, self::Place => 0,
            self::Street, self::Address => 1,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
