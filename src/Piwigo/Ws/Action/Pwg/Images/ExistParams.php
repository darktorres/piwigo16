<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParams;

/**
 * `pwg.images.exist` input DTO.
 *
 * Caller passes one of the two separator-joined strings; which one is
 * honored depends on Config::uniquenessMode().
 */
final readonly class ExistParams implements WsParams
{
    /**
     * @param list<string> $md5sums
     * @param list<string> $filenames
     */
    public function __construct(
        public array $md5sums,
        public array $filenames,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $splitPattern = '/[\s,;\|]/';
        $md5sumStr    = is_string($raw['md5sum_list']   ?? null) ? $raw['md5sum_list'] : '';
        $filenameStr  = is_string($raw['filename_list'] ?? null) ? $raw['filename_list'] : '';
        $md5sums      = $md5sumStr   !== '' ? preg_split($splitPattern, $md5sumStr, -1, PREG_SPLIT_NO_EMPTY) : [];
        $filenames    = $filenameStr !== '' ? preg_split($splitPattern, $filenameStr, -1, PREG_SPLIT_NO_EMPTY) : [];
        return new self(
            md5sums:   $md5sums   !== false ? $md5sums : [],
            filenames: $filenames !== false ? $filenames : [],
        );
    }
}
