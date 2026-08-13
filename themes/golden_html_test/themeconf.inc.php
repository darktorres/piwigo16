<?php

declare(strict_types=1);

// Not a real theme -- exists only so Template::setTheme()'s standard_pages
// fallback (identification/register/password/profile rendered under a
// non-'default' theme with no template overrides of its own) has a real,
// loadable themeconf.inc.php to read before it swaps to the real
// themes/standard_pages directory. See MailGoldenHtmlSnapshotTest.php's
// sibling GoldenHtmlSnapshotTest.php special cases
// (standard-pages-identification/-register/-password/-profile) for the
// only real caller. Never assigned as a real user's theme outside those
// tests' own temporary, restored H::setUserTheme() calls.
$themeconf = [
    'name' => 'golden_html_test',
];
