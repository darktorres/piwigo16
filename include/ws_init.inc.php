<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Ws\Protocol\PwgJsonEncoder;
use Piwigo\Ws\Protocol\PwgRestEncoder;
use Piwigo\Ws\Protocol\PwgRestRequestHandler;
use Piwigo\Ws\Protocol\PwgSerialPhpEncoder;
use Piwigo\Ws\Protocol\PwgXmlRpcEncoder;
use Piwigo\Ws\PwgServer;

defined('PHPWG_ROOT_PATH') or trigger_error('Hacking attempt!', E_USER_ERROR);

include_once PHPWG_ROOT_PATH . 'include/ws_core.inc.php';

add_event_handler('ws_add_methods', 'ws_addDefaultMethods');
add_event_handler('ws_invoke_allowed', 'ws_isInvokeAllowed');

$requestFormat = 'rest';
$responseFormat = null;

if (isset($_GET['format'])) {
    // cast defensively: PwgServer::setEncoder() requires a string, but
    // $_GET['format'] could be an array for a malformed ?format[]=x request
    $responseFormat = is_scalar($_GET['format']) ? (string) $_GET['format'] : '';
}

if (! isset($responseFormat)) {
    $responseFormat = $requestFormat;
}

$service = new PwgServer();

// $requestFormat is hardcoded to 'rest' above; the format-selection switch
// stays for parity with $responseFormat's structure and in case more request
// formats are ever added.
$handler = null;
switch ($requestFormat) {
    case 'rest':
        $handler = new PwgRestRequestHandler();
        break;
}
$service->setHandler($requestFormat, $handler);

// $responseFormat can never be null here: it's either $_GET['format'] or,
// per the isset() fallback above, $requestFormat ('rest').
$encoder = null;
switch ($responseFormat) {
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

set_make_full_url();
