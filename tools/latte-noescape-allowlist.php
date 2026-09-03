<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

/**
 * P59's zero-tolerance `|noescape` gate allowlist -- see
 * `tools/latte-noescape-gate.php`. Empty: the corpus is at true zero.
 *
 * `mail-wrapper.latte`'s own `<style>` block was the last real
 * exception -- Latte's CSS-context auto-escaper backslash-escapes even
 * an already-`Html`-typed value printed inside a literal `<style>`
 * element, which the mail pipeline's emogrifier-based CSS inliner
 * (`MailService.php`'s only real `pelago/emogrifier` call site) can't
 * parse once escaped (confirmed via a golden-html diff showing every
 * inline `style=""` attribute in the rendered mail vanish). Fixed for
 * real, not allowlisted: `MailService::mail()` now builds the whole
 * `<style type="text/css">...</style>` element as one pre-formed
 * `Html` fragment (see `MailStyleBlockPageContext`) and the template
 * prints it bare -- no literal `<style>` tag left in the .latte source
 * for Latte's parser to recognize, so the CSS-context escaping path
 * never triggers (confirmed via a standalone `Latte::renderToString()`
 * probe). A plain `style=""` ATTRIBUTE's own `url(...)` value stays a
 * different, browser-safe case once backslash-escaped (CSS Syntax
 * Module Level 3's own escaped-code-point rule) -- see
 * `plugins_new.latte`'s own git history (`fix(templates): remove
 * |noescape from PEM-catalog screenshot URLs...`) and
 * `cat_modify.latte`/`picture_formats.latte`, which reuse that pattern
 * and needed no change here.
 *
 * If a future site genuinely needs an exception, add it here with the
 * same rigor: a real reason, verified via a real render (a golden-html
 * diff or an equivalent standalone probe), not a shortcut around this
 * gate.
 *
 * @return array<string, int> relative path (from themes/) => expected |noescape count
 */
return [];
