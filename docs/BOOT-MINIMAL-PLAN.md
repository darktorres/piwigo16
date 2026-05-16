# Plan: `Kernel::bootMinimal()` — proper fast-path boot

## Audit: entry-point boot behaviour in `index.php`

Five fast-paths exist. Three already call `Kernel::boot()` correctly; two
bypass it.

| Route prefix | `Kernel::boot()` | Verdict |
|---|---|---|
| *(normal)* | Yes — via `CommonBootstrap::run()` | Correct |
| `upgrade_feed` | Yes — directly | Correct |
| `upgrade` | Yes — directly | Correct |
| `install` | No at index level; `InstallController::__invoke()` calls it internally | Justified — see §2 |
| `i/` | No — deliberately skipped | **Needs fix — see §3** |

---

## §1 Smells in the current `i/` fast-path (`index.php:30-48`)

```php
// two problems:

// 1. Logger constructed inline — duplicates CommonBootstrap logic
$logger = new Logger([...]);
LoggerRegistry::set($logger);

// 2. Controller manually wired — bypasses the DI container
//    (bare EventDispatcher passed directly; EventDispatcherInterface
//     is now a container-registered service)
(new ImageDerivativeController(DbConnection::build(), new EventDispatcher()))(RequestFactory::fromGlobals());
```

The performance rationale is correct (derivative serving must not trigger
session startup, auth checks, or language loading), but the *implementation*
of the skip is wrong.

---

## §2 `install` fast-path — no change needed

`index.php` skips `Kernel::boot()` here because no DB credentials exist yet.
`InstallController::__invoke()` calls `Kernel::boot()` internally after the
user supplies credentials (line 91). This is the right layering. Leave it.

---

## §3 Solution: `Kernel::bootMinimal()`

Add a second boot profile to `src/Piwigo/Core/Kernel.php` for entry points
that need the DI container but must skip the globals-bridge machinery and
eager singletons.

### What `bootMinimal()` does vs `boot()`

| Step | `boot()` | `bootMinimal()` |
|---|---|---|
| Set `self::$booted = true` | Yes | Yes (shared flag — the `i/` path never escalates to full boot) |
| `LoggerRegistry` NullLogger fallback | Yes | Yes |
| `Container::build()` | Yes | Yes |
| `PageState::current()` (singleton init) | Yes | **No** |
| `Lang::attachGlobals()` | Yes | **No** |
| `CurrentUser::attachGlobals()` | Yes | **No** |
| Eager `StorageRegistry` init | Yes | **No** |

### Why the shared `$booted` flag is safe

The `i/` path calls `exit` immediately after serving the image. There is no
code path that calls `bootMinimal()` and then later needs full `boot()`.
The idempotency guard prevents double-wiring in nested includes, same as today.

---

## §4 Implementation steps

### Step A — `src/Piwigo/Core/Kernel.php`

Add `bootMinimal()` between `boot()` and `handle()`:

```php
public static function bootMinimal(): void
{
    if (self::$booted) {
        return;
    }
    self::$booted = true;

    if (!LoggerRegistry::isInitialized()) {
        LoggerRegistry::set(new NullLogger());
    }

    self::$container = Container::build();
}
```

No changes to `reset()` — it already clears `$booted` and `$container`.

### Step B — `index.php` `i/` block

Replace:

```php
// current
$logger = new Logger([
    'directory' => PHPWG_ROOT_PATH . Config::dataLocation() . Config::logDir(),
    'severity'  => Config::logLevel(),
    'filename'  => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . Config::dbPassword()) . '.txt',
]);
LoggerRegistry::set($logger);
(new ImageDerivativeController(DbConnection::build(), new EventDispatcher()))(RequestFactory::fromGlobals());
```

With:

```php
// target
$logger = new Logger([
    'directory' => PHPWG_ROOT_PATH . Config::dataLocation() . Config::logDir(),
    'severity'  => Config::logLevel(),
    'filename'  => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . Config::dbPassword()) . '.txt',
]);
LoggerRegistry::set($logger);
Kernel::bootMinimal();
Kernel::service(ImageDerivativeController::class)(RequestFactory::fromGlobals());
```

Logger creation stays before `bootMinimal()` so that `LoggerInterface` in the
container resolves to the real logger (the container factory reads
`LoggerRegistry` lazily, but the derivative path needs the real logger set
before any service resolves it).

### Step C — `config/container.php`

No change. `ImageDerivativeController` has two constructor params — `Connection`
and `EventDispatcherInterface` — both registered in `container.php`. PHP-DI
autowiring resolves both without an explicit factory entry.

The container's `EventDispatcherInterface` binding wires `CoreSubscribers`
lazily. This is safe for the derivative path: none of the 16 `CoreSubscribers`
subscribe to `DerivativeParamsGet`, so dispatching that event through the
container-built dispatcher has identical behaviour to the current bare
`new EventDispatcher()`. Plugin subscribers register via `PluginRegistry::activate()`
which requires plugins to be loaded — that doesn't happen on the `i/`
fast-path regardless.

---

## §5 Files touched

| File | Change |
|---|---|
| `src/Piwigo/Core/Kernel.php` | Add `bootMinimal()` method |
| `index.php` | Replace manual controller construction with `bootMinimal()` + `Kernel::service()` |

No other files change. `config/container.php`, `Kernel::reset()`, and the
`install`/`upgrade`/`upgrade_feed` fast-paths are all left as-is.
