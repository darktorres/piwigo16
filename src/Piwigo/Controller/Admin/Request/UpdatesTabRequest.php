<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET['tab']` for UpdatesSubController::handle() (page slug
 * "updates"). The pattern validation (`/^(pwg|ext)$/`) constrains the tab
 * value before it can be used to select behavior downstream.
 */
final readonly class UpdatesTabRequest
{
    private function __construct(
        public string $tab,
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
            ->validate('tab', $source, false, '/^(pwg|ext)$/');

        $tab = $source['tab'] ?? null;

        return new self(is_string($tab) ? $tab : 'pwg');
    }
}
