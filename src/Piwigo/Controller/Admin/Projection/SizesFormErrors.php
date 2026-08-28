<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * `configuration_sizes.latte`'s validation-failure messages, produced by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::processSizes()}.
 *
 * Was `$errors + $derivative_errors`, two differently-shaped maps added
 * together: a flat `field => message` for the original-resize fields and the
 * global resize quality, and a nested `type => [w|h|sharpen => message]` for
 * the per-size table. The template told them apart by which key it reached
 * for.
 *
 * The four flat names are a closed set -- three come from
 * `UploadService::saveUploadFormConfig()`, which writes `$form_errors[$field]`
 * for exactly the fields it was handed, and the fourth is `resize_quality`
 * -- so they are real properties. `$byType` stays an array because the
 * template indexes it by `$type`, a runtime key from its own `{foreach}`
 * over the derivatives.
 */
final readonly class SizesFormErrors
{
    /**
     * @param array<string, DerivativeSizeErrors> $byType
     */
    public function __construct(
        public ?string $originalResizeMaxwidth = null,
        public ?string $originalResizeMaxheight = null,
        public ?string $originalResizeQuality = null,
        public ?string $resizeQuality = null,
        public array $byType = [],
    ) {}

    /**
     * The per-size messages for one derivative type, or null when that size
     * validated cleanly. A method rather than a bare array read because the
     * template asks this of a `?SizesFormErrors`, and
     * `$ferrors?->forType($type)?->width` says in one line what a nested
     * `isset()` chain said in three.
     */
    public function forType(string $type): ?DerivativeSizeErrors
    {
        return $this->byType[$type] ?? null;
    }
}
