<?php

declare(strict_types=1);

namespace Piwigo\Controller\Request;

use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET` shape for SearchController::__invoke() (replaces
 * search.php). `cat_id` is pattern-validated (`InputValidator::validate()`)
 * then parsed into a `?CategoryId`; a `hasCatId = true` with `catId ===
 * null` means the value was syntactically digits but non-positive (`0`),
 * which the controller routes to `pageNotFound()` rather than
 * `fatalError()`. `tag_id` stays a raw comma-separated string -- it's
 * exploded into free-text search tokens, never validated as real tag-id
 * references, and the original's own stricter `is_string(...) ?:
 * fatalError('[Hacking attempt] ...')` hard rejection stays at the call
 * site rather than being replicated in this DTO -- that call goes through
 * `PresentationAccessor::htmlService()` directly (an L3Presentation
 * side-effecting call), which a Request DTO should stay pure of, same
 * precedent as UserActivityRequest::filterValue().
 */
final readonly class SearchQueryRequest
{
    private function __construct(
        public string $q,
        public bool $hasCatId,
        public ?CategoryId $catId,
        public bool $hasTagId,
        public mixed $tagId,
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
        $q_raw = $source['q'] ?? null;
        $q = is_string($q_raw) ? $q_raw : '';

        $hasCatId = isset($source['cat_id']);
        if ($hasCatId) {
            $inputValidator
                ->validate('cat_id', $source, false, ValidationPattern::ID);
        }

        $hasTagId = isset($source['tag_id']);
        if ($hasTagId) {
            $inputValidator
                ->validate('tag_id', $source, false, '/^\d+(,\d+)*$/');
        }

        return new self($q, $hasCatId, CategoryId::tryFrom($source['cat_id'] ?? null), $hasTagId, $source['tag_id'] ?? null);
    }
}
