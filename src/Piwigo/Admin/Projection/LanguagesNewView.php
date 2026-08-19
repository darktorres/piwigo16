<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `languages_new.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\LanguagesNewPageRenderer::render()}. `$languages` is
 * always included (even empty) since the template reads it with
 * `{if !empty($languages)}`, not `isset()`.
 */
#[Template('languages_new.latte')]
final readonly class LanguagesNewView implements View
{
    /**
     * @param list<array<string, mixed>> $languages
     */
    public function __construct(
        public int $isWebmaster,
        public array $languages,
    ) {}
}
