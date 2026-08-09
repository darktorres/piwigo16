<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET` shape for UserActivityPageRenderer::render() (page
 * slug "user_activity"). `photo`/`album`/`group`
 * are pattern-validated (`ValidationPattern::ID`, digits-only) up front,
 * same as the original; the additional-filters loop's own `is_string()` +
 * fatalError() check on whichever key is actually present is preserved at
 * the call site rather than folded in here, since it uses
 * `PresentationAccessor::htmlService()->fatalError()` directly (an
 * `L3Presentation` call, not something a DTO should replicate) --
 * `filterValue()` exposes the already-pattern-checked raw value so that
 * downstream logic is unchanged.
 */
final readonly class UserActivityRequest
{
    /**
     * @param array<int|string, mixed> $source
     */
    private function __construct(
        public bool $isDownloadLogs,
        private array $source,
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
            ->validate('photo', $source, false, ValidationPattern::ID);
        $inputValidator
            ->validate('album', $source, false, ValidationPattern::ID);
        $inputValidator
            ->validate('group', $source, false, ValidationPattern::ID);

        return new self(($source['type'] ?? null) === 'download_logs', $source);
    }

    public function hasFilter(string $key): bool
    {
        return isset($this->source[$key]);
    }

    public function filterValue(string $key): mixed
    {
        return $this->source[$key] ?? null;
    }
}
