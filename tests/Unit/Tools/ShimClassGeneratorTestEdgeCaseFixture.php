<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Tools;

/**
 * A real static method the generator can reflect for one specific
 * docblock-copying edge case (`@return list<string>` -- native PHP has
 * no way to express "a list of strings" in a return type, so the
 * generator falls back to copying the docblock line verbatim). Kept
 * separate from `PiwigoExtension` itself: this shape doesn't need to be
 * a real, currently-registered filter/function to be worth the
 * generator handling correctly.
 */
final class ShimClassGeneratorTestEdgeCaseFixture
{
    /**
     * @return list<string>
     */
    public static function explode(string $text, string $delimiter = ','): array
    {
        return explode($delimiter !== '' ? $delimiter : ',', $text);
    }

    /**
     * Same shape `Template::combineScript()` used to have (multiple
     * optional string|null params plus a `string|false` one) -- kept
     * here as a dedicated fixture once combineScript() itself was
     * deleted (P42's own final step, docs/PLAN.md), since the generator
     * itself must keep handling this real-default-value shape correctly
     * whether or not any *current* registration happens to exhibit it.
     */
    public static function withDefaults(string $id, ?string $load = null, ?string $require = null, ?string $path = null, string|false $version = '0'): void {}
}
