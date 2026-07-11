# Architecture

> Created in P6 (PSR-4 namespace migration). Extended in P7–P23, P27, P32 as
> later phases add the kernel, DI container, HTTP layer, config/DB/language
> subsystems, service layer, frontend build, and the final dependency-cycle
> cleanup. This first version documents only what P6 actually produced: the
> `src/Piwigo/` namespace tree and the `deptrac.yaml` layer model that governs
> it.

## Scope of P6

P6 extracted every first-party `class`/`interface` declaration that used to
live inside `include/` and `admin/include/` procedural files into a
PSR-4-autoloaded `src/Piwigo/` tree, under the `Piwigo\` namespace prefix
(`composer.json`'s `autoload.psr-4`). **This was extraction and namespacing
only, not a rewrite** — class names, method signatures, and behavior are
unchanged from the pre-P6 procedural codebase; only file location and
namespace changed. Renaming classes to modern PSR-1 `StudlyCaps` (several
still use the legacy lowercase/snake_case style: `pwg_image`, `tabsheet`,
`plugins`, `check_integrity`, …), redesigning APIs, and introducing
dependency injection are P17–P23's job.

66 classes/interfaces across 33 origin files were moved. The procedural
files that contained them either got fully deleted (when they held nothing
but class declarations) or trimmed down to their remaining top-level
`function`/`define()` statements (when they mixed classes with procedural
code — e.g. `include/functions_plugins.inc.php` keeps its ~10
`add_event_handler()`-family functions after `PluginMaintain`/`ThemeMaintain`
moved out).

## Namespace tree

```
src/Piwigo/
├── Admin/                    admin-side domain classes (plugin/theme/language
│   │                         management, integrity checks, image processing,
│   │                         tab navigation) — still legacy-cased names
│   ├── Image/                image processing backends (GD, Imagick, external
│   │                         ImageMagick) behind a common imageInterface
│   └── Integrity/            installation self-check (check_integrity,
│                             c13y_internal)
├── Auth/                     TOTP two-factor auth (PwgBase32, PwgTOTP)
├── Cache/                    PersistentCache abstract base + file-backed impl
├── Calendar/                 monthly/weekly calendar navigation widgets
├── Core/                     cross-cutting infra with no better home yet
│                             (Logger) — P13+ will likely grow this into
│                             Config/Db/etc. per the 6-layer model below
├── Image/                    derivative-image computation: SrcImage,
│                             DerivativeImage, DerivativeParams, ImageRect,
│                             ImageStdParams, SizingParams, WatermarkParams
├── Menu/                     admin/theme menu block registration
├── Search/                   advanced-search query model (Q*Scope/Q*Token
│                             classes) plus per-language search-term
│                             inflectors
├── Session/                  PwgSession (SessionHandlerInterface)
├── Site/                     local filesystem site-synchronization reader
├── Template/                 Smarty wrapper + JS/CSS combination/minification
│                             pipeline (Template, CssLoader, ScriptLoader,
│                             FileCombiner, Combinable/Script/Css)
└── Ws/                       web services (REST/XML-RPC) protocol layer
    ├── Encoder/               response-encoder abstract base
    └── Protocol/              per-format encoders/request handlers (JSON,
                                Serial-PHP, REST, XML-RPC)
```

Every namespace segment above is deliberately enumerated in `deptrac.yaml`'s
collectors (not matched by a catch-all regex) — a future phase adding a new
top-level namespace has to explicitly decide which architectural layer it
belongs to rather than silently falling through to a default.

## Layer model (`deptrac.yaml`)

P6 also introduced a 6-layer dependency-direction model, enforced by
[Deptrac](https://github.com/qossmic/deptrac) in CI (`.github/workflows/ci.yml`'s
`deptrac` job). Each layer may only depend on layers at or below its own
level:

| Layer | P6 namespaces | Later-phase additions (per `docs/PLAN-REPLAY.md`) |
| --- | --- | --- |
| **L4 Integration** | `Admin` (incl. `Admin\Image`, `Admin\Integrity`), `Ws` (incl. `Ws\Encoder`, `Ws\Protocol`) | `Bootstrap`, `Controller`, `Job` |
| **L3 Presentation** | `Menu`, `Template` | `Html`, `Http`, `Page`, `Asset`, `Listener` |
| **L2b Extended Domain** | `Search`, `Calendar`, `Site` | every other first-party domain namespace |
| **L2a Core Domain** | `Auth`, `Image` | `Users`, `Permission`, `Category`, `Tag` |
| **L1 Infrastructure** | `Cache`, `Session`, `Core` | `Config`, `Db` |
| **L0 Data** | *(none yet)* | `Common`, `Event`, `Exception` |

A layer may depend on itself and any layer below it, never a layer above it
(e.g. L4 can depend on L3/L2b/L2a/L1/L0, but L1 can only depend on L0).

### Baseline

Running `deptrac analyse` against the P6 result surfaced **10 violations**,
all Presentation/Extended-Domain code reaching directly into L2a-CoreDomain's
`Image` classes:

- `Calendar\CalendarMonthly` → `Image\{DerivativeImage,ImageStdParams,SrcImage}`
  (calendar thumbnail rendering)
- `Template\Template` → `Image\ImageStdParams` (the `define_derivative`
  Smarty function)
- `Template\PwgTemplateAdapter` → `Image\{DerivativeImage,SrcImage}` (the
  `$pwg->derivative(...)` template helper)

These are recorded in `deptrac.yaml`'s `skip_violations` block rather than
fixed — P6 is a pure extraction pass, and untangling them properly needs a
real service layer between the Presentation/Extended-Domain layers and
`Image` (P17–P23's job, not P6's). **Ratchet this list down, never up**: a
future phase introducing the service layer should remove entries here as it
fixes each dependency, and no new phase should add to it without an
equivalent architectural justification.

## What P6 did not do

- No renaming to modern casing (`pwg_image`, `tabsheet`, `plugins`, etc. keep
  their legacy names — P17–P23).
- No dependency injection or service-layer introduction — classes still read
  globals (`global $conf;`, `global $template;`) exactly as before.
- No behavior changes beyond what was strictly required to survive the
  namespace move itself: qualifying built-in PHP class references
  (`\Exception`, `\DateTime`, …) that used to resolve from the global
  namespace implicitly, fixing dynamic-reference patterns that don't respect
  `use` imports (`new $classString()`, `['ClassName', 'method']` callable
  arrays — changed to `[self::class, 'method']` where self-referential), and
  one genuine bug fix forced by the move: `admin/include/updates.class.php`'s
  `new $type()` dynamic instantiation of `plugins`/`themes`/`languages` by
  bare string, replaced with an explicit `match()`.
