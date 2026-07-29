<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Doctrine\ORM\EntityRepository;

/**
 * Persistence layer for `extension_ignored_updates`. A "set membership"
 * table like every other ignore-list this migration touches -- ignore()
 * must not throw when the same extension is ignored twice (e.g. re-running
 * checkExtensions() after the row already exists).
 *
 * @extends EntityRepository<ExtensionIgnoredUpdateEntity>
 */
final class ExtensionIgnoredUpdateRepository extends EntityRepository
{
    public function isIgnored(ExtensionType $type, string $extensionId): bool
    {
        return $this->find([
            'extensionType' => $type->value,
            'extensionId' => $extensionId,
        ]) !== null;
    }

    /**
     * @return list<string>
     */
    public function findIgnoredIdsByType(ExtensionType $type): array
    {
        $entities = $this->findBy([
            'extensionType' => $type->value,
        ]);

        return array_map(static fn (ExtensionIgnoredUpdateEntity $e): string => $e->extensionId, $entities);
    }

    public function ignore(ExtensionType $type, string $extensionId, string $now): void
    {
        if ($this->isIgnored($type, $extensionId)) {
            return;
        }

        $em = $this->getEntityManager();
        $em->persist(new ExtensionIgnoredUpdateEntity($type->value, $extensionId, $now));
        $em->flush();
    }

    public function unignore(ExtensionType $type, string $extensionId): void
    {
        $entity = $this->find([
            'extensionType' => $type->value,
            'extensionId' => $extensionId,
        ]);
        if ($entity === null) {
            return;
        }

        $em = $this->getEntityManager();
        $em->remove($entity);
        $em->flush();
    }

    /**
     * Deletes every ignored-update row for $type -- the WS method's own
     * "reset" action for a single type.
     */
    public function resetType(ExtensionType $type): void
    {
        $em = $this->getEntityManager();
        foreach ($this->findBy([
            'extensionType' => $type->value,
        ]) as $entity) {
            $em->remove($entity);
        }
        $em->flush();
    }

    /**
     * Deletes every ignored-update row across every type -- the WS
     * method's own "reset everything" action (no specific type given).
     */
    public function resetAll(): void
    {
        $em = $this->getEntityManager();
        foreach ($this->findAll() as $entity) {
            $em->remove($entity);
        }
        $em->flush();
    }
}
