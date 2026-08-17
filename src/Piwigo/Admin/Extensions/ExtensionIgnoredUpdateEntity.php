<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `extension_ignored_updates` table. Replaces the former
 * `updates_ignored` config blob (one JSON object keyed by the *plural*
 * page-slug form, `{plugins: [...], themes: [...], languages: [...]}`).
 *
 * `extensionType` stores `ExtensionType::value` (singular: 'plugin'/
 * 'theme'/'language'), not the plural form `/api/v1/extensions/*`'s own
 * `type` query parameter uses -- there is no legacy blob shape left to
 * match in a brand-new table. Real callers translate between the plural
 * query form and ExtensionType via ExtensionType::fromPluralParam().
 */
#[ORM\Entity(repositoryClass: ExtensionIgnoredUpdateRepository::class)]
#[ORM\Table(name: 'extension_ignored_updates')]
final class ExtensionIgnoredUpdateEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'extension_type', type: 'string', length: 16)]
        public string $extensionType,
        #[ORM\Id]
        #[ORM\Column(name: 'extension_id', type: 'string', length: 64)]
        public string $extensionId,
        #[ORM\Column(name: 'ignored_at', type: 'string', length: 19)]
        public string $ignoredAt,
    ) {}
}
