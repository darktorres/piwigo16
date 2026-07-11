<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * Recent-post-date limits for RSS and NBM notification channels.
 */
final readonly class NotificationConfig
{
    public function __construct(
        public NotificationChannelConfig $rss,
        public NotificationChannelConfig $nbm,
    ) {}
}
