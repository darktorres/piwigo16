# Security policy

This document describes the security posture of the `darktorres/piwigo16`
fork as of the §1.5 hardening pass. It targets two audiences:
operators running an install, and plugin/theme authors who need to
know what they can assume about the runtime.

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security bugs.

Report privately via GitHub's security advisories:
<https://github.com/darktorres/piwigo16/security/advisories/new>

What to include: a description of the vulnerability, the affected
version/branch, reproduction steps, and (if you have one) a proof of
concept. CVE assignment is handled through the advisory workflow.

**SLA:** This is a single-maintainer fork. Reports are acknowledged
on a best-effort basis; there is no fixed response or fix deadline.
Severity-dependent — credible critical reports get priority over
hardening suggestions.

## Supported versions

| Branch          | Supported |
| --------------- | --------- |
| `16.x-rewrite`  | ✅ active |
| upstream `16.x` | ❌        |
| anything older  | ❌        |

This fork has diverged substantially from upstream Piwigo 16. Only
`16.x-rewrite` receives security fixes here; upstream is handled by
the upstream project.

## Threat model

Defenses currently in tree, grouped by adversary.

### Anonymous attackers (unauthenticated)

| Attack              | Defense                                                                                                                                                                  |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Credential stuffing | Per-user lockout (5 failures / 15 min) backed by `piwigo_user_failed_logins`; per-IP rate limit 5/min; per-account rate limit 10/10min. See `LoginThrottle` + `LoginRateLimiterFactory`. |
| CSRF                | `CsrfMiddleware` validates `pwg_token` on every POST that isn't on the exempt path-prefix list (`/ws`, `/admin`, `/install`, `/upgrade`, `/identification`, `/register`, `/qsearch`). |
| XSS                 | `Content-Security-Policy: script-src 'self'` blocks executable inline JS; `composer lint:no-inline-scripts` catches regressions at CI time before they reach the browser. |
| Clickjacking        | `X-Frame-Options: SAMEORIGIN` + CSP `frame-ancestors 'self'` (defense in depth — older browsers honour XFO, modern ones prefer the CSP directive).                       |
| MIME-sniffing       | `X-Content-Type-Options: nosniff`.                                                                                                                                       |
| Referrer leakage    | `Referrer-Policy: strict-origin-when-cross-origin`.                                                                                                                      |

### Authenticated low-privilege attackers

| Attack                | Defense                                                                                                                                                                       |
| --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Privilege escalation  | `PermissionService::checkStatus(AccessLevel::…)` gates every admin/webmaster controller. Every state-changing call lands in `piwigo_activity` for the post-hoc audit trail.  |
| IDOR                  | Reviewed case-by-case at controller boundaries — each controller that reads a numeric id from request input must check the caller's access to that resource before returning data. No blanket runtime defense. |
| Session fixation      | `session_regenerate_id(true)` on every successful login (`AuthService::logUser`).                                                                                            |

### Supply chain

- `composer.lock` and `package-lock.json` are committed. Restore from
  lockfile, not from manifest, in production.
- Composer is configured with `classmap-authoritative: true` — only
  classes the maintainer has explicitly opted into are autoloadable
  in production.
- `composer audit` and `npm audit` run in CI on every PR.

## Response headers reference

Every response that flows through the PSR-15 pipeline carries the
following headers (set by `SecurityHeadersMiddleware`, position 0).
Bypass paths in `index.php` for `install`, `upgrade`, and the
`i/` derivative fast-path do **not** carry these headers by design —
they're admin-only or short-lived flows.

| Header                              | Value                                                                                                                                                                          |
| ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `Content-Security-Policy`           | `default-src 'self'; img-src 'self' data: blob:; style-src 'self'; style-src-elem 'self'; style-src-attr 'unsafe-inline'; script-src 'self'; frame-ancestors 'self'; form-action 'self'` |
| `X-Frame-Options`                   | `SAMEORIGIN`                                                                                                                                                                   |
| `X-Content-Type-Options`            | `nosniff`                                                                                                                                                                      |
| `Referrer-Policy`                   | `strict-origin-when-cross-origin`                                                                                                                                              |
| `Permissions-Policy`                | `geolocation=(), microphone=(), camera=()`                                                                                                                                     |
| `Strict-Transport-Security`         | `max-age=31536000; includeSubDomains` — **HTTPS only**                                                                                                                         |

### Notes on the CSP shape

- `script-src 'self'` (no nonce expression). Every `<script>` tag in
  the tree is `type="application/json"`, which CSP3 §6.1.5 exempts
  from `script-src`. If a future template needs inline JS, nonce
  support has to be added then.
