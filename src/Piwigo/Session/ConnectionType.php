<?php

declare(strict_types=1);

namespace Piwigo\Session;

/** How the current request is authenticated — stored in `Session::$connectedWith`. */
enum ConnectionType: string
{
    case PwgUi               = 'pwg_ui';
    case AuthKey             = 'auth_key';
    case ApiKey              = 'api_key';
    case WsSessionLoginApiKey = 'ws_session_login_api_key';
}
