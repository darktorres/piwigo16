<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `themes` table (installed/active themes, row deleted outright
 * on deactivation) -- natural PK `id` (the theme directory-name
 * identifier, referenced by `user_infos.theme`), not auto-increment.
 *
 * `Users\UserRepository::countUserInfosRows()`/`fetchUserInfosWithThemeName()`
 * LEFT JOIN `themes`; this entity is also queried directly by other
 * domains that don't need `ThemeRepository`'s own `findAllIdsAndNames()`,
 * same shape as {@see \Piwigo\Tag\ImageTagEntity}. `getRepository(ThemeEntity::class)`
 * resolves to `ThemeRepository`, not a generic `EntityRepository`. Lives
 * in `Piwigo\Core` (L1Infrastructure), alongside `ThemeRepository`/
 * `ThemeCatalog`, not a `Piwigo\Theme` namespace -- `deptrac.yaml`'s
 * L1Infrastructure collector is a fixed namespace enumeration.
 * `Users\UserRepository` (L2aCoreDomain) depending on this is an allowed
 * direction (L2aCoreDomain -> L1Infrastructure).
 */
#[ORM\Entity(repositoryClass: ThemeRepository::class)]
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
