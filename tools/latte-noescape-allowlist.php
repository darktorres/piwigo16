<?php

declare(strict_types=1);

/**
 * P59's zero-tolerance `|noescape` gate allowlist -- see
 * `tools/latte-noescape-gate.php`. Every entry here is a real,
 * documented exception verified in its own template (grep the file
 * for "noescape kept on purpose" to find its explanation), not a
 * placeholder for "not yet converted".
 *
 * Both currently-allowed sites are `mail-wrapper.latte`'s own
 * `<style>` block: Latte's CSS-context auto-escaper backslash-escapes
 * even an already-`Html`-typed value there, which the mail pipeline's
 * emogrifier-based CSS inliner (`MailService.php`'s only real
 * `pelago/emogrifier` call site) can't parse once escaped -- confirmed
 * via a golden-html diff showing every inline `style=""` attribute in
 * the rendered mail vanish. This is specific to that downstream
 * consumer: a plain `style=""` ATTRIBUTE's own `url(...)` value is
 * browser-safe once backslash-escaped (CSS Syntax Module Level 3's own
 * escaped-code-point rule), verified separately via a real golden-html
 * regeneration for `plugins_new.latte` -- see that template's own git
 * history (`fix(templates): remove |noescape from PEM-catalog
 * screenshot URLs...`) and `cat_modify.latte`/`picture_formats.latte`,
 * which reuse the same verified-safe pattern.
 *
 * @return array<string, int> relative path (from themes/) => expected |noescape count
 */
return [
    'default/template/mail/text/html/mail-wrapper.latte' => 2,
];
