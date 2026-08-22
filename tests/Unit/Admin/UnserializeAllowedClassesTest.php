<?php

declare(strict_types=1);

/**
 * [P44-K] `PiwigoInfosSender::send()`/`CoreUpdateService::triggerDownload()`
 * both call `unserialize()` directly on a remote HTTPS response body (the
 * PEM extension-list API and the core-download chunk-counter API
 * respectively). Neither method has a real test seam of its own --
 * both talk to piwigo.org over the network via the static, non-injectable
 * `Piwigo\Http\HttpClientService::fetch()`, an already-documented,
 * accepted limitation (see PiwigoInfosSenderTest.php's own docblock:
 * "matching this same session's PemCatalog/CoreUpdateService findings
 * for the same class of 'talks to piwigo.org' code") -- so this doesn't
 * drive the real methods end-to-end. Instead it verifies the two
 * properties that actually matter: (1) the exact fixed source shape is
 * present at both call sites, and (2) `allowed_classes: false` really
 * does defeat PHP Object Injection for a payload shaped like what either
 * API's response could carry, degrading to the same "not a plain array"
 * rejection path both callers already have (`is_array($decodedExtensions)`/
 * `is_array($input)`).
 */
final class UnserializeAllowedClassesTestGadget
{
    public bool $woke = false;

    public function __wakeup(): void
    {
        $this->woke = true;
    }
}

test('PiwigoInfosSender::send()\'s unserialize() call passes allowed_classes: false', function (): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Piwigo/Admin/PiwigoInfosSender.php');
    expect($source)
        ->toBeString();
    assert(is_string($source));

    expect((bool) preg_match("/@unserialize\\(\\\$result,\\s*\\[\\s*'allowed_classes' => false,?\\s*\\]\\)/", $source))
        ->toBeTrue();
});

test('CoreUpdateService::triggerDownload()\'s unserialize() call passes allowed_classes: false', function (): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Piwigo/Admin/Extensions/CoreUpdateService.php');
    expect($source)
        ->toBeString();
    assert(is_string($source));

    expect((bool) preg_match("/@unserialize\\(\\\$result,\\s*\\[\\s*'allowed_classes' => false,?\\s*\\]\\)/", $source))
        ->toBeTrue();
});

test('unserialize() with allowed_classes: false degrades an object-injection payload to a value both real callers already reject', function (): void {
    $payload = serialize(new UnserializeAllowedClassesTestGadget());

    // Suppresses the real E_WARNING unserialize() emits for a disallowed
    // class -- same established pattern as ArrayHelperTest's own
    // malformed-input case.
    set_error_handler(static fn (): bool => true);
    try {
        $result = unserialize($payload, [
            'allowed_classes' => false,
        ]);
    } finally {
        restore_error_handler();
    }

    // __wakeup() never runs -- the gadget's own side effect is proof the
    // object was never really constructed, not just differently typed.
    expect($result)
        ->not->toBeInstanceOf(UnserializeAllowedClassesTestGadget::class)
        ->and($result)
        ->not->toBeArray();
});
