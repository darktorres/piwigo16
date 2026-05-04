<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

define('WS_PARAM_ACCEPT_ARRAY', \Piwigo\Ws\WsParam::AcceptArray->value);
define('WS_PARAM_FORCE_ARRAY', \Piwigo\Ws\WsParam::ForceArray->value);
define('WS_PARAM_OPTIONAL', \Piwigo\Ws\WsParam::Optional->value);

define('WS_TYPE_BOOL', \Piwigo\Ws\WsType::Bool->value);
define('WS_TYPE_INT', \Piwigo\Ws\WsType::Int->value);
define('WS_TYPE_FLOAT', \Piwigo\Ws\WsType::Float->value);
define('WS_TYPE_POSITIVE', \Piwigo\Ws\WsType::Positive->value);
define('WS_TYPE_NOTNULL', \Piwigo\Ws\WsType::NotNull->value);
define('WS_TYPE_ID', \Piwigo\Ws\WsType::Id->value);

define('WS_ERR_INVALID_METHOD', 501);
define('WS_ERR_MISSING_PARAM', 1002);
define('WS_ERR_INVALID_PARAM', 1003);

define('WS_XML_ATTRIBUTES', 'attributes_xml_');
