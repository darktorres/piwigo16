<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/db/84-database.php (P23 sub-batch 8g-1).
 */
final class Patch84 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '84';
    }

    #[\Override]
    public function description(): string
    {
        return 'Update default template to default theme.';
    }

    #[\Override]
    public function apply(): void
    {
        $query = '
ALTER TABLE ' . Tables::userInfos() . '
  CHANGE COLUMN template theme varchar(255) NOT NULL default \'Sylvia\'
;';

        MysqliDb::query($query);

        $query = '
SELECT user_id, theme
  FROM ' . Tables::userInfos() . '
;';

        $result = MysqliDb::query($query);

        $users = [];
        while ($row = MysqliDb::fetchAssoc($result)) {
            [$user_template, $user_theme] = explode('/', (string) $row['theme']);

            switch ($user_template) {
                case 'yoga':
                    break;

                case 'gally':
                    $user_theme = 'gally-' . $user_theme;
                    break;

                case 'floPure':
                    $user_theme = 'Pure_' . $user_theme;
                    break;

                case 'floOs':
                    $user_theme = 'OS_' . $user_theme;
                    break;

                case 'simple':
                    $user_theme = 'simple-' . $user_theme;
                    break;

                default:
                    $user_theme = 'Sylvia';
            }

            array_push(
                $users,
                [
                    'user_id' => $row['user_id'],
                    'theme' => $user_theme,
                ]
            );
        }

        MysqliDb::massUpdates(
            Tables::userInfos(),
            [
                'primary' => ['user_id'],
                'update' => ['theme'],
            ],
            $users
        );

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
