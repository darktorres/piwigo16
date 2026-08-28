<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * The validation messages for one derivative size on the sizes tab --
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController}'s own
 * `$derivative_errors[$type]`, whose only keys are these three.
 */
final readonly class DerivativeSizeErrors
{
    public function __construct(
        public ?string $width = null,
        public ?string $height = null,
        public ?string $sharpen = null,
    ) {}
}
