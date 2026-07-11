<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Doctrine\ORM\EntityRepository;

/**
 * `find()`/`findAll()` are inherited from EntityRepository for free --
 * `find()` resolves by primary key (param), which covers the only real
 * non-trivial call site found across the current codebase
 * (`load_conf_from_db('param = "..."', ...)`, a single-param filter).
 * Every other real call site loads everything (no condition).
 *
 * @extends EntityRepository<ConfigEntry>
 */
final class ConfigRepository extends EntityRepository
{
    /**
     * Insert or update a single param/value/comment row.
     */
    public function upsert(string $param, ?string $value, ?string $comment = null): void
    {
        $em = $this->getEntityManager();
        $entry = $this->find($param);

        if ($entry === null) {
            $em->persist(new ConfigEntry($param, $value, $comment));
        } else {
            $entry->value = $value;
            if ($comment !== null) {
                $entry->comment = $comment;
            }
        }

        $em->flush();
    }

    public function deleteByParam(string $param): void
    {
        $entry = $this->find($param);
        if ($entry === null) {
            return;
        }

        $em = $this->getEntityManager();
        $em->remove($entry);
        $em->flush();
    }
}
