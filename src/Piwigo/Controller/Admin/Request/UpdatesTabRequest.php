<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET['tab']` for UpdatesSubController::handle() (page slug
 * "updates") -- P27/SEC-40 Request DTO. Preserves the existing
 * `/^(pwg|ext)$/` pattern validation -- see this controller's own docblock
 * for the real LFI a prior remediation pass already fixed here (this
 * value used to be spliced unvalidated into `include
 * admin/updates_<tab>.php`).
 */
final readonly class UpdatesTabRequest
{
    private function __construct(
        public string $tab,
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
        InputValidator::createStatic()
            ->validate('tab', $source, false, '/^(pwg|ext)$/');

        $tab = $source['tab'] ?? null;

        return new self(is_string($tab) ? $tab : 'pwg');
    }
}
