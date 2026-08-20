<?php

declare(strict_types=1);

namespace Piwigo\Page\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `no_photo_yet.latte`'s own typed view, constructed by {@see
 * \Piwigo\Page\NoPhotoYetRenderer::render()} for both its admin and
 * guest branches. `$step` alone (`1` vs. anything else) picks the
 * template's own branch, so `$loginUrl` is only ever real for the
 * guest (`step === 1`) branch and `$intro`/`$nextStepUrl` only for the
 * admin one.
 */
#[Template('no_photo_yet.latte')]
final readonly class NoPhotoYetView implements View
{
    public function __construct(
        public int $step,
        public ?string $loginUrl,
        public ?string $intro,
        public ?string $nextStepUrl,
        public string $deactivateUrl,
    ) {}
}
