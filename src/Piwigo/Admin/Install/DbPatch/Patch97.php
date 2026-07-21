<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Doctrine\DBAL\Connection;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;

/**
 * Former install/db/97-database.php (P23 sub-batch 8g-1). default_user_id
 * is read via LegacyFileConf::read() -- Config::defaultUserId() doesn't
 * see a site's local/config/config.inc.php override on this path (same
 * reasoning as the rest of this file family).
 */
final class Patch97 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '97';
    }

    #[\Override]
    public function description(): string
    {
        return 'makes sure default user has a theme and a language';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $localConf = LegacyFileConf::read();
        $defaultUserId = is_scalar($localConf['default_user_id'] ?? null) ? $localConf['default_user_id'] : 2;

        $query = '
SELECT
    theme,
    language
  FROM ' . Tables::userInfos() . '
  WHERE user_id = :userId
;';
        $row = $conn->fetchNumeric($query, [
            'userId' => $defaultUserId,
        ]);
        $theme = $row !== false && is_scalar($row[0]) ? (string) $row[0] : '';
        $language = $row !== false && is_scalar($row[1]) ? (string) $row[1] : '';

        $data = [
            'user_id' => $defaultUserId,
            'theme' => empty($theme) ? 'Sylvia' : $theme,
            'language' => empty($language) ? 'en_UK' : $language,
        ];

        new BatchWriter($conn)
            ->massUpdate(
                Tables::userInfos(),
                [
                    'primary' => ['user_id'],
                    'update' => ['theme', 'language'],
                ],
                [
                    $data,
                ]
            );

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
