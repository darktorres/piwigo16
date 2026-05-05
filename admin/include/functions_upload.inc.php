<?php

declare(strict_types=1);

use Piwigo\Admin\Upload\UploadService;
use Piwigo\Core\ServiceLocator;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

require_once(PHPWG_ROOT_PATH . 'admin/include/functions.php');

/* File-level event handler registrations — must stay here. */
add_event_handler('upload_image_resize', 'pwg_image_resize');
add_event_handler('upload_thumbnail_resize', 'pwg_image_resize');

/** @return array<string, array{default: bool|int|string, can_be_null: bool, min?: int, max?: int, pattern?: string, error_message?: string}> */
function get_upload_form_config(): array
{
    return ServiceLocator::get(UploadService::class)->getUploadFormConfig();
}

/**
 * @param array<mixed> $data
 * @param string[] $errors
 * @param string[] $form_errors
 */
function save_upload_form_config(array $data, array &$errors = [], array &$form_errors = []): bool
{
    return ServiceLocator::get(UploadService::class)->saveUploadFormConfig($data, $errors, $form_errors);
}

/** @param int[]|null $categories */
function add_uploaded_file(string $source_filepath, ?string $original_filename = null, ?array $categories = null, ?int $level = null, ?int $image_id = null, ?string $original_md5sum = null): int
{
    return ServiceLocator::get(UploadService::class)->addUploadedFile($source_filepath, $original_filename, $categories, $level, $image_id, $original_md5sum);
}

/** @param int[]|null $categories */
function add_uploaded_file_add_to_categories(int $image_id, ?array $categories): void
{
    ServiceLocator::get(UploadService::class)->addUploadedFileAddToCategories($image_id, $categories);
}

function add_format(string $source_filepath, string $format_ext, string $format_of): string
{
    return ServiceLocator::get(UploadService::class)->addFormat($source_filepath, $format_ext, $format_of);
}

add_event_handler('upload_file', 'upload_file_pdf');
function upload_file_pdf(?string $representative_ext, string $file_path): ?string
{
    return ServiceLocator::get(UploadService::class)->uploadFilePdf($representative_ext, $file_path);
}

add_event_handler('upload_file', 'upload_file_heic');
function upload_file_heic(?string $representative_ext, string $file_path): ?string
{
    return ServiceLocator::get(UploadService::class)->uploadFileHeic($representative_ext, $file_path);
}

add_event_handler('upload_file', 'upload_file_tiff');
function upload_file_tiff(?string $representative_ext, string $file_path): ?string
{
    return ServiceLocator::get(UploadService::class)->uploadFileTiff($representative_ext, $file_path);
}

add_event_handler('upload_file', 'upload_file_video');
function upload_file_video(?string $representative_ext, string $file_path): ?string
{
    return ServiceLocator::get(UploadService::class)->uploadFileVideo($representative_ext, $file_path);
}

add_event_handler('upload_file', 'upload_file_psd');
function upload_file_psd(?string $representative_ext, string $file_path): ?string
{
    return ServiceLocator::get(UploadService::class)->uploadFilePsd($representative_ext, $file_path);
}

add_event_handler('upload_file', 'upload_file_eps');
function upload_file_eps(?string $representative_ext, string $file_path): ?string
{
    return ServiceLocator::get(UploadService::class)->uploadFileEps($representative_ext, $file_path);
}

function prepare_directory(string $directory): void
{
    ServiceLocator::get(UploadService::class)->prepareDirectory($directory);
}

function need_resize(string $image_filepath, int|string $max_width, int|string $max_height): bool
{
    return ServiceLocator::get(UploadService::class)->needResize($image_filepath, $max_width, $max_height);
}

/** @return array<string,mixed> */
function pwg_image_infos(string $path): array
{
    return ServiceLocator::get(UploadService::class)->pwgImageInfos($path);
}

/** @return string[] */
function is_valid_image_extension(string $extension): array
{
    return ServiceLocator::get(UploadService::class)->isValidImageExtension($extension);
}

function file_upload_error_message(int $error_code): string
{
    return ServiceLocator::get(UploadService::class)->fileUploadErrorMessage($error_code);
}

function get_ini_size(string $ini_key, bool $in_bytes = true): int|string
{
    return ServiceLocator::get(UploadService::class)->getIniSize($ini_key, $in_bytes);
}

function convert_shorthand_notation_to_bytes(int|string $value): int
{
    return ServiceLocator::get(UploadService::class)->convertShorthandNotationToBytes($value);
}

function add_upload_error(string $upload_id, string $error_message): void
{
    ServiceLocator::get(UploadService::class)->addUploadError($upload_id, $error_message);
}

function ready_for_upload_message(): ?string
{
    return ServiceLocator::get(UploadService::class)->readyForUploadMessage();
}

/** @return array<int, int|float> */
function get_optimal_dimensions_for_representative(): array
{
    return ServiceLocator::get(UploadService::class)->getOptimalDimensionsForRepresentative();
}
