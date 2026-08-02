<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `themes` table (installed/active themes, row deleted outright
 * on deactivation) -- natural PK `id` (the theme directory-name
 * identifier, referenced by `user_infos.theme`), not auto-increment.
 *
 * Further SQL-modernization audit, Item 14 Sub-phase B1: {@see
 * ThemeRepository}'s own docblock previously claimed "no ORM entity
 * exists for it (no real caller ever needed one beyond the id/name
 * listing below)" -- re-audited: `Users\UserRepository::
 * countUserInfosRows()`/`fetchUserInfosWithThemeName()` LEFT JOIN
 * `themes`, a real DQL-conversion use `ThemeRepository`'s own raw
 * id/name listing didn't need to anticipate. No `repositoryClass` --
 * `ThemeRepository` stays plain DBAL for its own existing use, this
 * entity is queried directly by whichever domain needs it, same shape
 * as {@see \Piwigo\Tag\ImageTagEntity}. Lives in `Piwigo\Core`
 * (L1Infrastructure), alongside `ThemeRepository`/`ThemeCatalog`, not a
 * new `Piwigo\Theme` namespace -- `deptrac.yaml`'s own L1Infrastructure
 * collector is a fixed namespace enumeration, same reasoning
 * `ThemeCatalog`'s own docblock already documents. `Users\UserRepository`
 * (L2aCoreDomain) depending on this is an allowed direction
 * (L2aCoreDomain -> L1Infrastructure).
 */
#[ORM\Entity]
#[ORM\Table(name: 'themes')]
final class ThemeEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 64)]
        public string $id,
        #[ORM\Column(type: 'string', length: 64)]
        public string $version,
        #[ORM\Column(type: 'string', length: 64, nullable: true)]
        public ?string $name,
    ) {}
}
