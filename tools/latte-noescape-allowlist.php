<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

/**
 * P59's zero-tolerance `|noescape` gate allowlist -- see
 * `tools/latte-noescape-gate.php`.
 *
 * Three confirmed real exceptions, all the SAME underlying class of
 * Latte gotcha: a bare print sitting inside a tag's own
 * ATTRIBUTE-LIST area (`<img ... {$var} ...>`, not inside any quoted
 * attribute VALUE) is its own special escaping context -- Latte
 * numeric-entity-encodes it (`width="1"` -> `width&#61;&quot;1&quot;`)
 * regardless of the value's PHP-level type, an `Html`-typed value
 * included. `DerivativeImage::getSizeHtm()` (`width="…" height="…"`,
 * meant to be spliced in as real attribute syntax) hits this at both
 * its real print sites, and `CroppedDerivativeLink::$htmSize`
 * (`picture_coi.latte`'s own `HTM_SIZE`) hits it at its one. Confirmed
 * via a standalone `Latte::renderToString()` probe, and via the
 * golden-html suite itself: `slideshow.html`/`admin-picture-coi.html`'s
 * own diffs showed exactly this corruption after an earlier pass
 * dropped `|noescape` here on the mistaken belief `Html`-typing alone
 * was sufficient (the same mistake `mail-wrapper.latte`'s own
 * CSS-context exception made, and the inverse mistake
 * `cat_modify.latte`/`picture_formats.latte`'s own `style=""`
 * `url(...)` sites almost repeated -- see those templates' own history
 * for the case where dropping noescape WAS safe). Each site carries its
 * own comment explaining why -- grep this repo for "attribute-list
 * area" to find them.
 *
 * `mail-wrapper.latte`'s own `<style>` block (Latte's CSS-context
 * auto-escaper backslash-escapes even an already-`Html`-typed value
 * printed inside a literal `<style>` element, which the mail
 * pipeline's emogrifier-based CSS inliner can't parse once escaped)
 * was a THIRD case of this same family, but is fixed for real here,
 * not allowlisted: `MailService::mail()` builds the whole
 * `<style type="text/css">...</style>` element as one pre-formed
 * `Html` fragment (see `MailStyleBlockPageContext`) and the template
 * prints it bare in plain HTML-body context -- no literal `<style>`
 * tag left in the .latte source, so the CSS-context escaping path
 * never triggers. That fix pattern (hide the special-context markup
 * inside a value printed from a different, unproblematic position)
 * doesn't apply to `getSizeHtm()`'s own attribute-list case -- there's
 * no way to print "more attributes on this same tag" from outside the
 * tag's own opening bracket, so `|noescape` is the real, permanent fix
 * there, not a workaround.
 *
 * If a future site genuinely needs an exception, add it here with the
 * same rigor: a real reason, verified via a real render (a golden-html
 * diff or an equivalent standalone probe), not a shortcut around this
 * gate.
 *
 * @return array<string, int> relative path (from themes/) => expected |noescape count
 */
return [
    'default/template/picture_content.latte' => 1,
    'admin/default/template/batch_manager_global.latte' => 1,
    'admin/default/template/picture_coi.latte' => 1,
];
