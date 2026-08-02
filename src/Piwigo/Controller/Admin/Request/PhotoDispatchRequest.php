<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET['image_id']`/`$_GET['tab']` for
 * PhotoSubController::handle() (page slug "photo") -- P27/SEC-40 Request
 * DTO. `tab` is checked against a fixed allowlist (not an InputValidator
 * pattern), matching this controller's own established
 * defense-in-depth precedent (its own docblock: admin.php's shared tab
 * pattern already blocks real path traversal before dispatch reaches
 * here).
 *
 * `$_GET['cat_id']` was also validated here in the original code but
 * never actually read anywhere in this file or any of its 3 tab
 * renderers (confirmed via grep across all 4 files) -- dropped as dead
 * validation rather than carried into this DTO.
 */
final readonly class PhotoDispatchRequest
{
    private function __construct(
        public string $imageId,
        public string $tab,
    ) {}

    /**
     * @param list<string> $knownTabs
     */
    public static function fromGlobals(array $knownTabs): self
    {
        return self::fromArray($_GET, $knownTabs);
    }

    /**
     * @param array<int|string, mixed> $source
     * @param list<string> $knownTabs
     */
    public static function fromArray(array $source, array $knownTabs): self
    {
        InputValidator::createStatic()
            ->validate('image_id', $source, false, ValidationPattern::ID);

        $get_image_id_raw = $source['image_id'] ?? null;
        $get_image_id = is_string($get_image_id_raw) ? $get_image_id_raw : '';

        $tab_param = $source['tab'] ?? null;
        $tab = is_string($tab_param) && in_array($tab_param, $knownTabs, true) ? $tab_param : 'properties';

        return new self($get_image_id, $tab);
    }
}
