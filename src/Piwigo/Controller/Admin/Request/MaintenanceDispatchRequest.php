<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET` shape for MaintenanceSubController::handle() (page
 * slug "maintenance") -- P26/SEC-40 Request DTO. `action`'s presence
 * gates the (real, load-bearing) CSRF check; `tab` is pattern-validated
 * against the 3 real tabs, defaulting to `actions`.
 */
final readonly class MaintenanceDispatchRequest
{
    private function __construct(
        public bool $requiresCsrfCheck,
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
        $requiresCsrfCheck = isset($source['action']);

        if (isset($source['tab'])) {
            InputValidator::createStatic()
                ->validate('tab', $source, false, '/^(actions|env|sys)$/');
            $tab_raw = $source['tab'];
            $tab = is_string($tab_raw) ? $tab_raw : 'actions';
        } else {
            $tab = 'actions';
        }

        return new self($requiresCsrfCheck, $tab);
    }
}
