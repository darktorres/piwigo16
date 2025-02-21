<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\PwgServer;
use Piwigo\inc\ws_protocols\PwgJsonEncoder;
use Piwigo\inc\ws_protocols\PwgRestEncoder;
use Piwigo\inc\ws_protocols\PwgRestRequestHandler;
use Piwigo\inc\ws_protocols\PwgSerialPhpEncoder;
use Piwigo\inc\ws_protocols\PwgXmlRpcEncoder;

defined('PHPWG_ROOT_PATH') or trigger_error('Hacking attempt!', E_USER_ERROR);

include_once(PHPWG_ROOT_PATH.'inc/ws_core.php');

add_event_handler('ws_add_methods', ws_addDefaultMethods(...));
add_event_handler('ws_invoke_allowed', ws_isInvokeAllowed(...), EVENT_HANDLER_PRIORITY_NEUTRAL, 3);

$requestFormat = 'rest';
$responseFormat = null;

if ( isset($_GET['format']) )
{
  $responseFormat = $_GET['format'];
}

if ( !isset($responseFormat) and isset($requestFormat) )
{
  $responseFormat = $requestFormat;
}

$service = new PwgServer();

if (!is_null($requestFormat))
{
  $handler = null;
  switch ($requestFormat)
  {
    case 'rest':
      $handler = new PwgRestRequestHandler();
      break;
  }
  $service->setHandler($requestFormat, $handler);
}

if (!is_null($responseFormat))
{
  $encoder = null;
  switch ($responseFormat)
  {
    case 'rest':
      $encoder = new PwgRestEncoder();
      break;
    case 'php':
      $encoder = new PwgSerialPhpEncoder();
      break;
    case 'json':
      $encoder = new PwgJsonEncoder();
      break;
    case 'xmlrpc':
      $encoder = new PwgXmlRpcEncoder();
      break;
  }
  $service->setEncoder($responseFormat, $encoder);
}

set_make_full_url();