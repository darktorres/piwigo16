<?php

declare(strict_types=1);

namespace Piwigo\Lang;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for LangService's own single read: the `languages`
 * table backing getLanguages()'s installed-language list.
 */
final class LangRepository extends AbstractRepository
{
    /**
     * @return list<array{id: string, name: string}>
     */
    public function findAll(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'name')
            ->from(Tables::languages())
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            if (is_string($row['id']) && is_string($row['name'])) {
                $result[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                ];
            }
        }

        return $result;
    }
}
