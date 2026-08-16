<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Categories;

use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.categories.add` -- admin only. Adds an album.
 */
final readonly class AddHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private ActivityService $activityService,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
        private WsCsrfGuard $wsCsrfGuard,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{info: string, id: int|string}
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array
    {
        $input = AddParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        if (! in_array($input->position, [null, ''], true) and in_array($input->position, ['first', 'last'], true)) {
            // In-memory override only (this request's own CurrentConfig
            // property), not a real persisted preference -- known
            // limitation, same as AlbumsPageRenderer's own POS_PREF
            // assignment.
            $this->currentConfig->newcatDefaultPosition = $input->position;
        }

        // $input->visible/->commentable are always real bools by the
        // time they reach this handler (WsParamType::BOOL) -- always set
        // on $options so pwg.categories.add's own documented visible/
        // commentable params take effect, unlike status/comment below
        // which are only applied when actually supplied.
        $options = [
            'visible' => $input->visible,
            'commentable' => $input->commentable,
        ];
        if (! in_array($input->status, [null, ''], true) and in_array($input->status, ['private', 'public'], true)) {
            $options['status'] = $input->status;
        }

        if (! in_array($input->comment, [null, ''], true)) {
            $options['comment'] = $this->currentConfig->allowHtmlDescriptions ? $input->comment : strip_tags($input->comment);
        }

        $creation_output = $this->categoryService->createVirtualCategory(
            $this->currentConfig->allowHtmlDescriptions ? $input->name : strip_tags($input->name),
            $this->activityService,
            $this->currentUser,
            $input->parent,
            $options
        );

        if ($creation_output->error !== null) {
            return new WsErrorResponse(500, $creation_output->error);
        }

        PermissionCacheInvalidator::invalidate();

        // success()'s own contract guarantees info/id are non-null whenever
        // error is null.
        return [
            'info' => (string) $creation_output->info,
            'id' => $creation_output->id ?? 0,
        ];
    }
}
