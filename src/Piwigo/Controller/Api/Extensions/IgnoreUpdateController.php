<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Extensions;

use Override;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Auth\AccessControl;
use Piwigo\Cache\ExtensionUpdateCachePool;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/extensions/updates/ignore` --
 * `pwg.extensions.ignoreUpdate`'s real replacement, webmaster + CSRF.
 */
final readonly class IgnoreUpdateController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private AccessControl $accessControl,
        private ExtensionUpdateChecker $extensionUpdateChecker,
        private ExtensionUpdateCachePool $extensionUpdateCachePool,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        if (! $this->accessControl->isWebmaster()) {
            return ResponseFactory::problem('Forbidden', 403, 'Access denied.');
        }

        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        $input = IgnoreUpdateInput::fromArray(JsonBody::decode($request));
        $type = $input->type !== null ? ExtensionType::fromPluralWsParam($input->type) : null;

        if ($input->reset) {
            if ($type instanceof ExtensionType) {
                $this->extensionUpdateChecker->resetIgnoredForType($type);
            } else {
                $this->extensionUpdateChecker->resetAllIgnored();
            }

            $this->extensionUpdateCachePool->deleteItem('extensions_need_update');

            return ResponseFactory::noContent();
        }

        if (in_array($input->id, [null, ''], true) || ! $type instanceof ExtensionType) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid parameters.');
        }

        $this->extensionUpdateChecker->ignoreUpdate($type, $input->id);
        $this->extensionUpdateCachePool->deleteItem('extensions_need_update');

        return ResponseFactory::noContent();
    }
}
