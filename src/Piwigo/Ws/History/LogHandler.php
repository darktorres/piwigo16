<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\History;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\History\HistoryEntity;
use Piwigo\History\HistoryService;
use Piwigo\Image\ImageRepository;
use Piwigo\Ws\WsAction;

/**
 * `pwg.history.log` -- log visit in history.
 *
 * @since 13
 */
final readonly class LogHandler implements WsAction
{
    public function __construct(
        private HistoryService $historyService,
        private ImageRepository $imageRepository,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     * WsAction::__invoke()'s own return type is `mixed` -- `void` isn't a
     * valid covariant subtype of that under PHP's LSP rules (unlike every
     * other return type, `void` is a special case PHP forbids widening
     * away from in inheritance), so this returns null rather than
     * matching the god-class method's own `void`-declared original.
     */
    #[Override]
    public function __invoke(array $params): mixed
    {
        $input = LogParams::fromArray($params);

        $section = null;
        if ($input->section !== null) {
            $historyRepository = $this->entityManager->getRepository(HistoryEntity::class);
            if (in_array($input->section, $historyRepository->getSectionEnumOptions(), true)) {
                $section = $input->section;
            }
        }

        $category = null;
        if ($input->catId !== null and $input->catId !== 0) {
            $category = [
                'id' => $input->catId,
            ];
        }

        $tagIds = null;
        if ($input->tagsString !== null and (bool) preg_match('/^\d+(,\d+)*$/', $input->tagsString)) {
            $tagIds = array_map(intval(...), explode(',', $input->tagsString));
        }

        // when visiting a photo (which is currently, in version 14, the only event registered
        // by pwg.history.log) we should also increment images.hit
        $historyImageId = ImageId::tryFrom($input->imageId);
        if ($historyImageId instanceof ImageId) {
            $this->imageRepository->incrementVisitCounter($historyImageId);
        }

        $image_type = 'picture';
        if ($input->isDownload) {
            $image_type = 'high';
        }

        $this->historyService->logVisit(
            $input->imageId,
            $image_type,
            section: $section,
            category: $category,
            tagIds: $tagIds,
        );

        return null;
    }
}
