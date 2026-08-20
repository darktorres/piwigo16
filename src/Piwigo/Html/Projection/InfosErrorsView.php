<?php

declare(strict_types=1);

namespace Piwigo\Html\Projection;

/**
 * `{templateType}` target for `infos_errors.latte`. Never rendered via
 * `Renderer::render()` -- reached only through 12 real bare
 * `{include 'infos_errors.latte'}` call sites, relying on full
 * parent-scope inheritance from the genuinely cross-cutting ambient
 * `errors`/`infos` vars {@see PageMessagesContext} assigns (not tied
 * to any one page's own View, unlike {@see
 * \Piwigo\Controller\Projection\PictureNavButtonsView}). Contract-only
 * conversion, same shape as {@see \Piwigo\Menu\Projection\MenubarBlockView}.
 */
final readonly class InfosErrorsView
{
    /**
     * @param array<array-key, string>|null $errors
     * @param array<array-key, string>|null $infos
     */
    public function __construct(
        public ?array $errors,
        public ?array $infos,
    ) {}
}
