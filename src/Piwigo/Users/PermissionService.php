<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Db\SqlExpr;
use Piwigo\Db\SqlFragment;
use Piwigo\Filter\FilterContextRegistry;
use Piwigo\Html\HtmlService;
use Piwigo\Permission\PermissionRepository;

final readonly class PermissionService
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private PermissionRepository $permissionRepository,
        private HtmlService $htmlService,
    ) {
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
            'guest'     => Config::guestAccess() ? AccessLevel::Guest : AccessLevel::Free,
            'generic'   => AccessLevel::Guest,
            'normal'    => AccessLevel::Classic,
            'admin'     => AccessLevel::Administrator,
            'webmaster' => AccessLevel::Webmaster,
            default     => AccessLevel::Free,
        };
    }

    public function isAutorizeStatus(int $accessType, string $userStatus = ''): bool
    {
        return ($this->getAccessTypeStatus($userStatus) >= $accessType);
    }

    public function checkStatus(int $accessType): void
    {
        if (!$this->isAutorizeStatus($accessType)) {
            $this->htmlService->accessDenied();
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
        return $this->isAutorizeStatus(AccessLevel::Classic, $userStatus);
    }

    public function isAdmin(string $userStatus = ''): bool
    {
        return $this->isAutorizeStatus(AccessLevel::Administrator, $userStatus);
    }

    public function isWebmaster(string $userStatus = ''): bool
    {
        return $this->isAutorizeStatus(AccessLevel::Webmaster, $userStatus);
    }

    public function canManageComment(string $action, int $commentAuthorId): bool
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

    /** @return list<int> category ids the user is not allowed to see (always non-empty; placeholder 0 if user has full access) */
    public function calculatePermissions(int $userId, string $userStatus): array
    {
        $privateIds    = $this->categoryRepository->findPrivateIds();
        $authorizedIds = array_merge(
            $this->permissionRepository->findCatIdsByUserAccess($userId),
            $this->permissionRepository->findCatIdsByUserGroupAccess($userId),
        );

        $forbiddenIds = array_diff($privateIds, $authorizedIds);

        if (!$this->isAdmin($userStatus)) {
            $forbiddenIds = array_unique(array_merge($forbiddenIds, $this->categoryRepository->findLockedIds()));
        }

        return $forbiddenIds === [] ? [0] : array_values($forbiddenIds);
    }

    /**
     * Build a parameterized WHERE-fragment from the current user's permission state.
     *
     * The returned fragment uses positional `?` placeholders; its `params`
     * and `types` align by index. Each caller appends the fragment's
     * `where` to its query string and `params`/`types` to the
     * executeQuery bound-parameter arrays.
     *
     * @param array<string,string> $conditionFields condition-name → DB column expression
     */
    public function getSqlConditionFandF(array $conditionFields, ?string $prefixCondition = null, bool $forceOneCondition = false): SqlFragment
    {
        $filter = FilterContextRegistry::current();
        $user   = CurrentUser::get()->rawAttributes;

        $asIntArray = static function (mixed $v): array {
            if (!is_array($v)) {
                return [];
            }
            $out = [];
            foreach ($v as $item) {
                if (is_int($item)) {
                    $out[] = $item;
                } elseif (is_numeric($item)) {
                    $out[] = (int) $item;
                }
            }
            return $out;
        };

        $clauses = [];
        $params  = [];
        $types   = [];

        foreach ($conditionFields as $condition => $fieldName) {
            switch ($condition) {
                case 'forbidden_categories':
                    $ids = $asIntArray($user['forbidden_categories'] ?? null);
                    if ($ids !== []) {
                        $clauses[] = $fieldName . ' NOT IN (?)';
                        $params[]  = $ids;
                        $types[]   = ArrayParameterType::INTEGER;
                    }
                    break;
                case 'visible_categories':
                    $ids = $asIntArray($filter->visibleCategories);
                    if ($ids !== []) {
                        $clauses[] = $fieldName . ' IN (?)';
                        $params[]  = $ids;
                        $types[]   = ArrayParameterType::INTEGER;
                    }
                    break;
                case 'visible_images':
                    $ids = $asIntArray($filter->visibleImages);
                    if ($ids !== []) {
                        $clauses[] = $fieldName . ' IN (?)';
                        $params[]  = $ids;
                        $types[]   = ArrayParameterType::INTEGER;
                    }
                    // no break — visible includes forbidden
                case 'forbidden_images':
                    if (!empty($user['image_access_list']) or ($user['image_access_type'] ?? null) != 'NOT IN') {
                        $tablePrefix = null;
                        if ($fieldName === 'id') {
                            $tablePrefix = '';
                        } elseif ($fieldName === 'i.id') {
                            $tablePrefix = 'i.';
                        }
                        if (isset($tablePrefix)) {
                            $clauses[] = $tablePrefix . 'level <= ?';
                            $params[]  = is_numeric($user['level'] ?? null) ? (int) $user['level'] : 0;
                            $types[]   = ParameterType::INTEGER;
                        } elseif (!empty($user['image_access_list']) and !empty($user['image_access_type'])) {
                            $accessIds = $asIntArray($user['image_access_list']);
                            $op = (is_string($user['image_access_type']) && $user['image_access_type'] === 'IN') ? 'IN' : 'NOT IN';
                            if ($accessIds !== []) {
                                $clauses[] = $fieldName . ' ' . $op . ' (?)';
                                $params[]  = $accessIds;
                                $types[]   = ArrayParameterType::INTEGER;
                            }
                        }
                    }
                    break;
                default:
                    throw new \LogicException('Unknown condition');
            }
        }

        if ($clauses !== []) {
            $sql = '(' . implode(' AND ', $clauses) . ')';
        } else {
            $sql = $forceOneCondition ? '1 = 1' : '';
        }

        if ($prefixCondition !== null && $sql !== '') {
            $sql = $prefixCondition . ' ' . $sql;
        }

        return new SqlFragment($sql, $params, $types);
    }

    public function getRecentPhotosSql(string $dbField): string
    {
        $user = CurrentUser::get()->rawAttributes;
        if (!isset($user['last_photo_date'])) {
            return '0=1';
        }
        $recentPeriodRaw = $user['recent_period'] ?? null;
        $recentPeriod  = is_numeric($recentPeriodRaw) ? (int) $recentPeriodRaw : 0;
        $lastPhotoDate = is_string($user['last_photo_date']) ? $user['last_photo_date'] : '';
        return $dbField . '>=LEAST('
          . SqlExpr::recentPeriodExpr($recentPeriod)
          . ',' . SqlExpr::recentPeriodExpr(1, $lastPhotoDate) . ')';
    }
}
