<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Lang\Translator;

/**
 * Singleton/service-locator elimination campaign, Phase 12 sub-phase 12F-6:
 * replaces the deleted `Translator::get()` transitional shim for test call
 * sites -- reproduces its exact behavior (the real container-shared
 * instance once Kernel has booted, a MEMOIZED, not fresh-per-call,
 * instance otherwise). Translator is "load once, read many times" --
 * load() populates $inner/$mirror, translate()/plural() read them back --
 * so a fresh instance per call would silently lose every loaded PO file
 * between calls.
 */
final class TranslatorTestFactory
{
    private static ?Translator $fallback = null;

    public static function get(): Translator
    {
        if (Kernel::isBooted()) {
            $translator = Kernel::container()->get(Translator::class);
            if (! $translator instanceof Translator) {
                throw new LogicException('Container returned an unexpected type for ' . Translator::class);
            }

            return $translator;
        }

        return self::$fallback ??= new Translator(new CurrentConfig());
    }
}
