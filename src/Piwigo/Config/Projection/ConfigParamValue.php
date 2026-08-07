<?php

declare(strict_types=1);

namespace Piwigo\Config\Projection;

/**
 * {@see \Piwigo\Config\ConfigRepository::findParamsAndValuesLike()}'s own
 * param/value row shape -- {@see \Piwigo\Controller\Admin\
 * NotificationByMailSubController}'s real (and only) consumer, via
 * {@see \Piwigo\Config\ConfigService::getParamsAndValuesLike()}.
 */
final readonly class ConfigParamValue
{
    public function __construct(
        public string $param,
        public ?string $value,
    ) {}
}
