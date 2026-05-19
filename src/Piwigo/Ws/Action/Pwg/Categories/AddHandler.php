<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Config\Config;
use Piwigo\Csrf\CsrfService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.categories.add` — create a virtual album. pwg_token required for HTML in name/comment. */
final readonly class AddHandler implements WsAction
{
    public function __construct(
        private CategoryAdminService $categoryAdminService,
        private CsrfService $csrfService,
        private UserAdminService $userAdminService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        if (isset($params['pwg_token']) && $this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!empty($params['position']) && in_array($params['position'], ['first', 'last'])) {
            Config::override('newcat_default_position', is_string($params['position']) ? $params['position'] : '');
        }
        $options = [];
        if (!empty($params['status']) && in_array($params['status'], ['private', 'public'])) {
            $options['status'] = $params['status'];
        }
        if (!empty($params['comment'])) {
            $commentStr         = is_string($params['comment']) ? $params['comment'] : '';
            $options['comment'] = (!Config::allowHtmlDescriptions() || !isset($params['pwg_token'])) ? strip_tags($commentStr) : $commentStr;
        }
        $catNameRaw     = $params['name'] ?? null;
        $catNameStr     = is_string($catNameRaw) ? $catNameRaw : '';
        $catName        = (!Config::allowHtmlDescriptions() || !isset($params['pwg_token'])) ? strip_tags($catNameStr) : $catNameStr;
        $catParent      = is_numeric($params['parent']) ? (int) $params['parent'] : (is_string($params['parent']) ? $params['parent'] : null);
        $creationOutput = $this->categoryAdminService->createVirtualCategory($catName, $catParent, $options);
        if (isset($creationOutput['error'])) {
            return new PwgError(500, is_string($creationOutput['error']) ? $creationOutput['error'] : '');
        }
        $this->userAdminService->invalidateUserCache();
        return $creationOutput;
    }
}
