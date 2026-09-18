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
 */
enum SuggestionType: string
{
    case Address = 'address';
    case Street = 'street';
    case Municipality = 'municipality';
    case Postcode = 'postcode';

    /**
     * Search API filter family this suggestion feeds into.
     */
    public function filter(): string
    {
        return match ($this) {
            self::Address, self::Street => 'coordinates',
            self::Municipality, self::Postcode => 'region',
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
