<?php

declare(strict_types=1);

// One-shot P23 batch 8d codemod: retargets every real call site of the
// ~55 include/functions_user.inc.php free functions (minus the handful
// handled by hand -- register_user()'s wrapper logic, pwg_login()'s event
// registration, and the 0-external-caller functions) onto their new real
// class method. See docs: user's explicit authorization for a narrowly-
// scoped Rector codemod on pure, uniform, mechanical renames at this
// scale (04-batch8-cdef-combined.md, "no scripted mass rewrites" exception).
// Discarded after this migration lands -- the diff is committed, not this
// script (project convention: one-shot backfills as throwaway scripts).

require_once __DIR__ . '/rector-rules/FuncCallToNewMethodCallRector.php';

use Rector\Config\RectorConfig;
use Rector\Transform\Rector\FuncCall\FuncCallToStaticCallRector;
use Rector\Transform\ValueObject\FuncCallToStaticCall;
use Utils\Rector\FuncCallToNewMethodCallRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/..',
    ])
    ->withSkip([
        __DIR__ . '/../_data',
        __DIR__ . '/../galleries',
        __DIR__ . '/../install/db',
        __DIR__ . '/../language',
        __DIR__ . '/../local',
        __DIR__ . '/../node_modules',
        __DIR__ . '/../vendor',
        // being deleted outright in this same fold -- its own function
        // definitions must not be rewritten into calls to themselves
        __DIR__ . '/../include/functions_user.inc.php',
    ])
    ->withRules([
        FuncCallToNewMethodCallRector::class,
    ])
    ->withConfiguredRule(FuncCallToStaticCallRector::class, [
        new FuncCallToStaticCall('check_status', 'Piwigo\Auth\AccessControl', 'checkStatus'),
        new FuncCallToStaticCall('is_a_guest', 'Piwigo\Auth\AccessControl', 'isAGuest'),
        new FuncCallToStaticCall('is_admin', 'Piwigo\Auth\AccessControl', 'isAdmin'),
        new FuncCallToStaticCall('is_webmaster', 'Piwigo\Auth\AccessControl', 'isWebmaster'),
        new FuncCallToStaticCall('is_generic', 'Piwigo\Auth\AccessControl', 'isGeneric'),
        new FuncCallToStaticCall('is_classic_user', 'Piwigo\Auth\AccessControl', 'isClassicUser'),
        new FuncCallToStaticCall('is_autorize_status', 'Piwigo\Auth\AccessControl', 'isAuthorizeStatus'),
        new FuncCallToStaticCall('can_manage_comment', 'Piwigo\Auth\AccessControl', 'canManageComment'),
        new FuncCallToStaticCall('generate_user_code', 'Piwigo\Auth\AuthService', 'generateUserCode'),
        new FuncCallToStaticCall('verify_user_code', 'Piwigo\Auth\AuthService', 'verifyUserCode'),
    ])
    ->withPhpVersion(\Rector\ValueObject\PhpVersion::PHP_85)
    ->withParallel(timeoutSeconds: 300);
