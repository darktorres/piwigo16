<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\OperationError;
use Piwigo\Core\ValidationPattern;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageCategoryRelationsHelper;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\Projection\Image;
use Piwigo\Tag\TagService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PATCH /api/v1/images/{id}` -- `pwg.images.setInfo`'s real replacement,
 * admin + CSRF. Nothing here reads a `tag_list` straight off raw
 * superglobals -- there is nothing for a JSON API to read from those
 * anyway.
 */
final readonly class ImageUpdateController implements ControllerInterface
{
    private const array INFO_COLUMNS = ['name', 'author', 'comment', 'level', 'dateCreation'];

    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CurrentConfig $currentConfig,
        private ImageRepository $imageRepository,
        private ImageService $imageService,
        private ActivityService $activityService,
        private TagService $tagService,
        private ImageCategoryRelationsHelper $imageCategoryRelationsHelper,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        $routeArgs = $request->getAttribute('route_args');
        $rawId = is_array($routeArgs) ? ($routeArgs['id'] ?? null) : null;
        $imageIdValue = is_string($rawId) ? (int) $rawId : 0;
        $imageId = ImageId::from($imageIdValue);

        $imageRow = $this->imageRepository->findById($imageId);
        if (! $imageRow instanceof Image) {
            return ResponseFactory::problem('Not Found', 404, 'This image does not exist.');
        }

        $input = ImageUpdateInput::fromArray(JsonBody::decode($request));

        if (! in_array($input->singleValueMode, ['fillIfEmpty', 'replace'], true)) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid singleValueMode, possible values are {fillIfEmpty, replace}.');
        }
        if (! in_array($input->multipleValueMode, ['append', 'replace'], true)) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid multipleValueMode, possible values are {append, replace}.');
        }

        $values = [
            'name' => $input->name,
            'author' => $input->author,
            'comment' => $input->comment,
            'level' => $input->level,
            'dateCreation' => $input->dateCreation,
        ];
        $currentValues = [
            'name' => $imageRow->name,
            'author' => $imageRow->author,
            'comment' => $imageRow->comment,
            'level' => $imageRow->level,
            'dateCreation' => $imageRow->dateCreation,
        ];

        $update = [];
        foreach (self::INFO_COLUMNS as $key) {
            $value = $values[$key];
            if ($value === null) {
                continue;
            }

            if (is_string($value) && ! $this->currentConfig->allowHtmlDescriptions) {
                $value = strip_tags($value, '<b><strong><em><i>');
            }

            if ($input->singleValueMode === 'fillIfEmpty') {
                if (in_array($currentValues[$key], [null, 0, '0', ''], true)) {
                    $update[self::dbColumn($key)] = $value;
                }
            } else {
                $update[self::dbColumn($key)] = $value;
            }
        }

        if ($input->file !== null) {
            if (($imageRow->storageCategoryId ?? 0) !== 0) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Updating "file" is forbidden on photos added by synchronization.');
            }

            $file = strip_tags($input->file);
            if ($file !== '' && $file !== '0') {
                $update['file'] = $file;
            }
        }

        if ($update !== []) {
            $this->imageService->updateFields($imageId, $update);
            $this->entityManager->clear();
            $this->activityService->record('photo', $imageIdValue, 'edit');
        }

        if ($input->categories !== null) {
            $result = $this->imageCategoryRelationsHelper->addImageCategoryRelations(
                $imageId,
                $input->categories,
                $input->multipleValueMode === 'replace'
            );
            if ($result instanceof OperationError) {
                return ResponseFactory::problem('Unprocessable Entity', 422, $result->message());
            }
        }

        if ($input->tagIds !== null) {
            $tagIds = [];
            foreach (explode(',', $input->tagIds) as $candidate) {
                $candidate = trim($candidate);
                if (preg_match(ValidationPattern::ID, $candidate) === 1) {
                    $tagIds[] = TagId::from((int) $candidate);
                }
            }

            if ($input->multipleValueMode === 'replace') {
                $this->tagService->setTags($tagIds, $imageIdValue, $this->imageService);
            } else {
                $this->tagService->addTags($tagIds, [$imageIdValue], $this->imageService);
            }
        }

        PermissionCacheInvalidator::invalidate();

        $updated = $this->imageRepository->findById($imageId);
        assert($updated instanceof Image);

        return ResponseFactory::json([
            'id' => $updated->id->value,
            'file' => $updated->file,
            'name' => $updated->name,
            'author' => $updated->author,
            'comment' => $updated->comment,
            'level' => $updated->level,
            'dateCreation' => $updated->dateCreation,
            'lastmodified' => $updated->lastmodified,
        ]);
    }

    private static function dbColumn(string $key): string
    {
        return match ($key) {
            'dateCreation' => 'date_creation',
            default => $key,
        };
    }
}
