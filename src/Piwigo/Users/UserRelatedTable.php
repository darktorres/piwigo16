<?php

declare(strict_types=1);

namespace Piwigo\Users;

/**
 * {@see UserRepository::findDistinctUserIdsInMappedTable()}/
 * {@see UserRepository::deleteUsersFromMappedTable()}'s target, enumerated
 * -- {@see UserService::syncUsers()}'s own fixed 5-table list has exactly
 * 3 tables this repository may reach via DQL ({@see UserInfoEntity}/
 * {@see \Piwigo\Category\UserAccessEntity}/{@see \Piwigo\Group\UserGroupEntity},
 * all `L2aCoreDomain`-or-same-layer). The other 2
 * (`user_mail_notification`/`user_feed`, both `L2bExtendedDomain`) are a
 * real `deleteSiteRow`-class deptrac boundary `Users` (`L2aCoreDomain`)
 * cannot cross via DQL regardless of whether those tables get their own
 * entities elsewhere -- {@see UserRepository::findDistinctUserIdsInTable()}/
 * {@see UserRepository::deleteUsersFromTable()} stay on raw-table-name
 * DBAL permanently for those 2.
 */
enum UserRelatedTable
{
    case UserInfos;
    case UserAccess;
    case UserGroup;
}
