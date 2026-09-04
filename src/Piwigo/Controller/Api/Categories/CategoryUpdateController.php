<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Projection\Category;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PATCH /api/v1/categories/{id}` -- `pwg.categories.setInfo`'s real
 * replacement, admin + CSRF. `{id}` is route-constrained to `\d+`.
 */
final readonly class CategoryUpdateController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CategoryService $categoryService,
        private CategoryRepository $categoryRepository,
        private ActivityService $activityService,
        private CurrentConfig $currentConfig,
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
        $categoryId = is_string($rawId) ? (int) $rawId : 0;
        $categoryIdVo = CategoryId::tryFrom($categoryId);

        $category = $categoryIdVo instanceof CategoryId ? $this->categoryRepository->findById($categoryIdVo) : null;
        if (! $category instanceof Category) {
            return ResponseFactory::problem('Not Found', 404, 'This album does not exist.');
        }

        $input = CategoryUpdateInput::fromArray(JsonBody::decode($request));

        if ($input->status !== null) {
            if (! in_array($input->status, ['private', 'public'], true)) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid status, only public/private.');
            }
            if ($input->status !== $category->status) {
                $this->categoryService->setCatStatus([$categoryId], $input->status, $this->entityManager);
            }
        }

        $update = [];

        if ($input->visible !== null && $input->visible !== $category->visible) {
            $this->categoryService->setCatVisible([$categoryId], $input->visible);
            $update['visible'] = $input->visible;
        }

        if ($input->name !== null) {
            $update['name'] = $this->currentConfig->allowHtmlDescriptions ? $input->name : strip_tags($input->name);
        }
        if ($input->comment !== null) {
            $update['comment'] = $this->currentConfig->allowHtmlDescriptions ? $input->comment : strip_tags($input->comment);
        }

        if ($input->commentable !== null && $input->applyCommentableToSubalbums) {
            $subcats = $this->categoryService->getSubcatIds([$categoryId]);
            if ($subcats !== []) {
                $this->categoryService->setCatCommentable($subcats, $input->commentable);
            }
        } elseif ($input->commentable !== null && $input->commentable !== $category->commentable) {
            $this->categoryService->setCatCommentable([$categoryId], $input->commentable);
        }

        $updateFields = $update;
        unset($updateFields['visible']);
        if ($updateFields !== []) {
            $this->categoryService->updateFields(CategoryId::from($categoryId), $updateFields);
        }

        $this->activityService->record('album', $categoryId, 'edit', [
            'fields' => implode(',', array_keys($update)),
        ]);

        $updated = $this->categoryRepository->findById($categoryIdVo);

        return ResponseFactory::json(CategoryPresenter::toArray($updated));
    }
}
