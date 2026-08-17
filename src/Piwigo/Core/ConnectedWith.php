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
 * ApiKeyService}`, `Controller\Api\SessionLoginController`, `Bootstrap\
 * {UserBootstrap,UserResolutionMiddleware}`, `Controller\
 * {IdentificationController,ProfileFormHandler}`, `Admin\Install\
 * InstallWizard` and `Activity\ActivityService`.
 */
enum ConnectedWith: string
{
    case PwgUi = 'pwg_ui';

    case ApiSessionLogin = 'api_session_login';

    case ApiSessionLoginApiKey = 'api_session_login_api_key';

    case UploadAsync = 'pwg.images.uploadAsync';

    case AuthKey = 'auth_key';

    case ApiKey = 'api_key';
}
