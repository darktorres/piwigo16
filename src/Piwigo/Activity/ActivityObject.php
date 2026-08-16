<?php

declare(strict_types=1);

namespace Piwigo\Activity;

/**
 * The kinds of thing an activity row can be about, and which typed column
 * holds the reference to one.
 *
 * `activity.object` has always been a free string written by ~65 call sites.
 * This does not change that -- {@see ActivityService::record()} still takes a
 * string, because retyping every caller is a separate job -- but it gives the
 * discriminator-to-column mapping a single home, so the exclusive arc cannot
 * drift away from the constraints in `Version20260815230000`.
 *
 * `System` is the odd one and the reason this enum is worth having:
 * `object = 'system'` rows never referenced a row at all. They carry an
 * {@see \Piwigo\Core\ActivitySystem} constant (`Core`, `Plugin`, `Theme`),
 * which used to be stuffed into `object_id` alongside genuine row ids. It now
 * has its own column, so one column no longer means two unrelated things.
 */
enum ActivityObject: string
{
    case User = 'user';
    case Album = 'album';
    case Photo = 'photo';
    case Tag = 'tag';
    case Group = 'group';
    case System = 'system';

    /**
     * The typed reference column for this kind, or null when there is no
     * referenced row -- `System`, and any discriminator a plugin invents that
     * this enum does not know.
     */
    public function referenceColumn(): ?string
    {
        return match ($this) {
            self::User => 'userId',
            self::Album => 'categoryId',
            self::Photo => 'imageId',
            self::Tag => 'tagId',
            self::Group => 'groupId',
            self::System => null,
        };
    }
}
