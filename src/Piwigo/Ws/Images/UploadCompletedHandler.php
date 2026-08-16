<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Override;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Event\Picture\WsImagesUploadCompleted;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.uploadCompleted` -- admin only. Notifies Piwigo you have
 * finished uploading a set of photos. It will empty the lounge, if any.
 *
 * @since 12
 */
final readonly class UploadCompletedHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private HtmlService $htmlService,
        private EventDispatcher $eventDispatcher,
        private WsCsrfGuard $wsCsrfGuard,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{moved_from_lounge: list<array{image_id: int, category_id: int}>|null, category: array{id: int, nb_photos: int, label: string}}
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array
    {
        $input = UploadCompletedParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        // the list of images moved from the lounge might not be the same than
        // $image_ids (canbe a subset or more image_ids from another upload too)
        $moved_from_lounge = $this->imageService
            ->emptyLounge();

        $nb_photos = $this->imageService->countImagesInCategory(CategoryId::from($input->categoryId));
        $category_name = $this->htmlService
            ->getCatDisplayNameFromId($input->categoryId, null);

        $this->eventDispatcher->dispatch(new WsImagesUploadCompleted([
            'image_ids' => $input->imageIds,
            'category_id' => $input->categoryId,
            'moved_from_lounge' => $moved_from_lounge,
        ]));

        return [
            'moved_from_lounge' => $moved_from_lounge,
            'category' => [
                'id' => $input->categoryId,
                'nb_photos' => $nb_photos,
                'label' => $category_name,
            ],
        ];
    }
}
