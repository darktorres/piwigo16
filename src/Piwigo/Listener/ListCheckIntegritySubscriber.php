<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Admin\Integrity\C13yInternal;
use Piwigo\Event\Picture\ListCheckIntegrity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Core integrity checks (PHP/MySQL version, EXIF availability, mandatory
 * user accounts) run on every CheckIntegrity invocation. The listener
 * side-effects on the CheckIntegrity subject by calling $c13y->addAnomaly().
 *
 * Legacy v16 wired three separate addListener calls in C13yInternal::__construct
 * which created the registration on every new C13yInternal — effectively
 * relying on the singleton lifetime of the legacy dispatcher. Replaced
 * here with one boot-time subscriber that dispatches to all three checks
 * sequentially.
 */
final readonly class ListCheckIntegritySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private C13yInternal $c13yInternal,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [ListCheckIntegrity::class => 'onListCheckIntegrity'];
    }

    public function onListCheckIntegrity(ListCheckIntegrity $event): void
    {
        $this->c13yInternal->c13yVersion($event->value);
        $this->c13yInternal->c13yExif($event->value);
        $this->c13yInternal->c13yUser($event->value);
    }
}
