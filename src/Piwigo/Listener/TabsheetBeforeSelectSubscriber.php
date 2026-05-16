<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Admin\CoreTabsRegistrar;
use Piwigo\Event\Admin\TabsheetBeforeSelect;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Registers Piwigo's built-in admin tabs (Album, Photos, Tags, …) before
 * any plugin/theme tabs land. Replaces the legacy AdminController-time
 * registration at priority 0.
 */
final readonly class TabsheetBeforeSelectSubscriber implements EventSubscriberInterface
{
    /**
     * Priority 0 reproduces the legacy AdminController registration. Plugins
     * default to NEUTRAL_PRIORITY (10), so their tabs apply after core's.
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [TabsheetBeforeSelect::class => ['onTabsheetBeforeSelect', 0]];
    }

    public function onTabsheetBeforeSelect(TabsheetBeforeSelect $event): void
    {
        $event->sheets = CoreTabsRegistrar::addCoreTabs($event->sheets, $event->tabsheetId);
    }
}
