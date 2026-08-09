<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * The last update-availability notification shown to the admin.
 */
final readonly class UpdateNotification
{
    public function __construct(
        public ?string $version,
        public ?string $notifiedOn,
    ) {}

    /**
     * @param array<mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $version = $value['version'] ?? null;
        $notifiedOn = $value['notified_on'] ?? null;

        return new self(
            version: is_string($version) ? $version : null,
            notifiedOn: is_string($notifiedOn) ? $notifiedOn : null,
        );
    }
}
