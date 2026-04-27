<?php

declare(strict_types=1);

namespace Piwigo\Core;

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
        $p = &$GLOBALS['page'];

        $inst->errors = $p['errors'] ?? [];
        $inst->warnings = $p['warnings'] ?? [];
        $inst->messages = $p['messages'] ?? [];
        $inst->infos = $p['infos'] ?? [];
        $inst->bodyClasses = $p['body_classes'] ?? [];
        $inst->bodyData = $p['body_data'] ?? [];
        $inst->executionUuid = $p['execution_uuid'] ?? '';

        $p['errors'] = &$inst->errors;
        $p['warnings'] = &$inst->warnings;
        $p['messages'] = &$inst->messages;
        $p['infos'] = &$inst->infos;
        $p['body_classes'] = &$inst->bodyClasses;
        $p['body_data'] = &$inst->bodyData;
        $p['execution_uuid'] = &$inst->executionUuid;

        // Runtime-attached keys: preserve pre-boot values then bridge.
        $inst->authKeyInvalid = (bool) ($p['auth_key_invalid'] ?? false);
        $inst->notifyApiKeyExpiration = is_array($p['notify_api_key_expiration'] ?? null) ? $p['notify_api_key_expiration'] : null;
        $inst->countQueries = (int) ($p['count_queries'] ?? 0);
        $inst->queriesTime = (float) ($p['queries_time'] ?? 0.0);
        $inst->upgradeStart = (float) ($p['upgrade_start'] ?? 0.0);

        $p['auth_key_invalid'] = &$inst->authKeyInvalid;
        $p['notify_api_key_expiration'] = &$inst->notifyApiKeyExpiration;
        $p['count_queries'] = &$inst->countQueries;
        $p['queries_time'] = &$inst->queriesTime;
        $p['upgrade_start'] = &$inst->upgradeStart;
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
     * Merges \Piwigo\Core\Config::headerNotes() (informational strings set by plugins or config)
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
