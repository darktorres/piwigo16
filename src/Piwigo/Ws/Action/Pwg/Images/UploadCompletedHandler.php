<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Picture\WsImagesUploadCompleted;
use Piwigo\Html\HtmlService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Psr\EventDispatcher\EventDispatcherInterface;

/** `pwg.images.uploadCompleted` — flush the lounge + dispatch the upload-completed event. */
final readonly class UploadCompletedHandler implements WsAction
{
    public function __construct(
        private CategoryAdminService $categoryAdminService,
        private CategoryRepository $categoryRepository,
        private CsrfService $csrfService,
        private EventDispatcherInterface $dispatcher,
        private HtmlService $htmlService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $ucImageIdsRaw = $params['image_id'];
        if (!is_array($ucImageIdsRaw)) {
            $ucImageIdsRaw = (($ucSplit = preg_split('/[\s,;\|]/', is_scalar($ucImageIdsRaw) ? (string) $ucImageIdsRaw : '', -1, PREG_SPLIT_NO_EMPTY)) !== false ? $ucSplit : []);
        }
        $ucImageIdsRaw   = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $ucImageIdsRaw);
        $imageIds        = array_values(array_filter($ucImageIdsRaw, fn (int $v): bool => $v > 0));
        $ucCategoryId    = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $movedFromLounge = $this->categoryAdminService->emptyLounge();
        $categoryInfos   = ['nb_photos' => $this->categoryRepository->countImagesByCategoryId($ucCategoryId)];
        $categoryName    = $this->htmlService->getCatDisplayNameFromId($ucCategoryId, null);
        $this->dispatcher->dispatch(new WsImagesUploadCompleted(['image_ids' => $imageIds, 'category_id' => $ucCategoryId, 'moved_from_lounge' => $movedFromLounge]));
        return ['moved_from_lounge' => $movedFromLounge, 'category' => ['id' => $ucCategoryId, 'nb_photos' => $categoryInfos['nb_photos'], 'label' => $categoryName]];
    }
}
