<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One `rates` entry of `rating.latte`'s `$images[]['rates']`, built by
 * {@see \Piwigo\Admin\RatingPageRenderer::render()} from a real
 * {@see \Piwigo\Rate\Projection\Rate} row plus one spliced view-only
 * field (`user`, the resolved display name or a `? {id}` fallback).
 */
final readonly class RatingReportRateRow
{
    public function __construct(
        public int $userId,
        public int $elementId,
        public string $anonymousId,
        public int $rate,
        public ?string $date,
        public string $user,
    ) {}

    /**
     * @return array{user_id: int, element_id: int, anonymous_id: string, rate: int, date: ?string, USER: string}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'element_id' => $this->elementId,
            'anonymous_id' => $this->anonymousId,
            'rate' => $this->rate,
            'date' => $this->date,
            'USER' => $this->user,
        ];
    }
}
