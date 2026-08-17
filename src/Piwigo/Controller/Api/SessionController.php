<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\Env;
use Piwigo\Csrf\CsrfService;
use Piwigo\History\HistoryService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageStdParams;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/session` -- `pwg.session.getStatus`'s real replacement.
 * Deliberately a fresh implementation, not a shared extraction with
 * `Ws\Session\GetStatusHandler`: that handler's own User-Agent-sniffed
 * branches (dropping `save_visits`/`connected_with`/`available_sizes` for
 * the "Piwigo Remote Sync" client) are exactly the kind of wire-format
 * compat cruft P27 has no obligation to carry (Locked Decision D5), but
 * WS's own wire contract stays untouched until it's actually deleted
 * (Numbering section) -- so the WS handler keeps its UA branches, and this
 * duplicates the small, clean remainder rather than forcing one shared
 * implementation to carry both. Auth is not gated: works the same for a
 * guest or a signed-in user, exactly like its WS predecessor.
 */
final readonly class SessionController implements ControllerInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private AccessControl $accessControl,
        private CurrentConfig $currentConfig,
        private HistoryService $historyService,
        private ImageStdParams $imageStdParams,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
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

        return ResponseFactory::json($body);
    }
}
