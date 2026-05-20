<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\History;

use Piwigo\Activity\ActivityLogger;
use Piwigo\Common\Enum\Section;
use Piwigo\Image\ImageType;
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
        $input      = LogParams::fromArray($params);
        $currentCtx = SectionContextRegistry::current();

        $section = $currentCtx->section;
        if ($input->section !== null) {
            $section = Section::tryFrom($input->section) ?? $section;
        }

        $category = $currentCtx->category;
        if (!empty($input->catId)) {
            $category = ['id' => $input->catId];
        }

        $tagIds = $currentCtx->tagIds;
        if ($input->tagsString !== '' && preg_match('/^\d+(,\d+)*$/', $input->tagsString)) {
            $tagIds = array_map(intval(...), explode(',', $input->tagsString));
        }

        SectionContextRegistry::set(new SectionContext(
            section: $section,
            category: $category,
            tagIds: $tagIds,
        ));

        if ($input->imageId !== null) {
            $this->pictureService->increaseImageVisitCounter($input->imageId);
        }
        $imageType = $input->isDownload ? ImageType::High : ImageType::Picture;
        $this->activityLogger->pageView($input->imageId, $imageType);
        return null;
    }
}
