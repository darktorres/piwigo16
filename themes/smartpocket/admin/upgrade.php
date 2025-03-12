<?php

use Piwigo\inc\functions;

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

global $prefixeTable, $conf;

if (!isset($conf['smartpocket']))
{
  $config = array(
    'loop' => true,//true - false
    'autohide' => 5000,//5000 - 0
  );

  functions::conf_update_param('smartpocket', $config, true);
}
elseif (count(functions::safe_unserialize($conf['smartpocket'])) != 2)
{
  $conff = functions::safe_unserialize($conf['smartpocket']);
  $config = array(
    'loop' => (!empty($conff['loop'])) ? $conff['loop'] :true,
    'autohide' => (!empty($conff['autohide'])) ? $conff['autohide'] :5000,
  );
  functions::conf_update_param('smartpocket', $config, true);
}
?>