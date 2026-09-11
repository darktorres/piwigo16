<?php

declare(strict_types=1);

namespace Piwigo\Lang\Event;

/**
 * Typed marker event for the legacy `loading_lang` notification. No
 * payload. `Piwigo\PluginConfig\PluginRegistry::onLoadingLang()` (P29.6)
 * is the one real handler -- see that method's own docblock for why
 * plugin `.po` loading has to wait for this event rather than happening
 * inline during `bootActive()`.
 */
final readonly class LoadingLang {}
