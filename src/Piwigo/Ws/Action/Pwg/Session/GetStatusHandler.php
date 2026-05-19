<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Session;

use Piwigo\Activity\ActivityLogger;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\StringUtil;
use Piwigo\Csrf\CsrfService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Session\Session;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/**
 * `pwg.session.getStatus` — current-user / current-request status
 * snapshot. Provides the CSRF token clients need for admin methods.
 *
 * Trims `save_visits` / `connected_with` for the PiwigoRemoteSync
 * client, and trims `available_sizes` for Apache-HttpClient clients
 * to keep their response shapes stable.
 */
final readonly class GetStatusHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private PermissionService $permissionService,
        private Session $session,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): GetStatusResult
    {
        $currentUser = CurrentUser::get();
        /** @var mixed $httpUserAgentRaw */
        $httpUserAgentRaw = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $httpUserAgent    = is_string($httpUserAgentRaw) ? $httpUserAgentRaw : '';

        $hidePiwigoRemoteFields = $httpUserAgent !== '' && preg_match('/^PiwigoRemoteSync/', $httpUserAgent);
        $hideAvailableSizes     = $httpUserAgent !== '' && str_starts_with($httpUserAgent, 'Apache-HttpClient/');

        return new GetStatusResult(
            username:            $this->permissionService->isAGuest() ? 'guest' : stripslashes($currentUser->username),
            status:              $currentUser->status,
            theme:               $currentUser->theme,
            language:            $currentUser->language,
            pwgToken:            $this->csrfService->getToken(),
            charset:             StringUtil::getPwgCharset(),
            currentDatetime:     new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            version:             AppInfo::VERSION,
            saveVisits:          $hidePiwigoRemoteFields ? null : $this->activityLogger->isLoggingEnabled(),
            connectedWith:       $hidePiwigoRemoteFields ? null : $this->session->connectedWith,
            availableSizes:      $hideAvailableSizes ? null : array_keys(ImageStdParams::getDefinedTypeMap()),
            uploadFileTypes:     $this->permissionService->isAdmin()
                ? implode(',', array_unique(array_map(strtolower(...), Config::uploadFormAllTypes() ? Config::fileExtensions() : Config::pictureExtensions())))
                : null,
            uploadFormChunkSize: $this->permissionService->isAdmin() ? Config::uploadFormChunkSize() : null,
        );
    }
}
