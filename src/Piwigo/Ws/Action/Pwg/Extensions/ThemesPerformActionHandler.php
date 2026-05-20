<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Extensions;

use Piwigo\Admin\Themes;
use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Csrf\CsrfService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsParamException;

/** `pwg.extensions.themes.performAction` — activate/deactivate/delete/set_default. */
final readonly class ThemesPerformActionHandler implements WsAction
{
    public function __construct(
        private CsrfService $csrfService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|true
    {
        $template = TemplateRegistry::current();
        try {
            $input = ThemesPerformActionParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!Config::enableExtensionsInstall() && $input->action === 'delete') {
            return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
        }
        $themes = Kernel::service(Themes::class);
        $errors = $themes->performAction($input->action, $input->theme);
        if (!empty($errors)) {
            return new PwgError(500, implode(', ', array_map(fn (mixed $e): string => is_scalar($e) ? (string) $e : '', $errors)));
        }
        if (in_array($input->action, ['activate', 'deactivate'])) {
            $template->deleteCompiledTemplates();
        }
        return true;
    }
}
