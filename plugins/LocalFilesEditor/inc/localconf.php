<?php

use Piwigo\inc\functions;

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

$edited_file = PHPWG_ROOT_PATH.PWG_LOCAL_DIR . "config/config.php";

if (file_exists($edited_file))
{
  $content_file = file_get_contents($edited_file);
}
else
{
  $content_file = "<?php\n\n/* ".functions::l10n('locfiledit_newfile')." */\n\n\n\n\n?>";
}

$template->assign('show_default', array(
  array(
    'URL' => LOCALEDIT_PATH.'show_default.php?file=inc/config_default.php',
    'FILE' => 'config_default.php'
    )
  )
);

$codemirror_mode = 'application/x-httpd-php';

?>