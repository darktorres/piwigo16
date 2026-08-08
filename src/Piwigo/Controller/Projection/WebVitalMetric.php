<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

/**
 * {@see \Piwigo\Controller\VitalsController}'s own parsed RUM beacon
 * shape.
 */
final readonly class WebVitalMetric
{
    public function __construct(
        public string $name,
        public float $value,
        public string $id,
        public string $rating,
        public string $url,
    ) {}

    /**
     * {@see \Psr\Log\LoggerInterface::info()}'s `$context` parameter is a
     * plain array -- unbox to array at that logging boundary.
     *
     * @return array{name: string, value: float, id: string, rating: string, url: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
            'id' => $this->id,
            'rating' => $this->rating,
            'url' => $this->url,
        ];
    }
}
