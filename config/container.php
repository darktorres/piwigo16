<?php

declare(strict_types=1);

use function DI\factory;

use Piwigo\Config\Config;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Storage\StorageRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

return [
    Config::class          => factory(fn () => Config::instance()),
    PageState::class       => factory(fn () => PageState::current()),
    LoggerInterface::class => factory(
        fn () => LoggerRegistry::isInitialized() ? LoggerRegistry::current() : new NullLogger()
    ),
    StorageRegistry::class => factory(static function (): StorageRegistry {
        $registry = StorageRegistry::fromConfig(PHPWG_ROOT_PATH . 'config/storage.php');
        StorageRegistry::setInstance($registry);
        return $registry;
    }),
];
