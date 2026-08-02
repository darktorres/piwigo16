<?php

declare(strict_types=1);

namespace Piwigo\Event\Lifecycle;

/**
 * Typed marker event for the legacy `loading_lang` notification. No
 * payload, no handler registered anywhere today.
 */
final readonly class LoadingLang {}
