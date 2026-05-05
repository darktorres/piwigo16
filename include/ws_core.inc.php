<?php

declare(strict_types=1);

use Piwigo\Ws\WsParam;
use Piwigo\Ws\WsType;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

define('WS_PARAM_ACCEPT_ARRAY', WsParam::AcceptArray->value);
define('WS_PARAM_FORCE_ARRAY', WsParam::ForceArray->value);
define('WS_PARAM_OPTIONAL', WsParam::Optional->value);

define('WS_TYPE_BOOL', WsType::Bool->value);
define('WS_TYPE_INT', WsType::Int->value);
define('WS_TYPE_FLOAT', WsType::Float->value);
define('WS_TYPE_POSITIVE', WsType::Positive->value);
define('WS_TYPE_NOTNULL', WsType::NotNull->value);
define('WS_TYPE_ID', WsType::Id->value);

define('WS_ERR_INVALID_METHOD', 501);
define('WS_ERR_MISSING_PARAM', 1002);
define('WS_ERR_INVALID_PARAM', 1003);

define('WS_XML_ATTRIBUTES', 'attributes_xml_');
