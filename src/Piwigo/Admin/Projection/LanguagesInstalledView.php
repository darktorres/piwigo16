<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `languages_installed.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\LanguagesInstalledPageRenderer::render()}. No
 * `$languageStates` field -- the two states it iterates
 * (`active`/`inactive`) never vary per request, so the template now
 * declares that literal array itself instead of carrying it through
 * this class.
 */
#[Template('languages_installed.latte')]
final readonly class LanguagesInstalledView implements View
{
    /**
     * @param list<array<string, mixed>> $languages
     */
    public function __construct(
        public array $languages,
        public int $isWebmaster,
        public bool $enableExtensionsInstall,
    ) {}
}
