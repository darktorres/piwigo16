<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for UpdatesPwgPageRenderer::render()
 * (the "pwg" tab of page slug "updates") -- P26/SEC-40 Request DTO. `to`'s
 * pattern differs by container environment (a trailing letter suffix
 * allowed only for `'Official'`), passed in by the caller since
 * `ContainerDetector::detect()` must run before this DTO is built.
 * `upgradeToSubmitted` defaults to `''` (not `null`) when not submitted,
 * so its type stays a plain `string` at every real call site -- the
 * caller always checks `isUpgradeSubmitted` first anyway, same as the
 * original's own `isset($_POST['submit']) and isset($_POST['upgrade_to'])
 * and is_string(...)` guard.
 */
final readonly class UpdatesPwgRequest
{
    private function __construct(
        public int $step,
        public string $upgradeTo,
        public bool $isUpgradeSubmitted,
        public string $upgradeToSubmitted,
    ) {}

    public static function fromGlobals(string $ctEnv): self
    {
        return self::fromArrays($_GET, $_POST, $ctEnv);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     */
    public static function fromArrays(array $get, array $post, string $ctEnv): self
    {
        $step_param = $get['step'] ?? 0;
        $step = (is_string($step_param) || is_int($step_param)) ? (int) $step_param : 0;

        if ($ctEnv === 'Official') {
            // Remove optional ? on [a-z]? since it will only be available on piwigo 16.3
            // Docker images started to use letter suffix in 16.2
            InputValidator::createStatic()
                ->validate('to', $get, false, '/^\d+\.\d+\.\d+[a-z]?$/');
            $get_to = $get['to'] ?? null;
            // preg_replace() only returns null on a genuine PCRE engine
            // error (e.g. hitting the backtrack limit) -- unreachable for
            // this trivial single-trailing-letter pattern against a
            // validate()-bounded version string, so the `?? ''` fallback
            // here can't be exercised. Confirmed while investigating a
            // mutation-testing gap.
            $upgradeTo = is_string($get_to) ? (preg_replace('/[a-z]$/', '', $get_to) ?? '') : '';
        } else {
            InputValidator::createStatic()
                ->validate('to', $get, false, '/^\d+\.\d+\.\d+$/');
            $get_to = $get['to'] ?? '';
            $upgradeTo = is_string($get_to) ? $get_to : '';
        }

        $upgrade_to_post = $post['upgrade_to'] ?? null;
        if (isset($post['submit']) && is_string($upgrade_to_post)) {
            $isUpgradeSubmitted = true;
            $upgradeToSubmitted = $upgrade_to_post;
        } else {
            $isUpgradeSubmitted = false;
            $upgradeToSubmitted = '';
        }

        return new self($step, $upgradeTo, $isUpgradeSubmitted, $upgradeToSubmitted);
    }
}
