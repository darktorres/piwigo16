<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Core\Env;

/**
 * Keeps every {@see HasLastModified} entity's `lastmodified` column on
 * `Env::now()` (test-frozen-aware) rather than the DB server's own real
 * wall clock. Registered on every `EntityManagerFactory::build()`-produced
 * `EntityManager`, see that class.
 *
 * `preUpdate` mutating the entity directly (not
 * `PreUpdateEventArgs::setNewValue()`) works here because
 * `UnitOfWork::executeUpdates()` calls `recomputeSingleEntityChangeSet()`
 * immediately after invoking `preUpdate` listeners -- confirmed against
 * `vendor/doctrine/orm/src/UnitOfWork.php` and empirically proven live
 * against this codebase's real Doctrine setup before this class was
 * written.
 */
final class LastModifiedListener
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->touch($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->touch($args->getObject());
    }

    private function touch(object $entity): void
    {
        if ($entity instanceof HasLastModified) {
            $entity->touchLastModified(SqlDateTime::fromDateTime(Env::now()));
        }
    }
}
