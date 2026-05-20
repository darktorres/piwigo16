<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Category\CategoryRepository;
use Piwigo\Comment\CommentManagementAction;
use Piwigo\Common\Enum\UserStatus;
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

    public function getUserStatus(?UserStatus $userStatus = null): ?UserStatus
    {
        if ($userStatus !== null) {
            return $userStatus;
        }
        return CurrentUser::isInitialized() ? CurrentUser::get()->status : null;
    }

    public function getAccessTypeStatus(?UserStatus $userStatus = null): int
    {
        return match ($this->getUserStatus($userStatus)) {
            UserStatus::Guest     => Config::guestAccess() ? AccessLevel::Guest : AccessLevel::Free,
            UserStatus::Generic   => AccessLevel::Guest,
            UserStatus::Normal    => AccessLevel::Classic,
            UserStatus::Admin     => AccessLevel::Administrator,
            UserStatus::Webmaster => AccessLevel::Webmaster,
            default               => AccessLevel::Free,
        };
    }

    public function isAutorizeStatus(int $accessType, ?UserStatus $userStatus = null): bool
    {
        return ($this->getAccessTypeStatus($userStatus) >= $accessType);
    }

    public function checkStatus(int $accessType): void
    {
        if (!$this->isAutorizeStatus($accessType)) {
            $this->htmlService->accessDenied();
        }
    }

    public function isGeneric(?UserStatus $userStatus = null): bool
    {
        return $this->getUserStatus($userStatus) === UserStatus::Generic;
    }

    public function isAGuest(?UserStatus $userStatus = null): bool
    {
        return $this->getUserStatus($userStatus) === UserStatus::Guest;
    }

    public function isClassicUser(?UserStatus $userStatus = null): bool
    {
        return $this->isAutorizeStatus(AccessLevel::Classic, $userStatus);
    }

    public function isAdmin(?UserStatus $userStatus = null): bool
    {
        return $this->isAutorizeStatus(AccessLevel::Administrator, $userStatus);
    }

    public function isWebmaster(?UserStatus $userStatus = null): bool
    {
        return $this->isAutorizeStatus(AccessLevel::Webmaster, $userStatus);
    }

    public function canManageComment(CommentManagementAction $action, int $commentAuthorId): bool
    {
        if ($this->isAGuest()) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        $currentUserId = CurrentUser::get()->id;

        if ($action === CommentManagementAction::Edit and Config::userCanEditComment()) {
            if ($commentAuthorId == $currentUserId) {
                return true;
            }
        }

        if ($action === CommentManagementAction::Delete and Config::userCanDeleteComment()) {
            if ($commentAuthorId == $currentUserId) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> category ids the user is not allowed to see (always non-empty; placeholder 0 if user has full access) */
    public function calculatePermissions(int $userId, UserStatus $userStatus): array
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

        $clauses = [];
        $params  = [];
        $types   = [];

        foreach ($conditionFields as $condition => $fieldName) {
            switch ($condition) {
                case 'forbidden_categories':
                    $ids = $this->asIntArray($user['forbidden_categories'] ?? null);
                    if ($ids !== []) {
                        $clauses[] = $fieldName . ' NOT IN (?)';
                        $params[]  = $ids;
                        $types[]   = ArrayParameterType::INTEGER;
                    }
                    break;
                case 'visible_categories':
                    $ids = $this->asIntArray($filter->visibleCategories);
                    if ($ids !== []) {
                        $clauses[] = $fieldName . ' IN (?)';
                        $params[]  = $ids;
                        $types[]   = ArrayParameterType::INTEGER;
                    }
                    break;
                case 'visible_images':
                    $ids = $this->asIntArray($filter->visibleImages);
                    if ($ids !== []) {
                        $clauses[] = $fieldName . ' IN (?)';
                        $params[]  = $ids;
                        $types[]   = ArrayParameterType::INTEGER;
                    }
                    // no break — visible includes forbidden
                case 'forbidden_images':
                    $frag = $this->buildImageAccessClauses($fieldName, $user);
                    if ($frag->where !== '') {
                        $clauses[] = $frag->where;
                    }
                    array_push($params, ...$frag->params);
                    array_push($types, ...$frag->types);
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

    /** @return list<int> */
    private function asIntArray(mixed $v): array
    {
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
    }

    /**
     * Builds the WHERE fragment for the forbidden_images / visible_images access check.
     *
     * @param array<array-key, mixed> $user
     */
    private function buildImageAccessClauses(string $fieldName, array $user): SqlFragment
    {
        if (empty($user['image_access_list']) && ($user['image_access_type'] ?? null) === 'NOT IN') {
            return new SqlFragment('');
        }

        $tablePrefix = match ($fieldName) {
            'id'   => '',
            'i.id' => 'i.',
            default => null,
        };

        if ($tablePrefix !== null) {
            return new SqlFragment(
                $tablePrefix . 'level <= ?',
                [is_numeric($user['level'] ?? null) ? (int) $user['level'] : 0],
                [ParameterType::INTEGER],
            );
        }

        if (!empty($user['image_access_list']) && !empty($user['image_access_type'])) {
            $accessIds = $this->asIntArray($user['image_access_list']);
            $op        = (is_string($user['image_access_type']) && $user['image_access_type'] === 'IN') ? 'IN' : 'NOT IN';
            if ($accessIds !== []) {
                return new SqlFragment(
                    $fieldName . ' ' . $op . ' (?)',
                    [$accessIds],
                    [ArrayParameterType::INTEGER],
                );
            }
        }

        return new SqlFragment('');
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
