<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Section;

use PHPUnit\Framework\TestCase;
use Piwigo\Section\SectionPopulator;
use ReflectionMethod;

/**
 * SectionPopulator::emptyValue() -- the private empty()-semantics helper
 * backing needsPermalinkRedirect(). Its only real caller passes a ?string,
 * so the 0/0.0/false/[] branches of its own mixed-input contract are
 * unreachable through the public API; exercised directly via reflection
 * instead, matching this project's established convention for testing
 * private static helpers (e.g. Piwigo\Core\LangTest's getParentLanguage()
 * coverage).
 */
final class SectionPopulatorEmptyValueTest extends TestCase
{
    public function testMatchesPhpEmptysExactTruthinessSemantics(): void
    {
        $method = new ReflectionMethod(SectionPopulator::class, 'emptyValue');

        foreach ([null, '', 0, 0.0, '0', false, []] as $falsy) {
            self::assertTrue($method->invoke(null, $falsy), var_export($falsy, true) . ' should be empty');
        }

        foreach (['0.0', ' ', 0.1, -1, 1, -1.0, 1.0, [0], 'false', true] as $notFalsy) {
            self::assertFalse($method->invoke(null, $notFalsy), var_export($notFalsy, true) . ' should not be empty');
        }
    }
}
