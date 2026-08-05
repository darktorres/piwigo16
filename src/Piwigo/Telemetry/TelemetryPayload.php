<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

use DateTimeImmutable;

/**
 * The complete anonymous "phone home" payload. `installId` is a random
 * value generated once per install (see TelemetryService::resolveInstallId())
 * -- never derived from anything identifying (site URL, admin email, IP).
 * No field on this object or its nested DTOs carries PII: only version
 * strings and aggregate counts.
 */
final readonly class TelemetryPayload
{
    public function __construct(
        public string $installId,
        public DateTimeImmutable $generatedAt,
        public EnvironmentInfo $environment,
        public DatabaseInfo $database,
        public GalleryStats $gallery,
        public ExtensionStats $extensions,
    ) {}

    /**
     * @return array{
     *     install_id: string,
     *     generated_at: string,
     *     environment: array{piwigo_version: string, php_version: string, os_family: string},
     *     database: array{driver: string, server_version: string},
     *     gallery: array{image_count: int, category_count: int, user_count: int, comment_count: int},
     *     extensions: array{plugin_count: int, theme_count: int, language_count: int},
     * }
     */
    public function toArray(): array
    {
        return [
            'install_id' => $this->installId,
            'generated_at' => $this->generatedAt->format(DATE_ATOM),
            'environment' => $this->environment->toArray(),
            'database' => $this->database->toArray(),
            'gallery' => $this->gallery->toArray(),
            'extensions' => $this->extensions->toArray(),
        ];
    }
}
