<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\SqlExpr;

final readonly class PermissionService
{
    public function __construct(
        private Connection $conn,
    ) {
    }

    public static function get(): self
    {
        return ServiceLocator::get(self::class);
    }

    public function getUserStatus(string $userStatus = ''): string
    {
        if (empty($userStatus)) {
            if (CurrentUser::isInitialized()) {
                $userStatus = CurrentUser::get()->status;
            } else {
                $userStatus = '';
            }
        }
        return $userStatus;
    }

    public function getAccessTypeStatus(string $userStatus = ''): int
    {
        return match ($this->getUserStatus($userStatus)) {
            'guest'     => Config::guestAccess() ? ACCESS_GUEST : ACCESS_FREE,
            'generic'   => ACCESS_GUEST,
            'normal'    => ACCESS_CLASSIC,
            'admin'     => ACCESS_ADMINISTRATOR,
            'webmaster' => ACCESS_WEBMASTER,
            default     => ACCESS_FREE,
        };
    }

    public function isAutorizeStatus(int $accessType, string $userStatus = ''): bool
    {
        return ($this->getAccessTypeStatus($userStatus) >= $accessType);
    }

    public function checkStatus(int $accessType, string $userStatus = ''): void
    {
        if (!$this->isAutorizeStatus($accessType, $userStatus)) {
            access_denied();
        }
    }

    public function isGeneric(string $userStatus = ''): bool
    {
        return $this->getUserStatus($userStatus) == 'generic';
    }

    public function isAGuest(string $userStatus = ''): bool
    {
        return $this->getUserStatus($userStatus) == 'guest';
    }

    public function isClassicUser(string $userStatus = ''): bool
    {
        return $this->isAutorizeStatus(ACCESS_CLASSIC, $userStatus);
    }

    public function isAdmin(string $userStatus = ''): bool
    {
        return $this->isAutorizeStatus(ACCESS_ADMINISTRATOR, $userStatus);
    }

    public function isWebmaster(string $userStatus = ''): bool
    {
        return $this->isAutorizeStatus(ACCESS_WEBMASTER, $userStatus);
    }

    public function canManageComment(string $action, mixed $commentAuthorId): bool
    {
        if ($this->isAGuest()) {
            return false;
        }

        if (!in_array($action, ['delete', 'edit', 'validate'])) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        $currentUserId = CurrentUser::get()->id;

        if ('edit' == $action and Config::userCanEditComment()) {
            if ($commentAuthorId == $currentUserId) {
                return true;
            }
        }

        if ('delete' == $action and Config::userCanDeleteComment()) {
            if ($commentAuthorId == $currentUserId) {
                return true;
            }
        }

        return false;
    }

    public function calculatePermissions(int $userId, string $userStatus): string
    {
        $privateArray = array_column($this->conn->executeQuery('SELECT id FROM ' . CATEGORIES_TABLE . ' WHERE status = \'private\';')->fetchAllAssociative(), 'id');

        $authorizedArray = array_column($this->conn->executeQuery('SELECT cat_id FROM ' . USER_ACCESS_TABLE . ' WHERE user_id = ' . $userId . ';')->fetchAllAssociative(), 'cat_id');

        $authorizedArray = array_merge(
            $authorizedArray,
            array_column($this->conn->executeQuery('SELECT cat_id FROM ' . USER_GROUP_TABLE . ' AS ug INNER JOIN ' . GROUP_ACCESS_TABLE . ' AS ga ON ug.group_id = ga.group_id WHERE ug.user_id = ' . $userId . ';')->fetchAllAssociative(), 'cat_id')
        );

        $authorizedArray = array_unique(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $authorizedArray));
        $forbiddenArray  = array_diff(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $privateArray), $authorizedArray);

        if (!$this->isAdmin($userStatus)) {
            $forbiddenArray = array_merge($forbiddenArray, array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column($this->conn->executeQuery('SELECT id FROM ' . CATEGORIES_TABLE . ' WHERE visible = \'false\';')->fetchAllAssociative(), 'id')));
            $forbiddenArray = array_unique($forbiddenArray);
        }

        if (empty($forbiddenArray)) {
            $forbiddenArray[] = 0;
        }

        return implode(',', $forbiddenArray);
    }

    /** @param array<string,string> $conditionFields */
    public function getSqlConditionFandF(array $conditionFields, ?string $prefixCondition = null, bool $forceOneCondition = false): string
    {
        $filter = is_array($GLOBALS['filter'] ?? null) ? $GLOBALS['filter'] : [];
        $user   = CurrentUser::get()->rawAttributes;

        $sqlList = [];

        foreach ($conditionFields as $condition => $fieldName) {
            switch ($condition) {
                case 'forbidden_categories':
                    if (!empty($user['forbidden_categories'])) {
                        $sqlList[] = $fieldName . ' NOT IN (' . (is_scalar($user['forbidden_categories']) ? (string) $user['forbidden_categories'] : '') . ')';
                    }
                    break;
                case 'visible_categories':
                    $visibleCategories = is_scalar($filter['visible_categories'] ?? null) ? (string) $filter['visible_categories'] : '';
                    if ($visibleCategories !== '') {
                        $sqlList[] = $fieldName . ' IN (' . $visibleCategories . ')';
                    }
                    break;
                case 'visible_images':
                    $visibleImages = is_scalar($filter['visible_images'] ?? null) ? (string) $filter['visible_images'] : '';
                    if ($visibleImages !== '') {
                        $sqlList[] = $fieldName . ' IN (' . $visibleImages . ')';
                    }
                    // no break — visible includes forbidden
                case 'forbidden_images':
                    if (!empty($user['image_access_list']) or ($user['image_access_type'] ?? null) != 'NOT IN') {
                        $tablePrefix = null;
                        if ($fieldName == 'id') {
                            $tablePrefix = '';
                        } elseif ($fieldName == 'i.id') {
                            $tablePrefix = 'i.';
                        }
                        if (isset($tablePrefix)) {
                            $sqlList[] = $tablePrefix . 'level<=' . (is_scalar($user['level'] ?? null) ? (string) $user['level'] : '0');
                        } elseif (!empty($user['image_access_list']) and !empty($user['image_access_type'])) {
                            $sqlList[] = $fieldName . ' ' . (is_scalar($user['image_access_type']) ? (string) $user['image_access_type'] : '')
                                . ' (' . (is_scalar($user['image_access_list']) ? (string) $user['image_access_list'] : '') . ')';
                        }
                    }
                    break;
                default:
                    throw new \LogicException('Unknown condition');
            }
        }

        if (count($sqlList) > 0) {
            $sql = '(' . implode(' AND ', $sqlList) . ')';
        } else {
            $sql = $forceOneCondition ? '1 = 1' : '';
        }

        if (isset($prefixCondition) and !empty($sql)) {
            $sql = $prefixCondition . ' ' . $sql;
        }

        return $sql;
    }

    public function getRecentPhotosSql(string $dbField): string
    {
        $user = CurrentUser::get()->rawAttributes;
        if (!isset($user['last_photo_date'])) {
            return '0=1';
        }
        $recentPeriod  = is_numeric($user['recent_period'] ?? null) ? (int) $user['recent_period'] : 0;
        $lastPhotoDate = is_scalar($user['last_photo_date']) ? (string) $user['last_photo_date'] : '';
        return $dbField . '>=LEAST('
          . SqlExpr::recentPeriodExpr($recentPeriod)
          . ',' . SqlExpr::recentPeriodExpr(1, $lastPhotoDate) . ')';
    }
}
