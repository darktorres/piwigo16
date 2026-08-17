<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

/**
 * `POST /api/v1/images/formats/actions/search` body DTO -- mirrors
 * `Ws\Images\FormatsSearchImageParams`'s own `filename_list` shape, but
 * as a real JSON object (`{uniqueId: filename}`) instead of a JSON
 * string that itself has to be `json_decode()`d again.
 */
final readonly class ImageFormatSearchInput
{
    /**
     * @param array<string, string> $filenames
     */
    public function __construct(
        public array $filenames,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $filenameList = $raw['filenames'] ?? null;
        $filenames = [];
        if (is_array($filenameList)) {
            foreach ($filenameList as $uniqueId => $filename) {
                if (is_string($filename)) {
                    $filenames[(string) $uniqueId] = $filename;
                }
            }
        }

        return new self(filenames: $filenames);
    }
}
