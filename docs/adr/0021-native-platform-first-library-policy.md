# ADR-0021: Native-platform-first library policy

## Status

Accepted (decided P5 for the PHP-side content below; the same policy is
invoked again at later phases for their own vendored/legacy dependencies —
see Consequences)

## Context

Both `origin/16.x` and this rewrite's early scaffolding carry vendored
copies of small third-party libraries (`include/smarty/`, `include/phpmailer/`,
`include/emogrifier.class.php`, `include/phpqrcode.php`, `include/mdetect.php`,
`include/jshrink.class.php`, `include/minify/`) alongside a hand-rolled
`fetchRemote()` HTTP client and a phpass-based password hasher
(`include/passwordhash.class.php`). Carrying vendored copies means no
independent security patching, no Composer-tracked version/CVE visibility
(SEC-50/51/52), and — for `passwordhash.class.php` specifically — a weaker,
non-native KDF where PHP itself now ships a maintained one.

## Decision

Prefer browser/PHP-native features and the project's own adopted
Symfony/Doctrine layer over standalone vendored or third-party libraries,
wherever a native or already-adopted equivalent covers the need. Concretely,
at P5:

- Drop `include/mdetect.php` (User-Agent string sniffing) with **no
  replacement library** — Chromium is freezing/reducing the UA string, and
  this project's server-rendered MPA + responsive CSS (container queries,
  P30) removes most of the need for runtime device branching. Where a
  server-side hint is genuinely needed later, read native User-Agent Client
  Hints (`Sec-CH-UA-Mobile`/`Sec-CH-UA-Platform`/`Sec-CH-Viewport-Width` via
  `Critical-CH`) instead of a UA-parsing library.
- Replace `include/phpmailer/` with `symfony/mailer` + `symfony/mime`
  (already-adopted Symfony layer), `include/emogrifier.class.php` with the
  actively maintained `pelago/emogrifier` package, `include/phpqrcode.php`
  with `endroid/qr-code`, and the hand-rolled `fetchRemote()` with
  `symfony/http-client` behind PSR-18.
- Replace the vendored phpass hasher with PHP's own native
  `password_hash()`/`password_verify()` (bcrypt) — no library at all, since
  the platform now provides this natively.
- `include/smarty/` becomes a Composer-tracked `smarty/smarty` dependency
  instead of a vendored copy — same library, properly versioned — since
  Smarty itself stays the template engine until P29's Latte migration, this
  is a supply-chain fix, not a native-platform swap.

## Consequences

- Every one of these swaps is a real call-site rewrite, not just a
  `composer require` — the vendored and replacement APIs differ (see
  `docs/PLAN-REPLAY.md`'s P5 section for the specific per-library call-site
  notes: Emogrifier's v8 fluent API replacing direct instantiation, QR code's
  builder pattern replacing direct-output `QRcode::png()`, PHPMailer's
  object API replaced by `Symfony\Component\Mime\Email` + DSN-based
  `Mailer::send()`).
- `symfony/messenger` is not a P5 dependency (it lands P7+), so PHPMailer's
  replacement ships synchronous at P5; async send on the Messenger bus is a
  later enhancement under this same policy, not re-litigated as a new
  decision.
- This policy is invoked again, not re-decided, at later phases with their
  own vendored/legacy surfaces: mdetect's stub gets real Client-Hint reading
  once a concrmete need appears; the T3·WEB track retires flatpickr,
  nouislider, js-cookie, dayjs-formatting, and glightbox (→ `<pwg-lightbox>`)
  in the frontend under the same rationale; visual regression tooling moves
  to Playwright's native `toHaveScreenshot()`; JS reactivity uses
  `@lit-labs/signals` rather than a third-party signals library. Each of
  those is a progressive-enhancement swap with a fallback where the native
  feature isn't universally available yet (Chrome-ahead features), not a
  hard cutover.
