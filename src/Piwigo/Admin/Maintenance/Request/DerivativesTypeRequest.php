<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET['type']` for MaintenanceActionDispatcher's `derivatives`
 * action -- P27/SEC-40 Request DTO. `all` or a `_`-joined list of
 * `Piwigo\Image\ImageStdParams` size-type constants (all lowercase
 * alphanumeric, see that class's own constants); an absent/empty value
 * preserves the dispatcher's own pre-existing behavior of clearing nothing
 * (`InputValidator::validate()`'s `mandatory: false` lets it through
 * unvalidated). A non-empty value is pattern-checked -- not because a
 * known-invalid type crashes anything downstream (DerivativeCacheService
 * treats an unrecognized type as a literal custom-derivative suffix), but
 * because that suffix ends up embedded in a regex pattern used to match
 * filenames during a real filesystem-deleting directory walk
 * (clearDerivativeCacheRecursive()) -- defense-in-depth against regex
 * metacharacters/path separators reaching that pattern, matching this
 * project's existing precedent of validating admin-only inputs even when
 * already access-gated (e.g. AlbumSubController's own tab allowlist note).
 */
final readonly class DerivativesTypeRequest
{
    private function __construct(
        public string $typesStr,
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
        $types_str = $source['type'] ?? '';
        $types_str = is_string($types_str) ? $types_str : '';

        InputValidator::createStatic()
            ->validate('type', [
                'type' => $types_str,
            ], false, '/^[a-zA-Z0-9_]+$/');

        return new self($types_str);
    }
}
