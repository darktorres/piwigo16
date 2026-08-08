<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

/**
 * {@see \Piwigo\Image\ImageRepository::findAddMethodBreakdown()}'s own
 * per-add-method row shape -- `Admin\PiwigoInfosSender`'s own "how were
 * most photos added" telemetry breakdown.
 *
 * `toArray()` preserves the exact original snake_case shape: real
 * consumers copy individual scalar fields out (never the whole row) into
 * a telemetry payload keyed by those exact names, so a call site that
 * ever needs the whole row as an array (rather than field-by-field) must
 * go through `toArray()` first, not pass this DTO through directly.
 */
final readonly class AddMethodBreakdown
{
    public function __construct(
        public string $addMethod,
        public ?string $lastAddedOn,
        public int $nbFiles,
    ) {}

    /**
     * @return array{add_method: string, last_added_on: ?string, nb_files: int}
     */
    public function toArray(): array
    {
        return [
            'add_method' => $this->addMethod,
            'last_added_on' => $this->lastAddedOn,
            'nb_files' => $this->nbFiles,
        ];
    }
}
