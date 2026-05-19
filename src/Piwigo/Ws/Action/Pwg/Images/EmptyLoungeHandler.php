<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.images.emptyLounge` — drain the upload lounge into albums. */
final readonly class EmptyLoungeHandler implements WsAction
{
    public function __construct(
        private CategoryAdminService $categoryAdminService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>
     */
    public function __invoke(array $params, PwgServer $server): array
    {
        return ['rows' => $this->categoryAdminService->emptyLounge()];
    }
}
