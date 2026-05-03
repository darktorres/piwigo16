<?php

declare(strict_types=1);

global $persistent_cache;

use Piwigo\Ws\Protocol\PwgJsonEncoder;
use Piwigo\Ws\Protocol\PwgRestEncoder;
use Piwigo\Ws\Protocol\PwgRestRequestHandler;
use Piwigo\Ws\Protocol\PwgSerialPhpEncoder;
use Piwigo\Ws\Protocol\PwgXmlRpcEncoder;
use Piwigo\Ws\PwgServer;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

defined('PHPWG_ROOT_PATH') or trigger_error('Hacking attempt!', E_USER_ERROR);

include_once(PHPWG_ROOT_PATH.'include/ws_core.inc.php');

add_event_handler('ws_add_methods', 'ws_addDefaultMethods');
add_event_handler('ws_invoke_allowed', 'ws_isInvokeAllowed', EVENT_HANDLER_PRIORITY_NEUTRAL);

$requestFormat = 'rest';
$responseFormat = null;

if (isset($_GET['format'])) {
    $responseFormat = is_string($_GET['format']) ? $_GET['format'] : null;
}

if (!isset($responseFormat)) {
    $responseFormat = $requestFormat;
}
$responseFormat = (string) $responseFormat;

$service = new PwgServer();
\Piwigo\Ws\PwgServerRegistry::set($service);

$handler = null;
switch ($requestFormat) {
    case 'rest':
        include_once(PHPWG_ROOT_PATH.'include/ws_protocols/rest_handler.php');
        $handler = new PwgRestRequestHandler();
        break;
}
$service->setHandler($requestFormat, $handler);

$encoder = null;
switch ($responseFormat) {
    case 'rest':
        include_once(PHPWG_ROOT_PATH.'include/ws_protocols/rest_encoder.php');
        $encoder = new PwgRestEncoder();
        break;
    case 'php':
        include_once(PHPWG_ROOT_PATH.'include/ws_protocols/php_encoder.php');
        $encoder = new PwgSerialPhpEncoder();
        break;
    case 'json':
        include_once(PHPWG_ROOT_PATH.'include/ws_protocols/json_encoder.php');
        $encoder = new PwgJsonEncoder();
        break;
    case 'xmlrpc':
        include_once(PHPWG_ROOT_PATH.'include/ws_protocols/xmlrpc_encoder.php');
        $encoder = new PwgXmlRpcEncoder();
        break;
}
$service->setEncoder($responseFormat, $encoder);

set_make_full_url();
