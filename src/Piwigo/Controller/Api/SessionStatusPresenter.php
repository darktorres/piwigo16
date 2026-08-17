<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\Env;
use Piwigo\Csrf\CsrfService;
use Piwigo\History\HistoryService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Users\CurrentUser;

/**
 * Shared `pwg.session.getStatus`-equivalent body builder -- `GET
 * /api/v1/session` (`SessionController`) and `POST`/`DELETE
 * /api/v1/session` (login/logout) all return the same "session status"
 * shape, since a client mutating the session naturally wants the
 * resulting state back, not just a bare acknowledgment.
 */
final readonly class SessionStatusPresenter
{
    public function __construct(
        private CurrentUser $currentUser,
        private AccessControl $accessControl,
        private CurrentConfig $currentConfig,
        private HistoryService $historyService,
        private ImageStdParams $imageStdParams,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $currentUser = $this->currentUser->get();

        $body = [
            'username' => $this->accessControl->isAGuest() ? 'guest' : ($currentUser->username->value ?? ''),
            'status' => $currentUser->status->value,
            'theme' => $currentUser->theme->value,
            'language' => $currentUser->language->value,
            'pwgToken' => new CsrfService($this->currentConfig)
                ->getToken(),
            'charset' => 'utf-8',
            // Env::now() (not SQL's NOW()) so this is freezable by
            // PIWIGO_TEST_NOW in tests, same reasoning as
            // GetStatusHandler's own identical line.
            'currentDatetime' => Env::now()->format('Y-m-d H:i:s'),
            'version' => AppInfo::VERSION,
            'saveVisits' => $this->historyService->isLoggingAllowed(),
            'connectedWith' => new ConnectedWithSession()
                ->get()?->value,
            'availableSizes' => array_keys($this->imageStdParams->getDefinedTypeMap()),
        ];

        if ($this->accessControl->isAdmin()) {
            $uploadExtensions = $this->currentConfig->uploadFormAllTypes
                ? $this->currentConfig->fileExtensions
                : $this->currentConfig->pictureExtensions;

            $body['uploadFileTypes'] = array_values(array_unique(array_map(strtolower(...), $uploadExtensions)));
            $body['uploadFormChunkSize'] = $this->currentConfig->uploadFormChunkSize;
        }

        return $body;
    }
}
