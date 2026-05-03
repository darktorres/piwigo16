<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\Config;

/**
 * Typed DTO wrapping the $page global array.
 *
 * Wave A reference bridge: after attachGlobals(), the well-known $GLOBALS['page']
 * keys (errors, warnings, messages, infos, body_classes, body_data, execution_uuid)
 * are PHP references to the corresponding properties of this singleton. Code that
 * writes $page['errors'][] = 'msg' pushes into self::$instance->errors and vice
 * versa — no data divergence between the global and the typed reader.
 */
final class PageState
{
    private static ?self $instance = null;

    /** @var list<string> */
    public array $errors = [];
    /** @var array<string, string> */
    public array $keyedErrors = [];
    /** @var list<string> */
    public array $warnings = [];
    /** @var list<string> */
    public array $messages = [];
    /** @var list<string> */
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

        $errors = $p['errors'] ?? [];
        $inst->errors = is_array($errors) ? array_values(array_filter($errors, 'is_string')) : [];
        $warnings = $p['warnings'] ?? [];
        $inst->warnings = is_array($warnings) ? array_values(array_filter($warnings, 'is_string')) : [];
        $messages = $p['messages'] ?? [];
        $inst->messages = is_array($messages) ? array_values(array_filter($messages, 'is_string')) : [];
        $infos = $p['infos'] ?? [];
        $inst->infos = is_array($infos) ? array_values(array_filter($infos, 'is_string')) : [];
        $bodyClasses = $p['body_classes'] ?? [];
        $inst->bodyClasses = is_array($bodyClasses) ? array_values(array_filter($bodyClasses, 'is_string')) : [];
        $bodyData = $p['body_data'] ?? [];
        $inst->bodyData = is_array($bodyData) ? array_filter($bodyData, fn ($v, $k) => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH) : [];
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

    public function addError(string $msg): void
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
    public function addWarning(string $msg): void
    {
        $this->warnings[] = $msg;
    }
    public function addMessage(string $msg): void
    {
        $this->messages[] = $msg;
    }
    public function addInfo(string $msg): void
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
     * @param list<string> $headerNotes
     */
    public function mergeFromConf(array $headerNotes): void
    {
        foreach ($headerNotes as $note) {
            $this->infos[] = $note;
        }
    }

    // ---- Test helpers ----------------------------------------------------

    public static function reset(): void
    {
        self::$instance = null;
    }
}
