<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

/**
 * Validated `$_POST`/`$_GET` shape for SiteManagerSubController::handle()
 * (page slug "site_manager") -- P27/SEC-40 Request DTO. `action`'s own
 * `switch` has a single real case (`delete`) with no `default:` branch,
 * so any unrecognized value is already a safe no-op -- no pattern
 * validation needed.
 */
final readonly class SiteManagerRequest
{
    private function __construct(
        public bool $requiresCsrfCheck,
        public ?string $newSiteGalleriesUrl,
        public ?int $siteId,
        public ?string $action,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArrays($_POST, $_GET);
    }

    /**
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $get
     */
    public static function fromArrays(array $post, array $get): self
    {
        $requiresCsrfCheck = $post !== [] || isset($get['action']);

        $galleries_url = $post['galleries_url'] ?? null;
        $newSiteGalleriesUrl = (isset($post['submit'])
            and ! in_array($galleries_url, [null, false, 0, '0', '', []], true)
            and is_string($galleries_url))
            ? $galleries_url
            : null;

        $site_raw = $get['site'] ?? null;
        $siteId = is_numeric($site_raw) ? (int) $site_raw : null;

        $action_raw = $get['action'] ?? null;
        $action = is_string($action_raw) ? $action_raw : null;

        return new self($requiresCsrfCheck, $newSiteGalleriesUrl, $siteId, $action);
    }
}
