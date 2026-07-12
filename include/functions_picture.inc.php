<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Db\DbConnection;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;

/**
 * Returns slideshow default params.
 * - period
 * - repeat
 * - play
 * @return array<string, mixed>
 */
function get_default_slideshow_params(): array
{
    return new ImageService()
        ->getDefaultSlideshowParams();
}

/**
 * Checks and corrects slideshow params
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function correct_slideshow_params(array $params = []): array
{
    return new ImageService()
        ->correctSlideshowParams($params);
}

/**
 * Decodes slideshow string params into array
 *
 * @param string $encode_params
 * @return array<string, mixed>
 */
function decode_slideshow_params($encode_params = null): array
{
    return new ImageService()
        ->decodeSlideshowParams(is_string($encode_params) ? $encode_params : null);
}

/**
 * Encodes slideshow array params into a string
 * @param array<string, mixed> $decode_params
 */
function encode_slideshow_params(array $decode_params = []): string
{
    return new ImageService()
        ->encodeSlideshowParams($decode_params);
}

/**
 * Increase the number of visits for a given photo.
 *
 * Code moved from picture.php to be used by both the API and picture.php
 *
 * @since 14
 * @param int $image_id
 */
function increase_image_visit_counter($image_id): void
{
    new ImageRepository(DbConnection::build())->incrementVisitCounter($image_id);
}

/**
 * Returns the number of pages of a PDF file
 *
 * @param string $pdfPath
 * @return int
 */
function count_pdf_pages($pdfPath): int|false
{
    return new ImageService()
        ->countPdfPages($pdfPath);
}
