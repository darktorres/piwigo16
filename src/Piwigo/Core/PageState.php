<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Latte\Runtime\Html;

/**
 * Typed DTO wrapping the $page global array.
 *
 * Wave A reference bridge: after attachGlobals(), the well-known $GLOBALS['page']
 * keys (errors, warnings, messages, infos, body_classes, body_data, execution_uuid)
 * are PHP references to the corresponding properties of this singleton. Code that
 * writes $page['errors'][] = 'msg' pushes into self::$instance->errors and vice
 * versa — no data divergence between the global and the typed reader.
 *
 * Entries may be plain `string` (auto-escape applies — safe for Lang::t output)
 * or `Latte\Runtime\Html` (already-trusted HTML — links, icons, formatted text).
 * Callers that splice HTML into a message must wrap it with `new Html(...)` or
 * use `addInfoHtml`/`addErrorHtml`/`addMessageHtml`/`addWarningHtml`. The
 * `admin.latte`/`infos_errors.latte` foreach prints do bare `{$x}` — Html
 * passes through, plain strings get auto-escaped as expected.
 */
final class PageState
{
    private static ?self $instance = null;

    /** @var list<string|Html> */
    public array $errors = [];
    /** @var array<string, string> */
    public array $keyedErrors = [];
    /** @var list<string|Html> */
    public array $warnings = [];
    /** @var list<string|Html> */
    public array $messages = [];
    /** @var list<string|Html> */
    public array $infos = [];
    /** @var list<string> */
    public array $bodyClasses = [];
    /** @var array<string,string> */
    public array $bodyData = [];
    public string $executionUuid = '';

    // Runtime-attached keys (optional; bridged in attachGlobals)
    public bool $authKeyInvalid = false;
    /** @var array<string,mixed>|null */
    public ?array $notifyApiKeyExpiration = null;
    public int $countQueries = 0;
    public float $queriesTime = 0.0;
    public float $upgradeStart = 0.0;

    private function __construct()
    {
    }

    /**
     * Called by Kernel::boot() after common.inc.php has populated $GLOBALS['page'].
     * Preserves any values already in $page, then wires reference bridges so both
     * the global and typed accessors share the same storage.
     */
    public static function attachGlobals(): void
    {
        self::$instance ??= new self();
        $inst = self::$instance;

        // Read pre-boot values from $GLOBALS['page'] (may be mixed).
        $raw = $GLOBALS['page'] ?? [];
        /** @var array<string,mixed> $p */
        $p = is_array($raw) ? $raw : [];

        $inst->errors = self::filterStringOrHtmlList($p['errors'] ?? []);
        $inst->warnings = self::filterStringOrHtmlList($p['warnings'] ?? []);
        $inst->messages = self::filterStringOrHtmlList($p['messages'] ?? []);
        $inst->infos = self::filterStringOrHtmlList($p['infos'] ?? []);
        $bodyClasses = $p['body_classes'] ?? [];
        $inst->bodyClasses = is_array($bodyClasses) ? array_values(array_filter($bodyClasses, is_string(...))) : [];
        $bodyData = $p['body_data'] ?? [];
        $inst->bodyData = is_array($bodyData) ? array_filter($bodyData, fn ($v, $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH) : [];
        $execUuid = $p['execution_uuid'] ?? '';
        $inst->executionUuid = is_string($execUuid) ? $execUuid : '';

        // Runtime-attached keys: preserve pre-boot values then bridge.
        $inst->authKeyInvalid = (bool) ($p['auth_key_invalid'] ?? false);
        $notifyRaw = $p['notify_api_key_expiration'] ?? null;
        /** @var array<string,mixed>|null $notifyTyped */
        $notifyTyped = is_array($notifyRaw) ? $notifyRaw : null;
        $inst->notifyApiKeyExpiration = $notifyTyped;
        $countQ = $p['count_queries'] ?? 0;
        $inst->countQueries = is_int($countQ) ? $countQ : (is_numeric($countQ) ? (int) $countQ : 0);
        $qTime = $p['queries_time'] ?? 0.0;
        $inst->queriesTime = is_float($qTime) ? $qTime : (is_numeric($qTime) ? (float) $qTime : 0.0);
        $upStart = $p['upgrade_start'] ?? 0.0;
        $inst->upgradeStart = is_float($upStart) ? $upStart : (is_numeric($upStart) ? (float) $upStart : 0.0);

        // Now re-assign $GLOBALS['page'] with reference bridges.
        $GLOBALS['page'] = [];
        $GLOBALS['page']['errors'] = &$inst->errors;
        $GLOBALS['page']['keyed_errors'] = &$inst->keyedErrors;
        $GLOBALS['page']['warnings'] = &$inst->warnings;
        $GLOBALS['page']['messages'] = &$inst->messages;
        $GLOBALS['page']['infos'] = &$inst->infos;
        $GLOBALS['page']['body_classes'] = &$inst->bodyClasses;
        $GLOBALS['page']['body_data'] = &$inst->bodyData;
        $GLOBALS['page']['execution_uuid'] = &$inst->executionUuid;
        $GLOBALS['page']['auth_key_invalid'] = &$inst->authKeyInvalid;
        $GLOBALS['page']['notify_api_key_expiration'] = &$inst->notifyApiKeyExpiration;
        $GLOBALS['page']['count_queries'] = &$inst->countQueries;
        $GLOBALS['page']['queries_time'] = &$inst->queriesTime;
        $GLOBALS['page']['upgrade_start'] = &$inst->upgradeStart;
    }

    /** Returns the current singleton, creating an empty one if boot hasn't run yet. */
    public static function current(): self
    {
        return self::$instance ??= new self();
    }

    public function addError(string|Html $msg): void
    {
        $this->errors[] = $msg;
    }
    public function addKeyedError(string $key, string $message): void
    {
        $this->keyedErrors[$key] = $message;
    }
    public function getKeyedError(string $key): ?string
    {
        return $this->keyedErrors[$key] ?? null;
    }
    /** @return array<string, string> */
    public function getKeyedErrors(): array
    {
        return $this->keyedErrors;
    }
    public function addWarning(string|Html $msg): void
    {
        $this->warnings[] = $msg;
    }
    public function addMessage(string|Html $msg): void
    {
        $this->messages[] = $msg;
    }
    public function addInfo(string|Html $msg): void
    {
        $this->infos[] = $msg;
    }
    public function addBodyClass(string $cls): void
    {
        $this->bodyClasses[] = $cls;
    }
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Merges \Piwigo\Config\Config::headerNotes() (informational strings set by plugins or config)
     * into the infos bucket so they appear in the page output.
     *
     * @param list<string|Html> $headerNotes
     */
    public function mergeFromConf(array $headerNotes): void
    {
        foreach ($headerNotes as $note) {
            $this->infos[] = $note;
        }
    }

    /**
     * Coerce an arbitrary `$page['…']` value (typically the pre-boot global
     * array, which PHP types as `mixed`) into a clean
     * `list<string|Html>`. Anything that isn't already a string or `Html`
     * instance is dropped.
     *
     * @return list<string|Html>
     */
    private static function filterStringOrHtmlList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            if (is_string($v) || $v instanceof Html) {
                $out[] = $v;
            }
        }
        return $out;
    }

    // ---- Test helpers ----------------------------------------------------

    public static function reset(): void
    {
        self::$instance = null;
    }
}
