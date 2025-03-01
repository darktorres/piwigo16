<?php
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * WEB SERVICE CORE CLASSES************************************************
 * PwgServer - main object - the link between web service methods, request
 *  handler and response encoder
 * PwgRequestHandler - base class for handlers
 * PwgResponseEncoder - base class for response encoders
 * PwgError, PwgNamedArray, PwgNamedStruct - can be used by web service functions
 * as return values
 */


define( 'WS_PARAM_ACCEPT_ARRAY',  0x010000 );
define( 'WS_PARAM_FORCE_ARRAY',   0x030000 );
define( 'WS_PARAM_OPTIONAL',      0x040000 );

define( 'WS_TYPE_BOOL',           0x01 );
define( 'WS_TYPE_INT',            0x02 );
define( 'WS_TYPE_FLOAT',          0x04 );
define( 'WS_TYPE_POSITIVE',       0x10 );
define( 'WS_TYPE_NOTNULL',        0x20 );
define( 'WS_TYPE_ID', WS_TYPE_INT | WS_TYPE_POSITIVE | WS_TYPE_NOTNULL);

define( 'WS_ERR_INVALID_METHOD',  501 );
define( 'WS_ERR_MISSING_PARAM',   1002 );
define( 'WS_ERR_INVALID_PARAM',   1003 );

define( 'WS_XML_ATTRIBUTES', 'attributes_xml_');

?>
