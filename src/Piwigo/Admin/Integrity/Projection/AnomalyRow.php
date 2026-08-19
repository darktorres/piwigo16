<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity\Projection;

use Closure;

/**
 * One entry of {@see \Piwigo\Admin\Integrity\CheckIntegrity::$retrieve_list},
 * built exclusively by {@see \Piwigo\Admin\Integrity\CheckIntegrity::
 * addAnomaly()}. Deliberately mutable (not `final readonly`, same
 * rationale as {@see \Piwigo\Category\Projection\ComputedCategoryRow}) --
 * `CheckIntegrity::check()` sets `$corrected`/`$ignored` in place on an
 * already-built row after a correction/ignore action runs.
 *
 * `$corrected`/`$ignored` are `null` (not `false`) until set -- `display()`
 * distinguishes "never attempted" from "attempted and failed", and
 * `$ignored` is only ever set to `true` in practice (an explicit `false`
 * is a real, guarded-against logic error in the original code).
 */
final class AnomalyRow
{
    /**
     * @param ?array<string, mixed> $correctionFctArgs
     */
    public function __construct(
        public readonly string $id,
        public readonly string $anomaly,
        public readonly string|Closure|null $correctionFct,
        public readonly ?array $correctionFctArgs,
        public readonly ?string $correctionMsg,
        public readonly bool $isCallable,
        public ?bool $corrected = null,
        public ?bool $ignored = null,
    ) {}
}
