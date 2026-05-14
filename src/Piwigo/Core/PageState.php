<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Latte\Runtime\Html;

/**
 * Singleton holding typed per-request UI state: errors, warnings, infos,
 * messages, body classes/data, and display metadata (bodyId, pageBanner, etc.).
 *
 * Entries may be plain `string` (auto-escape applies — safe for Lang::t output)
 * or `Latte\Runtime\Html` (already-trusted HTML). Callers that splice HTML into
 * a message must wrap it with `new Html(...)`. The `admin.latte` / `infos_errors.latte`
 * foreach prints do bare `{$x}` — Html passes through, plain strings get
 * auto-escaped as expected.
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
    /** @var array<string,mixed> */
    public array $bodyData = [];
    public string $executionUuid = '';

    public bool $authKeyInvalid = false;
    /** @var array<string,mixed>|null */
    public ?array $notifyApiKeyExpiration = null;
    public int $countQueries = 0;
    public float $queriesTime = 0.0;

    public string $bodyId = '';
    public ?string $pageBanner = null;
    /** @var array<string,int> */
    public array $metaRobots = [];
    public ?string $galleryTitle = null;

    private function __construct()
    {
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


    // ---- Test helpers ----------------------------------------------------

    public static function reset(): void
    {
        self::$instance = null;
    }
}
