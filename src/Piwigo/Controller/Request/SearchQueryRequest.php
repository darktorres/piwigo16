<?php

declare(strict_types=1);

namespace Piwigo\Controller\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET` shape for SearchController::__invoke() (replaces
 * search.php) -- P27/SEC-40 Request DTO. `cat_id`/`tag_id` are
 * pattern-validated (`InputValidator::validate()`) here, but the
 * original's own stricter `is_string(...) ?: fatalError('[Hacking
 * attempt] ...')` hard rejection stays at the call site rather than being
 * replicated in this DTO -- that call goes through
 * `PresentationAccessor::htmlService()` directly (an L3Presentation
 * side-effecting call), which a Request DTO should stay pure of, same
 * precedent as UserActivityRequest::filterValue().
 */
final readonly class SearchQueryRequest
{
    private function __construct(
        public string $q,
        public bool $hasCatId,
        public mixed $catId,
        public bool $hasTagId,
        public mixed $tagId,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        $q_raw = $source['q'] ?? null;
        $q = is_string($q_raw) ? $q_raw : '';

        $hasCatId = isset($source['cat_id']);
        if ($hasCatId) {
            InputValidator::createStatic()
                ->validate('cat_id', $source, false, ValidationPattern::ID);
        }

        $hasTagId = isset($source['tag_id']);
        if ($hasTagId) {
            InputValidator::createStatic()
                ->validate('tag_id', $source, false, '/^\d+(,\d+)*$/');
        }

        return new self($q, $hasCatId, $source['cat_id'] ?? null, $hasTagId, $source['tag_id'] ?? null);
    }
}
