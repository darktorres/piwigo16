<?php

declare(strict_types=1);

namespace Piwigo\Mail\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * `mail-wrapper.latte`'s own `<style>` element, assigned by {@see
 * \Piwigo\Mail\MailService::mail()}. Built as one pre-formed `Html`
 * fragment -- the literal `<style type="text/css">` tags plus both
 * `global-mail-css.latte`/`mail-css-{theme}.latte` renders concatenated
 * -- rather than the two separate `GLOBAL_MAIL_CSS`/`MAIL_CSS` vars a
 * literal `<style>` block in the template used to print bare.
 *
 * That mattered because Latte's CSS-context auto-escaper backslash-
 * escapes ANY print inside a real `<style>` element regardless of its
 * PHP-level type (an `Html` value included), which corrupts the
 * stylesheet and breaks the mail's emogrifier-based CSS inliner --
 * confirmed via a golden-html diff showing every inline `style=""`
 * attribute in the rendered mail vanish. `|noescape` was the only way
 * to keep the old two-var shape working. Hiding the `<style>` tag
 * inside this pre-built fragment and printing it bare, in plain
 * HTML-body context (no literal `<style>` tag left in the .latte
 * source for Latte's parser to recognize), avoids that escaping path
 * entirely -- confirmed via a standalone `Latte::renderToString()`
 * probe -- so the template needs no `|noescape` at all.
 */
final readonly class MailStyleBlockPageContext implements TemplatePageContext
{
    public function __construct(
        public Html $mailStyleBlock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'MAIL_STYLE_BLOCK' => $this->mailStyleBlock,
        ];
    }
}
