# ADR-0013: FrankenPHP worker-mode runtime

## Status

Accepted (decided P4; backfilled at P5 — see `docs/PLAN-REPLAY.md`'s
Architecture Decision Records section for why this file trailed its own
citations)

## Context

The rewrite needs a PHP runtime for the production container image. Classic
`php-fpm`/Apache `mod_php` re-bootstraps the full framework on every request —
fine for a procedural legacy codebase, but this project is modernizing toward
a DI container, PSR-15 middleware, and a routed kernel (P7-P9), where
per-request bootstrap cost compounds. Worker-mode runtimes (keep the app
booted in memory, handle requests in a loop) avoid that cost, but bring their
own hazard: state that should be per-request can leak across requests if a
service accidentally holds onto request-scoped data between iterations
(SEC-60).

## Decision

Use FrankenPHP (Caddy + PHP 8.5) in worker mode as the primary production
runtime (`docker build --target production .`, per `docs/DEPLOYMENT.md`).
Keep a classic Apache/`mod_rewrite` fallback stage
(`docker build --target production-apache .`) for hosts that need it — not
every deployment target can run FrankenPHP, and the fallback is cheap to
maintain since it's the same codebase running in a more conventional,
per-request model.

Both images listen on `:80` as non-root `www-data`; FrankenPHP/Caddy needs
`CAP_NET_BIND_SERVICE` at startup regardless of which port it ultimately
binds, so there's no capability-reduction benefit to a high port with this
runtime — `cap_drop: [ALL]` + one narrow `cap_add: [NET_BIND_SERVICE]`
exception is the actual hardening, not port choice.

## Consequences

- Worker-mode state hazards are a standing concern from here forward: any
  service built in P7+ that holds request-scoped data must be explicitly
  reset or scoped per-request — SEC-60 ("cross-request state bleed") tracks
  this and gets its own arch/integration test once the kernel exists (P7).
- Two runtime targets (FrankenPHP + Apache fallback) means CI's deny-rule and
  containerization checks need to exercise both, not just one — already the
  case (`ci.yml`'s `apache-deny-rules` and `container-deny-rules` jobs).
- Deployment docs and the Helm chart standardize on FrankenPHP as the
  recommended path; Apache is documented as the fallback, not given equal
  billing, so operators default to the runtime this project actually tunes
  performance against.
