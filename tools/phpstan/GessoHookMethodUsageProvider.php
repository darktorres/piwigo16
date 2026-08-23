<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan;

use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use Studio\Gesso\Psr7\OpenApiAssertions;

/**
 * Recognizes Gesso's own documented hook methods (`openApiSpec()`,
 * `openApiMaxErrors()`, `openApiSkipResponseCodes()`,
 * `openApiSkipRequestValidationResponseCodes()`, `openApiSpecFallback()`)
 * as used when overridden on a class that mixes in
 * `Studio\Gesso\Psr7\OpenApiAssertions` -- Gesso's own
 * `OpenApiSpecResolver` trait calls these back via `$this->openApiSpec()`
 * etc. from inside vendor code this project's own phpstan.neon
 * deliberately excludes from analysis/scanning
 * (`excludePaths.analyseAndScan: vendor/*`), so
 * shipmonk/dead-code-detector has no way to see that real call site and
 * flags every override as dead. Gesso isn't in the tool's own
 * "Supported libraries" list (vendor/shipmonk/dead-code-detector/
 * README.md), so this is this project's own equivalent of that support,
 * scoped to exactly the hook names Gesso's trait documents -- see
 * tests/Support/OpenApiContractAssertions.php for the one real
 * (currently only) subscriber.
 */
final class GessoHookMethodUsageProvider extends ReflectionBasedMemberUsageProvider
{
    /**
     * @var list<string>
     */
    private const array HOOK_METHOD_NAMES = [
        'openApiSpec',
        'openApiMaxErrors',
        'openApiSkipResponseCodes',
        'openApiSkipRequestValidationResponseCodes',
        'openApiSpecFallback',
    ];

    #[\Override]
    public function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        if (! in_array($method->getName(), self::HOOK_METHOD_NAMES, true)) {
            return null;
        }

        if (! in_array(OpenApiAssertions::class, $method->getDeclaringClass()->getTraitNames(), true)) {
            return null;
        }

        return VirtualUsageData::withNote(
            'Called back via $this->' . $method->getName() . '() from Studio\\Gesso\\Spec\\OpenApiSpecResolver (vendor, excluded from analysis).'
        );
    }
}
