<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Config\Config;
use Piwigo\Core\StringUtil;
use Piwigo\Image\ImageFormatRepository;
use Piwigo\Image\ImageRepository;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.images.formats.searchImage` — match a JSON `unique_id:filename` map back to image_ids. */
final readonly class FormatsSearchImageHandler implements WsAction
{
    public function __construct(
        private ImageFormatRepository $imageFormatRepository,
        private ImageRepository $imageRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        $input             = FormatsSearchImageParams::fromArray($params);
        $candidates        = json_decode(stripslashes($input->filenameListJson), true);
        $uniqueFilenamesDb = [];
        foreach ($this->imageRepository->findAllIdFilename() as $row) {
            $filenameWoExt = StringUtil::getFilenameWoExtension((string) $row->file);
            $uniqueFilenamesDb[$filenameWoExt][] = $row->id->value;
        }
        $formatExtensions = Config::formatExtensions();
        usort($formatExtensions, fn (mixed $a, mixed $b): int => strlen((string) $b) - strlen((string) $a));
        /** @var array<string, list<string>> $formatDb */
        $formatDb = [];
        foreach ($this->imageFormatRepository->findAll() as $row) {
            $fmtImageId = is_scalar($row['image_id'] ?? null) ? (string) $row['image_id'] : '';
            $fmtExtVal  = is_string($row['ext'] ?? null) ? $row['ext'] : '';
            $formatDb[$fmtImageId][] = $fmtExtVal;
        }
        $result        = [];
        $candidatesArr = is_array($candidates) ? $candidates : [];
        foreach ($candidatesArr as $formatExternalId => $formatFilename) {
            $fmtExternalIdStr       = (string) $formatExternalId;
            $fmtFilenameStr         = is_scalar($formatFilename) ? (string) $formatFilename : '';
            $candidateFilenameWoExt = null;
            if (preg_match('/^(.*?)\.(' . implode('|', Config::formatExtensions()) . ')$/', $fmtFilenameStr, $matches)) {
                $candidateFilenameWoExt = $matches[1];
            }
            if ($candidateFilenameWoExt === null || $candidateFilenameWoExt === '') {
                $result[$fmtExternalIdStr] = ['status' => 'not found'];
                continue;
            }
            if (isset($uniqueFilenamesDb[$candidateFilenameWoExt])) {
                if (count($uniqueFilenamesDb[$candidateFilenameWoExt]) > 1) {
                    $result[$fmtExternalIdStr] = ['status' => 'multiple'];
                    continue;
                }
                $imgIdStr = (string) $uniqueFilenamesDb[$candidateFilenameWoExt][0];
                $multForm = false;
                if (isset($formatDb[$imgIdStr])) {
                    $fmtExt = pathinfo($fmtFilenameStr, PATHINFO_EXTENSION);
                    if (array_search($fmtExt, $formatDb[$imgIdStr]) !== false) {
                        $multForm = true;
                    }
                }
                $result[$fmtExternalIdStr] = ['status' => 'found', 'image_id' => $uniqueFilenamesDb[$candidateFilenameWoExt][0], 'format_exist' => $multForm];
                continue;
            }
            $result[$fmtExternalIdStr] = ['status' => 'not found'];
        }
        return $result;
    }
}
