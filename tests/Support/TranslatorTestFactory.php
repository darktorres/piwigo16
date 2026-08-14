<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Lang\Translator;

/**
 * Returns the real container-shared Translator instance once Kernel has
 * booted, or a memoized (not fresh-per-call) fallback instance otherwise.
 * Translator is "load once, read many times": load() populates
 * $inner/$mirror, and translate()/plural() read them back, so a fresh
 * instance per call would silently lose every loaded PO file between calls.
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

        return self::$fallback ??= new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations')));
    }
}
