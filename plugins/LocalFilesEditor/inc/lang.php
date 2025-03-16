<?php

use Piwigo\inc\functions;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

$languages = functions::get_languages();

if (isset($_POST['edit'])) {
    $_POST['language'] = $_POST['language_select'];
}

if (isset($_POST['language'])) {
    $page['language'] = $_POST['language'];
}

if (! isset($page['language']) or ! in_array($page['language'], array_keys($languages))) {
    $page['language'] = functions_user::get_default_language();
}

$template->assign('language', $page['language']);

$edited_file = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'language/' . $page['language'] . '.lang.php';

if (file_exists($edited_file)) {
    $content_file = file_get_contents($edited_file);
} else {
    $content_file = "<?php\n\n/* " . functions::l10n('locfiledit_newfile') . " */\n\n\n\n\n?>";
}

$selected = 0;
foreach (functions::get_languages() as $language_code => $language_name) {
    $file = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'language/' . $language_code . '.lang.php';

    $options[$language_code] = (file_exists($file) ? '&#x2714;' : '&#x2718;') . ' ' . $language_name;

    if ($page['language'] == $language_code) {
        $selected = $language_code;
        $template->assign(
            'show_default',
            [
                [
                    'URL' => LOCALEDIT_PATH . 'show_default.php?file=language/' . $language_code . '/common.lang.php',
                    'FILE' => 'common.lang.php',
                ],
                [
                    'URL' => LOCALEDIT_PATH . 'show_default.php?file=language/' . $language_code . '/admin.lang.php',
                    'FILE' => 'admin.lang.php',
                ],
            ]
        );
    }
}

$template->assign(
    'css_lang_tpl',
    [
        'SELECT_NAME' => 'language_select',
        'OPTIONS' => $options,
        'SELECTED' => $selected,
    ]
);

$codemirror_mode = 'application/x-httpd-php';
