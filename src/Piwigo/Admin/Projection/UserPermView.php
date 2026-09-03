<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Latte\Runtime\Html;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `user_perm.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\UserPermPageRenderer::render()}. `$doubleSelect` is
 * this page's own rendered {@see DoubleSelectView} sub-render -- the
 * category-option labels/lists it needs are only ever read by that
 * sub-render, never by `user_perm.latte`'s own body directly.
 * `$categoriesBecauseOfGroups` is genuinely optional -- only present
 * when the user has at least one group-granted category. Its entries
 * are Html, not string (P59): each is a `getCatDisplayNameCache()`
 * result, the same already-`htmlspecialchars()`-escaping trusted
 * producer used elsewhere.
 */
#[Template('user_perm.latte')]
final readonly class UserPermView implements View
{
    /**
     * @param list<Html>|null $categoriesBecauseOfGroups
     */
    public function __construct(
        public string $title,
        public ?array $categoriesBecauseOfGroups,
        public string $formAction,
        public string $pwgToken,
        public Html $doubleSelect,
    ) {}
}
