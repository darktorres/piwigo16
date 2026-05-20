<?php

declare(strict_types=1);

namespace Piwigo\Image;

/** History `image_type` column — the four values the DB enum recognises. */
enum ImageType: string
{
    case None    = 'none';
    case Picture = 'picture';
    case High    = 'high';
    case Other   = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
