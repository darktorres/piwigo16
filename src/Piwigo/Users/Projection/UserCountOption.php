<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

/**
 * One entry of `user_list.latte`'s status and privacy-level filter
 * dropdowns, built by {@see \Piwigo\Admin\UserListPageRenderer::render()}.
 *
 * Both lists start from every known status/level and then overwrite the
 * ones that have users with a count. That used to make the bag a
 * `string|array{name: string, counter: int}` union -- a bare label for
 * the empty ones, a two-key array for the rest -- which the template
 * disambiguated with `isset($x['name']) && isset($x['counter'])`,
 * relying on `isset()` returning false for a non-numeric offset on a
 * string. `$counter === null` is the same question asked directly, and
 * the second `isset()` was redundant either way: once the union narrowed
 * to the array member both keys always existed (P58-A's §11).
 */
final readonly class UserCountOption
{
    public function __construct(
        public string $name,
        public ?int $counter = null,
    ) {}
}
