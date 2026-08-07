<?php

declare(strict_types=1);

namespace Piwigo\Lang;

use Doctrine\ORM\EntityRepository;

/**
 * Persistence layer for LangService's own single read: the `languages`
 * table backing getLanguages()'s installed-language list.
 *
 * @extends EntityRepository<LanguageEntity>
 */
final class LangRepository extends EntityRepository
{
    /**
     * Named findAllRows(), not findAll() -- EntityRepository::findAll()
     * returns list<LanguageEntity>, an incompatible override this class's
     * own array-of-rows shape can't satisfy.
     *
     * @return list<array{id: string, name: string}>
     */
    public function findAllRows(): array
    {
        $entities = $this->createQueryBuilder('l')
            ->orderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($entities as $entity) {
            // Matches the pre-ORM DBAL read's own is_string($row['name'])
            // filter -- `name` is nullable in the schema, and a row with
            // no name was never surfaced to callers.
            if ($entity->name !== null) {
                $result[] = [
                    'id' => $entity->id->value,
                    'name' => $entity->name,
                ];
            }
        }

        return $result;
    }
}
