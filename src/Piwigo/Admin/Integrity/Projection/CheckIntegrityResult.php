<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity\Projection;

/**
 * {@see \Piwigo\Admin\Integrity\CheckIntegrity::display()}'s own return
 * value -- the raw data a caller threads into its own {@see
 * CheckIntegrityView} construction. Kept as a plain data holder,
 * separate from the View itself, so `display()` stays a pure data
 * method with no `Renderer`/rendering concern of its own -- the same
 * split `PictureMetadataRenderer`/`PictureRateRenderer` already use.
 */
final readonly class CheckIntegrityResult
{
    /**
     * @param list<array<string, mixed>> $c13yList
     * @param list<string>|null $c13yDoCheck
     */
    public function __construct(
        public bool $showSubmitAutomaticCorrection,
        public bool $showSubmitIgnore,
        public array $c13yList,
        public ?array $c13yDoCheck,
    ) {}
}
