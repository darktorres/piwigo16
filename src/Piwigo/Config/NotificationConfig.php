<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * Recent-post-date limits for RSS and NBM notification channels.
 */
final readonly class NotificationConfig
{
    /**
     * @var array{RSS: array{max_dates: int, max_elements: int, max_cats: int}, NBM: array{max_dates: int, max_elements: int, max_cats: int}}
     */
    private const array DEFAULTS = [
        'RSS' => [
            'max_dates' => 5,
            'max_elements' => 6,
            'max_cats' => 6,
        ],
        'NBM' => [
            'max_dates' => 7,
            'max_elements' => 3,
            'max_cats' => 9,
        ],
    ];

    public function __construct(
        public NotificationChannelConfig $rss,
        public NotificationChannelConfig $nbm,
    ) {}

    public static function default(): self
    {
        return new self(
            rss: new NotificationChannelConfig(maxDates: 5, maxElements: 6, maxCats: 6),
            nbm: new NotificationChannelConfig(maxDates: 7, maxElements: 3, maxCats: 9),
        );
    }

    /**
     * Coerces a raw config-table array (`recent_post_dates`'s JSON-decoded
     * shape) into a real value object, falling back to DEFAULTS per field
     * and per channel for anything missing or wrongly-shaped.
     *
     * @param array<mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $build = static function (string $key) use ($value): NotificationChannelConfig {
            $default = self::DEFAULTS[$key];
            $src = (isset($value[$key]) && is_array($value[$key])) ? $value[$key] : $default;

            return new NotificationChannelConfig(
                maxDates: isset($src['max_dates']) && is_int($src['max_dates']) ? $src['max_dates'] : $default['max_dates'],
                maxElements: isset($src['max_elements']) && is_int($src['max_elements']) ? $src['max_elements'] : $default['max_elements'],
                maxCats: isset($src['max_cats']) && is_int($src['max_cats']) ? $src['max_cats'] : $default['max_cats'],
            );
        };

        return new self(rss: $build('RSS'), nbm: $build('NBM'));
    }
}
