<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\History;

use Piwigo\Activity\ActivityLogger;
use Piwigo\Db\SchemaHelper;
use Piwigo\Db\Tables;
use Piwigo\Picture\PictureService;
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.history.log` — record a page view + bump image counter. */
final readonly class LogHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private PictureService $pictureService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        $currentCtx = SectionContextRegistry::current();

        $section = $currentCtx->section;
        if (!empty($params['section']) && in_array($params['section'], SchemaHelper::getEnums(Tables::history(), 'section'))) {
            $section = is_string($params['section']) ? $params['section'] : $section;
        }

        $category = $currentCtx->category;
        if (!empty($params['cat_id'])) {
            $category = ['id' => $params['cat_id']];
        }

        $tagIds     = $currentCtx->tagIds;
        $tagsString = is_string($params['tags_string'] ?? null) ? $params['tags_string'] : '';
        if ($tagsString !== '' && preg_match('/^\d+(,\d+)*$/', $tagsString)) {
            $tagIds = array_map(intval(...), explode(',', $tagsString));
        }

        SectionContextRegistry::set(new SectionContext(
            section: $section,
            category: $category,
            tagIds: $tagIds,
        ));

        $logImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : null;
        if (!empty($params['image_id']) && $logImageId !== null) {
            $this->pictureService->increaseImageVisitCounter($logImageId);
        }
        $imageType = $params['is_download'] ? 'high' : 'picture';
        $this->activityLogger->pageView($logImageId, $imageType);
        return null;
    }
}
