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
        $input = AddParams::fromArray($params);
        if ($input->pwgToken !== null && $this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($input->position !== null) {
            Config::override('newcat_default_position', $input->position);
        }
        $options = [];
        if ($input->status !== null) {
            $options['status'] = $input->status->value;
        }
        $allowHtml = Config::allowHtmlDescriptions() && $input->pwgToken !== null;
        if ($input->comment !== null) {
            $options['comment'] = $allowHtml ? $input->comment : strip_tags($input->comment);
        }
        $catName        = $allowHtml ? $input->name : strip_tags($input->name);
        $creationOutput = $this->categoryAdminService->createVirtualCategory($catName, $input->parent, $options);
        if (isset($creationOutput['error'])) {
            return new PwgError(500, is_string($creationOutput['error']) ? $creationOutput['error'] : '');
        }
        $this->userAdminService->invalidateUserCache();
        return $creationOutput;
    }
}
