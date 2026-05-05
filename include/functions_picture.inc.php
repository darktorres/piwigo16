<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Picture\PictureService;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
/**
 * @package functions\picture
 */
/** @return array<string, mixed> */
function get_default_slideshow_params(): array
{
    return ServiceLocator::get(PictureService::class)->getDefaultSlideshowParams();
}

/**
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function correct_slideshow_params(array $params = []): array
{
    return ServiceLocator::get(PictureService::class)->correctSlideshowParams($params);
}

/** @return array<string, mixed> */
function decode_slideshow_params(?string $encode_params = null): array
{
    return ServiceLocator::get(PictureService::class)->decodeSlideshowParams($encode_params);
}

/** @param array<string, mixed> $decode_params */
function encode_slideshow_params(array $decode_params = []): string
{
    return ServiceLocator::get(PictureService::class)->encodeSlideshowParams($decode_params);
}

function increase_image_visit_counter(int $image_id): void
{
    ServiceLocator::get(PictureService::class)->increaseImageVisitCounter($image_id);
}

function count_pdf_pages(string $pdfPath): int|false
{
    return ServiceLocator::get(PictureService::class)->countPdfPages($pdfPath);
}
