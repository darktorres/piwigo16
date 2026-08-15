<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\PluginConfig;

/**
 * Stand-in event class for ExtensionInterfaceTestFakePlugin's
 * subscribedEvents() map -- carries a single flag so the test can prove the
 * subscribed handler actually ran against the instance it was handed.
 */
final class ExtensionInterfaceTestFakeEvent
{
    public bool $touched = false;
}
