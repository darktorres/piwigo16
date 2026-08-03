<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity\Request;

/**
 * Validated `$_POST` shape for CheckIntegrity::check()'s treatment form
 * (page slug "maintenance", "c13y" tab) -- P26/SEC-40 Request DTO.
 * Faithfully preserves the original nested if/else priority: `correction`
 * mode requires both `c13y_submit_correction` and a real array
 * `c13y_selection`; `ignore` mode is only considered as a fallback (same
 * as the original's `else` branch) when that first check fails, requiring
 * `c13y_submit_ignore` + the same array check. No pattern validation
 * needed: selection ids are only ever compared against this class's own
 * server-generated `retrieve_list` ids, never used in SQL/filesystem
 * paths directly.
 */
final readonly class C13yTreatmentRequest
{
    /**
     * @param 'correction'|'ignore'|null $mode
     * @param list<string> $selection
     */
    private function __construct(
        public ?string $mode,
        public array $selection,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_POST);
    }

    /**
     * @param array<array-key, mixed> $post
     */
    public static function fromArray(array $post): self
    {
        $selection_raw = $post['c13y_selection'] ?? null;
        $has_selection = is_array($selection_raw);
        $selection = $has_selection
            ? array_values(array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $selection_raw))
            : [];

        if (isset($post['c13y_submit_correction']) && $has_selection) {
            $mode = 'correction';
        } elseif (isset($post['c13y_submit_ignore']) && $has_selection) {
            $mode = 'ignore';
        } else {
            $mode = null;
        }

        return new self($mode, $selection);
    }
}
