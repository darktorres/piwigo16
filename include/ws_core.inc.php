<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/* WEB SERVICE CORE CONSTANTS***********************************************
 * The WS_* constants below back the web service core classes, extracted to
 * Piwigo\Ws\ (PwgServer, PwgRequestHandler, PwgResponseEncoder, PwgError,
 * PwgNamedArray, PwgNamedStruct) and Piwigo\Ws\Protocol\ (PwgJsonEncoder,
 * PwgSerialPhpEncoder, PwgXmlWriter, PwgRestEncoder, PwgRestRequestHandler,
 * PwgXmlRpcEncoder). This file stays procedural (PHP constants aren't
 * PSR-4-autoloadable) and is still include_once'd by ws_init.inc.php.
 */

define('WS_PARAM_ACCEPT_ARRAY', 0x010000);
define('WS_PARAM_FORCE_ARRAY', 0x030000);
define('WS_PARAM_OPTIONAL', 0x040000);

define('WS_TYPE_BOOL', 0x01);
define('WS_TYPE_INT', 0x02);
define('WS_TYPE_FLOAT', 0x04);
define('WS_TYPE_POSITIVE', 0x10);
define('WS_TYPE_NOTNULL', 0x20);
define('WS_TYPE_ID', WS_TYPE_INT | WS_TYPE_POSITIVE | WS_TYPE_NOTNULL);

define('WS_ERR_INVALID_METHOD', 501);
define('WS_ERR_MISSING_PARAM', 1002);
define('WS_ERR_INVALID_PARAM', 1003);

define('WS_XML_ATTRIBUTES', 'attributes_xml_');

