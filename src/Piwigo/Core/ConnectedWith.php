<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Core;

/**
 * The `$_SESSION['connected_with']` marker -- records which login path
 * authenticated the current session. Read/written through
 * `ConnectedWithSession`, never directly, across `Auth\{AuthService,
 * ApiKeyService}`, `Ws\Server`, `Ws\Session\{LoginHandler,
 * GetStatusHandler}`, `Bootstrap\UserBootstrap`, `Controller\
 * {IdentificationController,ProfileFormHandler}`, `Admin\Install\
 * InstallWizard` and `Activity\ActivityService`. `Server::
 * isAuthorizedMethodForAPIKEY()` keys off `WsSessionLoginApiKey`
 * specifically to keep `apiKeyForbiddenMethods` enforced for the rest of
 * an api_key-authenticated session -- see `UserBootstrap`'s own docblock
 * comment at its uploadAsync branch (security finding 2) for why that
 * comparison must never be silently overwritten.
 */
enum ConnectedWith: string
{
    case PwgUi = 'pwg_ui';

    case WsSessionLogin = 'ws_session_login';

    case WsSessionLoginApiKey = 'ws_session_login_api_key';

    case UploadAsync = 'pwg.images.uploadAsync';

    case AuthKey = 'auth_key';

    case ApiKey = 'api_key';
}
