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

    // ---- Test helpers ----------------------------------------------------

    public static function reset(): void
    {
        self::$instance = null;
    }
}
