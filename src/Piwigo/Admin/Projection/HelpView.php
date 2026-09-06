<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Latte\Runtime\Html;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `help.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\HelpPageRenderer::render()}.
 *
 * `$helpContent` is Html, not string (P59): a local `help/help_*.html`
 * file shipped with the app, loaded under a filename built from
 * `$tabsheet->selected` -- always one of the tabsheet's own registered
 * (allowlisted) tab names per `Tabsheet::select()`'s own fallback
 * behavior, never raw user input (see `HelpSectionRequest`'s own
 * docblock). `$helpSectionTitle` is Html for the same reason {@see
 * \Piwigo\Admin\Projection\TabSheetEntry::$caption} is -- it's read
 * straight off that same field.
 *
 * No `HasPageAssets`: `css/pages/help.css`'s own only rule
 * (`#helpSynchro { display: none; }`) targeted an id nothing has ever
 * rendered (confirmed dead during P52-C's follow-up ID-detox audit),
 * so the file -- and the `$enableSynchronization`-gated registration
 * that loaded it -- were removed outright rather than converted.
 */
#[Template('help.latte')]
final readonly class HelpView implements View
{
    public function __construct(
        public Html $helpContent,
        public Html $helpSectionTitle,
    ) {}
}
