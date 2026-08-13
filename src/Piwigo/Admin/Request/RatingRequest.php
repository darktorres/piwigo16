<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET` shape for RatingPageRenderer::render() (page slug
 * "rating"). `catRaw`/`usersRaw` are handed straight to a template array
 * unchanged (`'category' => [$_GET['cat']]` / `'user_options_selected' =>
 * [$_GET['users']]`), independently of `catId`'s own numeric-narrowed
 * value used for the actual subcategory query -- neither has a
 * `InputValidator::validate()` call of its own, so the array possibility
 * is unconditionally real; a selection widget must tolerate an
 * unmatched/garbage value without erroring (it just fails to mark
 * anything selected), so nothing here should close that off.
 * `array<array-key, mixed>|string|null` (not a bare `mixed`) is the
 * real, exhaustive shape a `$_GET` leaf can ever take -- PHP's own
 * query-string parser never produces a raw bool/int/float there.
 * `orderByIndex` is exposed raw (no bounds check) -- `RatingPageRenderer`
 * itself is responsible for clamping it against `$available_order_by`'s
 * size, a renderer-local constant this DTO has no visibility into, same
 * precedent as `RatingUserFilterRequest`/`RatingUserPageRenderer`.
 */
final readonly class RatingRequest
{
    /**
     * @param array<array-key, mixed>|string|null $catRaw
     * @param array<array-key, mixed>|string|null $usersRaw
     */
    private function __construct(
        public int $start,
        public int $elementsPerPage,
        public int $orderByIndex,
        public bool $catPresent,
        public array|string|null $catRaw,
        public ?int $catId,
        public array|string|null $usersRaw,
        public bool $isUsersUser,
        public bool $isUsersGuest,
    ) {}

    public static function fromGlobals(InputValidator $inputValidator): self
    {
        return self::fromArray($_GET, $inputValidator);
    }

    /**
     * @param array<int|string, mixed> $get
     */
    public static function fromArray(array $get, InputValidator $inputValidator): self
    {
        $inputValidator
            ->validate('display', $get, false, ValidationPattern::ID);

        $start = 0;
        if (isset($get['start']) and is_numeric($get['start'])) {
            $start = (int) $get['start'];
        }

        $elements_per_page = 10;
        if (isset($get['display']) and is_numeric($get['display'])) {
            $elements_per_page = (int) $get['display'];
        }

        $order_by_index = 0;
        if (isset($get['order_by']) and is_numeric($get['order_by'])) {
            $order_by_index = (int) $get['order_by'];
        }

        $cat_present = isset($get['cat']);
        $raw_cat = $get['cat'] ?? null;
        $cat_id = ($cat_present and is_numeric($raw_cat)) ? (int) $raw_cat : null;
        $cat_raw = is_string($raw_cat) || is_array($raw_cat) ? $raw_cat : null;

        $raw_users = $get['users'] ?? null;
        $users_raw = is_string($raw_users) || is_array($raw_users) ? $raw_users : null;

        return new self(
            $start,
            $elements_per_page,
            $order_by_index,
            $cat_present,
            $cat_raw,
            $cat_id,
            $users_raw,
            $users_raw === 'user',
            $users_raw === 'guest',
        );
    }
}
