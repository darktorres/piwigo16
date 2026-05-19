<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Extensions;

use Piwigo\Admin\Plugins;
use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Csrf\CsrfService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.extensions.plugins.performAction` — install/activate/deactivate/uninstall/delete. */
final readonly class PluginsPerformActionHandler implements WsAction
{
    public function __construct(
        private CsrfService $csrfService,
        private PermissionService $permissionService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|true
    {
        $template = TemplateRegistry::current();
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!$this->permissionService->isWebmaster()) {
            return new PwgError(403, Lang::t('Webmaster status is required.'));
        }
        if (!Config::enableExtensionsInstall() && $params['action'] === 'delete') {
            return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
        }
        $plugins      = Kernel::service(Plugins::class);
        $pluginAction = is_string($params['action']) ? $params['action'] : '';
        $pluginId     = is_string($params['plugin']) ? $params['plugin'] : '';
        $errors       = $plugins->performAction($pluginAction, $pluginId);
        if (!empty($errors)) {
            return new PwgError(500, implode(', ', array_map(fn (mixed $e): string => is_scalar($e) ? (string) $e : '', is_array($errors) ? $errors : [])));
        }
        if (in_array($pluginAction, ['activate', 'deactivate'])) {
            $template->deleteCompiledTemplates();
        }
        return true;
    }
}
