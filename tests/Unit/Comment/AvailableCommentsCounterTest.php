<?php

declare(strict_types=1);

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Comment\AvailableCommentsCounter;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Comment\AvailableCommentsCounter -- extracted from
 * CommentService::getNbAvailableComments(), needing only 3 of its 11
 * collaborators. No dedicated Integration/Browser spec of its own.
 *
 * `count()` memoizes onto `CurrentUser::rawAttributes['nb_available_comments']`
 * -- covered here both ways: a pre-set memoized value is returned
 * directly with zero DB access (proven via a deliberately unrealistic
 * sentinel int a real count query would never produce), and a real
 * cache-miss computes a real value via a real DQL count query and
 * writes it back onto the CurrentUser for the next call.
 */
function availableCommentsCounterTestSubject(CurrentUser $currentUser, CurrentConfig $currentConfig): AvailableCommentsCounter
{
    return new AvailableCommentsCounter($currentUser, new AccessLevelChecker($currentUser, $currentConfig));
}

function availableCommentsCounterTestPermissionService(CurrentUser $currentUser, CurrentConfig $currentConfig): PermissionService
{
    $conn = DbConnection::build();

    return new PermissionService(
        new PermissionRepository(EntityManagerFactory::build($conn)),
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig),
        $currentUser,
        new FilterState(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

test('count returns the memoized value directly, without recomputing it', function (): void {
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Admin,
        enabledHigh: false,
        rawAttributes: [
            'nb_available_comments' => 987654,
        ],
    ));
    $counter = availableCommentsCounterTestSubject($currentUser, $currentConfig);

    $result = $counter->count(availableCommentsCounterTestPermissionService($currentUser, $currentConfig), EntityManagerFactory::build(DbConnection::build()));

    expect($result)
        ->toBe(987654);
});

test('count computes a real value on a cache miss and memoizes it back onto the CurrentUser', function (): void {
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(999999),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Admin,
        enabledHigh: false,
    ));
    $counter = availableCommentsCounterTestSubject($currentUser, $currentConfig);

    $result = $counter->count(availableCommentsCounterTestPermissionService($currentUser, $currentConfig), EntityManagerFactory::build(DbConnection::build()));

    expect($result)
        ->toBeGreaterThanOrEqual(0)
        ->and($currentUser->get()->rawAttributes['nb_available_comments'] ?? null)->toBe($result);
});
