<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET` filter shape for HistoryPageRenderer::render() (page
 * slug "history"). `ip`/`imageId` are only ever
 * pattern-validated then passed straight through to the template (never
 * computed on) -- both already pattern-checked (digits/dots for ip,
 * digits for image id, not mandatory) before this DTO returns, then
 * narrowed to a clean `?string` via the same `is_string(...) ? ... :
 * null` local guard `FeedRequest.php` already uses after its own
 * `validate()` call.
 */
final readonly class HistoryFilterRequest
{
    private function __construct(
        public ?string $ip,
        public ?string $imageId,
        public int $userId,
        public bool $hasAnyFilter,
    ) {}

    public static function fromGlobals(InputValidator $inputValidator): self
    {
        return self::fromArray($_GET, $inputValidator);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source, InputValidator $inputValidator): self
    {
        $inputValidator
            ->validate('filter_ip', $source, false, '/^[0-9.]+$/');
        $inputValidator
            ->validate('filter_image_id', $source, false, '/^\d+$/');
        $inputValidator
            ->validate('filter_user_id', $source, false, '/^\d+$/');

        $ip_raw = $source['filter_ip'] ?? null;
        $ip = is_string($ip_raw) ? $ip_raw : null;

        $image_id_raw = $source['filter_image_id'] ?? null;
        $imageId = is_string($image_id_raw) ? $image_id_raw : null;

        $user_id_raw = $source['filter_user_id'] ?? null;
        $userId = isset($source['filter_user_id']) && is_numeric($user_id_raw) ? (int) $user_id_raw : -1;

        $hasAnyFilter = isset($source['filter_ip']) || isset($source['filter_image_id']) || isset($source['filter_user_id']);

        return new self($ip, $imageId, $userId, $hasAnyFilter);
    }
}
