<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/**
 * `pwg.images.upload` input DTO.
 *
 * Carries only the $params discrete fields; chunk metadata + the file
 * payload itself still come from $_REQUEST / $_FILES because plupload
 * sends them outside the JSON-RPC envelope.
 */
final readonly class UploadParams implements WsParams
{
    /** @param list<int> $categoryIds */
    public function __construct(
        public string $pwgToken,
        public ?string $name,
        public ?int $formatOf,
        public array $categoryIds,
        public ?int $level,
        public bool $updateMode,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $pwgToken = $raw['pwg_token'] ?? null;
        if (!is_string($pwgToken)) {
            throw new WsParamException('Missing pwg_token');
        }
        $nameIn      = $raw['name'] ?? null;
        $catRaw      = is_array($raw['category'] ?? null) ? $raw['category'] : [];
        $categoryIds = array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $catRaw,
        ));
        return new self(
            pwgToken:    $pwgToken,
            name:        is_string($nameIn) ? $nameIn : null,
            formatOf:    is_numeric($raw['format_of'] ?? null) ? (int) $raw['format_of'] : null,
            categoryIds: $categoryIds,
            level:       is_numeric($raw['level'] ?? null) ? (int) $raw['level'] : null,
            updateMode:  (bool) ($raw['update_mode'] ?? false),
        );
    }
}
