<?php

declare(strict_types=1);

namespace Piwigo\Admin\Upload;

/** Validation spec for a single upload-form configuration field. */
final readonly class UploadParamSpec
{
    public function __construct(
        public bool|int|string $default,
        public bool $canBeNull,
        public int|null $min = null,
        public int|null $max = null,
        public string|null $pattern = null,
        public string|null $errorMessage = null,
    ) {
    }
}
