<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Studio\Gesso\Psr7\OpenApiAssertions;

/**
 * Mixes Gesso's PSR-7 assertion trait into a plain-functional Pest test
 * file via `uses(OpenApiContractAssertions::class)->in(...)` and points
 * it at this project's one real spec (`openapi/openapi.yaml`, loaded by
 * `OpenApiCoverageExtension` under the spec name `openapi` --
 * `OpenApiSpecLoader::resolveSpecFile()` matches a spec name to
 * `{spec_base_path}/{specName}.{json,yaml,yml}` verbatim, so this has to
 * be the real filename, not an arbitrary label). Gesso's own
 * `#[OpenApiSpec('...')]` attribute needs a real class to attach to,
 * which a Pest `it()`/`test()` closure never has -- overriding the
 * trait's `openApiSpec()` hook instead is the documented alternative for
 * exactly this shape.
 */
trait OpenApiContractAssertions
{
    use OpenApiAssertions;

    protected function openApiSpec(): string
    {
        return 'openapi';
    }

    /**
     * Gesso's own default is `['400', '422']` -- "the request didn't
     * meet the documented shape, and the app correctly said so" is
     * downgraded from a request-validation failure to a pass. This
     * surface's own documented contract treats a security-requirement
     * miss the exact same way: every admin-only/logged-in-only
     * operation has a real, deliberately-documented 401/403
     * `application/problem+json` response for exactly the "no/invalid
     * session cookie" case (AdminGuard/CsrfGuard's own real behavior,
     * not a framework afterthought) -- a test exercising that
     * documented negative case (an anonymous request against an
     * admin-only endpoint, asserting the real 401 body) is proving the
     * contract holds, not violating it.
     *
     * @return string[]
     */
    protected function openApiSkipRequestValidationResponseCodes(): array
    {
        return ['400', '401', '403', '422'];
    }
}