- `style-src-attr 'unsafe-inline'` covers the inline `style="--var:value"`
  CSS-variable bridges on a handful of templates (thumbnails, month
  calendar, format cards, …). The corresponding `style-src-elem`
  remains strict (`'self'`) — no inline `<style>` blocks are allowed.
- `img-src` permits `data:` and `blob:` so derivative previews and
  in-browser blob URLs work.
- HSTS is intentionally emitted **only over HTTPS** — browsers ignore
  it over plain HTTP, but emitting it there is noise. `preload` is
  intentionally not in the value: opting into the browser preload
  list is a one-way deployment-policy commitment, not a code change.

## Session cookie reference

Set at bootstrap in `SessionBootstrap.php`:

| Attribute  | Value                                                |
| ---------- | ---------------------------------------------------- |
| `lifetime` | `0` (session cookie, expires on browser close)       |
| `path`     | `CookieService::cookiePath()`                        |
| `samesite` | `Lax`                                                |
| `secure`   | `true` when `$_SERVER['HTTPS']` is on, else `false`  |
| `httponly` | `true`                                               |

`SameSite=Lax` (not `Strict`) preserves the
click-a-Piwigo-link-from-email UX while still blocking cross-site
POST. `secure` is request-scheme-conditional so local plain-HTTP dev
still works; in production behind HTTPS, the flag is automatically
set.

## Account lockout — admin runbook

A user reports they can't log in despite knowing their password.
Cause: 5 failed attempts within the last 15 minutes triggered the
per-user lockout (`LoginThrottle`).

**Identify**

```sql
SELECT user_id, COUNT(*) AS failures, MAX(attempted_at) AS last_attempt
  FROM piwigo_user_failed_logins
 WHERE attempted_at > NOW() - INTERVAL 900 SECOND
 GROUP BY user_id;
```

(The default table prefix is `piwigo_`; substitute the configured
prefix if you've customised it.)

**Unlock manually**

```sql
DELETE FROM piwigo_user_failed_logins WHERE user_id = <id>;
```

After this the user can log in immediately. Expired rows (older than
the 15 min window) are purged automatically on the next login
attempt — there's no cron required.

An admin-UI button for this lives behind §1.5's "Known gaps" below.

## Authentication mechanics (plugin/theme author reference)

- **Password hashing:** bcrypt via `password_hash()` /
  `password_verify()`. Cost factor follows PHP's default. Never store
  cleartext; never compare hashes with `==` / `===` — use
  `password_verify()`.
- **Session ID rotation:** `session_regenerate_id(true)` runs on
  every successful login. If you implement a custom login flow,
  call `AuthService::logUser($userId, $rememberMe)` rather than
  setting session state yourself — that path is the one the audit
  trail and rotation rely on.
- **Persistent login cookie:** name is `Config::rememberMeName()`;
  format `<userId>-<timestamp>-<hmac>`; HMAC derived from a
  per-install secret. Lifetime is `Config::rememberMeLength()`.
- **WS-API login errors:** `pwg.session.login` returns
  `PwgError(429, …)` for **both** the per-IP / per-account rate
  limit and the per-user lockout (the lockout message is
  `AuthException::accountLocked()`'s string). Treat any `429` as
  "wait and try again later" — don't retry immediately.

## CSP override procedure

Not yet supported. If your plugin needs to relax CSP (e.g. to add a
remote `script-src` host), please open an advisory or issue
describing what you need and why; the eventual hook will plug into
`PluginInterface::boot($container)` once the first concrete case
justifies designing it. The current zero-extension surface keeps the
policy as tight as possible by default.

## Known gaps / deferred work

These are intentional gaps documented so operators don't infer more
guarantees than the code delivers.

- **Admin UI for unlocking users.** The admin-page registry shipped
  in §1.4 B10; the unlock button itself is pending. Until then, use
  the SQL in "Account lockout — admin runbook" above.
- **Configurable lockout / rate-limit thresholds.** Currently
  hardcoded in `LoginThrottle` (5 / 15 min) and
  `LoginRateLimiterFactory` (5/min IP, 10/10min account). Config
  keys are gated on §1.6c (config-schema metadata).
- **HSTS `preload`.** Not in the header value — committing to the
  preload list is a one-way deployment policy decision, not a code
  change. Operators who want it can append `; preload` at the
  reverse-proxy layer.
- **`__Host-` / `__Secure-` cookie prefixes + unconditional
  `secure`.** Gated on a future "force HTTPS" config flag.
- **CSP `report-uri` / `report-to`.** No reporting endpoint exists
  to wire to yet; will be revisited if production violations appear.
- **Per-plugin CSP relaxation hook.** See "CSP override procedure"
  above.
- **WS-API rate-limit response shape.** Currently
  `PwgError(429, …)`. A typed HTTP `Response` body comes with §1.7
  (typed boundaries / HTTP DTO Phase 1).
