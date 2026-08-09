<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned near the end of
 * {@see \Piwigo\Controller\Admin\SiteUpdateSubController::handle()},
 * once every sync stage above has run. `$syncErrors`/`$syncErrorCaptions`
 * are always built together (the same `count($errors) > 0` branch);
 * `$syncInfos` is a separate, independently-gated branch. All 3 are
 * always included (even empty) since `site_update.tpl` reads each with
 * `{if not empty(...)}`, not `isset()`.
 */
final readonly class SiteUpdateSyncErrorsPageContext implements TemplatePageContext
{
    /**
     * @param list<array{ELEMENT: mixed, LABEL: string}> $syncErrors
     * @param list<array{TYPE: mixed, LABEL: mixed}> $syncErrorCaptions
     * @param list<array{ELEMENT: mixed, LABEL: mixed}> $syncInfos
     */
    public function __construct(
        public array $syncErrors,
        public array $syncErrorCaptions,
        public array $syncInfos,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'sync_errors' => $this->syncErrors,
            'sync_error_captions' => $this->syncErrorCaptions,
            'sync_infos' => $this->syncInfos,
        ];
    }
}
