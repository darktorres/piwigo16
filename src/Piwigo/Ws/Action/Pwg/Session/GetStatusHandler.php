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

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>
     */
    public function __invoke(array $params, PwgServer $server): array
    {
        $currentUser = CurrentUser::get();
        $res = [];
        $res['username']         = $this->permissionService->isAGuest() ? 'guest' : stripslashes($currentUser->username);
        $res['status']           = $currentUser->status;
        $res['theme']            = $currentUser->theme;
        $res['language']         = $currentUser->language;
        $res['pwg_token']        = $this->csrfService->getToken();
        $res['charset']          = StringUtil::getPwgCharset();
        $res['current_datetime'] = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $res['version']          = AppInfo::VERSION;
        $res['save_visits']      = $this->activityLogger->isLoggingEnabled();
        $res['connected_with']   = $this->session->connectedWith;
        /** @var mixed $httpUserAgentRaw */
        $httpUserAgentRaw = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $httpUserAgent    = is_string($httpUserAgentRaw) ? $httpUserAgentRaw : '';
        if ($httpUserAgent !== '' && preg_match('/^PiwigoRemoteSync/', $httpUserAgent)) {
            unset($res['save_visits'], $res['connected_with']);
        }
        if ($httpUserAgent === '' || !str_starts_with($httpUserAgent, 'Apache-HttpClient/')) {
            $res['available_sizes'] = array_keys(ImageStdParams::getDefinedTypeMap());
        }
        if ($this->permissionService->isAdmin()) {
            $res['upload_file_types']      = implode(',', array_unique(array_map(strtolower(...), Config::uploadFormAllTypes() ? Config::fileExtensions() : Config::pictureExtensions())));
            $res['upload_form_chunk_size'] = Config::uploadFormChunkSize();
        }
        return $res;
    }
}
