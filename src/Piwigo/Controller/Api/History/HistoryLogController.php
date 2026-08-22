<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\History;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\History\HistoryEntity;
use Piwigo\History\HistoryService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/history/log` -- `pwg.history.log`'s real replacement.
 * Public, no auth gate and no CSRF check -- every gallery-page-view
 * fires this, anonymous visitors included.
 */
final readonly class HistoryLogController implements ControllerInterface
{
    public function __construct(
        private HistoryService $historyService,
        private ImageRepository $imageRepository,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = HistoryLogInput::fromArray(JsonBody::decode($request));

        $section = null;
        if ($input->section !== null) {
            $historyRepository = $this->entityManager->getRepository(HistoryEntity::class);
            if (in_array($input->section, $historyRepository->getSectionEnumOptions(), true)) {
                $section = $input->section;
            }
        }

        $categoryId = $input->catId !== null && $input->catId !== 0 ? $input->catId : null;

        $tagIds = null;
        if ($input->tagsString !== null && (bool) preg_match('/^\d+(,\d+)*$/', $input->tagsString)) {
            $tagIds = array_map(intval(...), explode(',', $input->tagsString));
        }

        $historyImageId = ImageId::tryFrom($input->imageId);
        if ($historyImageId instanceof ImageId) {
            $this->imageRepository->incrementVisitCounter($historyImageId);
        }

        $imageType = $input->isDownload ? 'high' : 'picture';

        $logged = $this->historyService->logVisit(
            $input->imageId,
            $imageType,
            section: $section,
            categoryId: $categoryId,
            tagIds: $tagIds,
        );

        return ResponseFactory::json([
            'logged' => $logged,
        ], 201);
    }
}
