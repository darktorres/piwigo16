# Deployment

Covers running the image built by `./Dockerfile` — standalone, via Compose, or via
Helm. See `docs/PLAN-REPLAY.md` P4 for the design rationale and `docs/RUNBOOK.md`
for operations (incident response, restore, secret rotation).

## The image

`docker build --target production .` — FrankenPHP (Caddy + PHP 8.5, the recommended
runtime per ADR-0013). `docker build --target production-apache .` is the fallback for
hosts that need classic Apache/mod_rewrite.

Both images listen on **:80** as the non-root `www-data` user. Verified empirically that
FrankenPHP/Caddy wants `CAP_NET_BIND_SERVICE` at startup regardless of which port it ends
up binding (not just below-1024 ports as usual), so there was nothing to gain from
listening on a high port instead — the capability is unavoidable with this runtime
either way. `docker-compose.yml`/the Helm chart both do `cap_drop: [ALL]` +
`cap_add: [NET_BIND_SERVICE]` — one narrow, explicit exception, not the ~14-capability
Docker default set and not root.

Writable at runtime, everything else is read-only: `_data/` (cache), `local/` (config +
install sentinel), `galleries/` (photo storage), `upload/` (incoming uploads). Mount all
four as volumes.

## Image signing (SEC-54)

`.github/workflows/release-image.yml` builds the `production` target, pushes it to
`ghcr.io/<repo>` (tagged with the release version and `latest`), and signs it with
keyless `cosign` — no long-lived signing key; Fulcio/Rekor bind the signature to that
workflow run's own GitHub Actions OIDC identity. It only runs on a published GitHub
Release (release-please's output), never on every push, so an untrusted PR build can
never get signed. Verify before deploying a pulled image — this is the actual
deploy-time gate, not just a CI nicety:

```
cosign verify \
  --certificate-identity-regexp "https://github.com/<owner>/<repo>/.github/workflows/release-image.yml@.*" \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  ghcr.io/<owner>/<repo>:<tag>
```

## Standalone

```
docker build --target production -t piwigo .
docker run -d \
  --cap-drop=ALL --cap-add=NET_BIND_SERVICE --security-opt no-new-privileges \
  -p 8080:80 \
  -e PIWIGO_DB_HOST=<host> -e PIWIGO_DB_USER=<user> \
  -e PIWIGO_DB_PASSWORD=<password> -e PIWIGO_DB_BASE=<database> \
  -v piwigo_data:/app/_data -v piwigo_local:/app/local \
  -v piwigo_galleries:/app/galleries -v piwigo_upload:/app/upload \
  piwigo
```

## Compose

`docker compose up` — app + `mysql:9.7` (`--container-aware=ON`, auto-tunes
InnoDB/thread-pool from the container's cgroup limits) + `redis:7-alpine` (provisioned
now, unused by the app until P11 wires in cache/session). `docker compose --profile test
run test` runs the JS/TS Vitest suite in a container — see `docker-compose.yml`'s
comments for why the PHP Integration/Contract/Browser/Visual suites stay on bare-metal
CI instead (they need either a live DB+webserver or a full browser/Chromium stack, which
is real, separate scope from this phase's containerization work).

## Kubernetes (Helm)

```
helm install piwigo deploy/helm/piwigo \
  --set image.repository=<your-registry>/piwigo \
  --set image.tag=<tag> \
  --set db.host=<mysql-service> \
  --set db.existingSecret=<secret-name>
```

`db.existingSecret` (key `db-password` by default, `db.existingSecretPasswordKey` to
override) is the only supported way to supply the DB password — never `--set
db.password=...`, which lands in `helm history`/`helm get values` in plaintext.

`values.yaml` defaults: `ClusterIP` Service, Ingress disabled, 1 replica,
`runAsNonRoot`/`cap_drop: [ALL]` + `cap_add: [NET_BIND_SERVICE]`/`seccompProfile:
RuntimeDefault` (mirrors the Compose hardening below), 4 PVCs matching the 4 writable
directories above. Set `ingress.enabled=true` +
`ingress.host`/`ingress.className`/`ingress.tls` for external access.

## Runtime hardening

Both Compose and the Helm chart run: non-root (`www-data`, uid 33), `cap_drop: [ALL]`
plus a single `cap_add: [NET_BIND_SERVICE]` exception (see above), `security_opt:
no-new-privileges`, `seccompProfile: RuntimeDefault`, `readOnlyRootFilesystem` (with
`/tmp` as tmpfs/emptyDir and the four directories above as the only other writable
mounts).

## Web root (SEC-01, SEC-33, SEC-35, SEC-38, SEC-47)

Document root is `public/`, not the repo/image root (`docs/PLAN-REPLAY.md`'s P32
"web-root isolation" goal, pulled forward as its own phase) — every PHP entry point
(`index.php`, `admin.php`, `i.php`, ...) lives there, plus symlinks back to the 4 static
asset directories real requests actually need (`themes/`, `admin/themes/`, `dist/`,
`_data/combined/`). Everything else stays outside `public/` on purpose, most importantly
`upload/`, `galleries/`, `local/`, `language/`, `plugins/`, and every other `_data/`
subdirectory (`i/` — image derivatives — `logs/`, `backups/`, `cache/`, `templates_c/`,
`tmp/`): these are permission-gated (`i.php`/`ImageDerivativeController` and
`action.php`'s own permission checks, `Lang::load()`/`StorageRegistry` reading
`language/`/`local/`/`plugins/` server-side), and being directly, statically
web-reachable let a private album's originals/derivatives be served to anyone who
knew/guessed the URL, forever, without ever touching the permission check again once a
derivative was cached (found live during Part II's own investigation — not hypothetical).
`DocumentRoot=public/` is what actually closes this: none of these directories need web
reachability at all, so unlike the SEC-01 deny rules below (URL-pattern rules, matched
regardless of whether the underlying path exists), there's no rule to write here — the
fix is structural, requests to any of them 404 like any other nonexistent path. Verified
by CI (`.github/workflows/ci.yml`'s `apache-deny-rules`/`container-deny-rules` jobs) with
a real on-disk fixture file at each path, confirming genuine unreachability rather than
"this path happens not to exist".

`config/`, `tools/`, `dev/`, `src/`, `tests/`, `install/` (the directory — `install.php`
itself stays reachable, it's the routed install entry point), `vendor/`, `node_modules/`,
`docs/`, `deploy/`, `.git/` must never be served directly, on every supported front end —
nor must sensitive root-level tooling configs and dependency manifests: `composer.json`,
`composer.lock`, `package.json`, `bun.lock`, `phpstan.neon`, `psalm.xml`, `rector.php`,
`ecs.php`, `knip.json`, `lefthook.yml`, `tsconfig.json`, `.stylelintrc.json`, `.prettierrc.json`,
`lighthouserc.json`, `.size-limit.json`, `renovate.json`, `release-please-config.json`,
`.release-please-manifest.json`, `eslint-suppressions.json`, `.editorconfig`,
`.gitignore`, `.dockerignore`, `Dockerfile`, `docker-compose.yml`, `justfile`, `.env*`,
and any root `*.ts` config file. The directory list started narrower and the file list
didn't exist at all until a P4 audit found `vendor/`, `docs/`, `deploy/`, and every one of
those config files still returning `200` in the shipped image/Apache checkout —
`vendor/` in particular is copied into the production image via an explicit
`COPY --from=builder`, so excluding it from `.dockerignore` alone was never enough.
Shipped as:

- **Apache**: `public/.htaccess` (`mod_rewrite`-based, portable to any `REQUEST_URI`
  prefix a shared host might mount this under) — one rule for the denied directories,
  a second for the denied root files (matched by basename, not anchored to root).
- **FrankenPHP/Caddy**: `docker/Caddyfile` (baked into the production image) — same
  two-rule split via `@denied`/`@denied_files` matchers.
- **nginx** (not a runtime this project ships an image for, but commonly fronting one):

  ```nginx
  location ~ ^/(config|tools|dev|src|tests|install|vendor|node_modules|docs|deploy|\.git)/ {
      return 403;
  }

  location ~ ^/(composer\.(json|lock)|package\.json|bun\.lock|phpstan\.neon|psalm\.xml|rector\.php|ecs\.php|knip\.json|lefthook\.yml|tsconfig\.json|\.stylelintrc\.json|\.prettierrc\.json|lighthouserc\.json|\.size-limit\.json|renovate\.json|release-please-config\.json|\.release-please-manifest\.json|eslint-suppressions\.json|\.editorconfig|\.gitignore|\.dockerignore|Dockerfile|docker-compose\.ya?ml|justfile|\.env.*|[^/]+\.ts)$ {
      return 403;
  }

  location = /health {
      rewrite ^ /health.php last;
  }
  location = /ready {
      rewrite ^ /ready.php last;
  }

  location ~ \.[0-9a-f]{8}\.(js|css|woff2|avif|webp|png|jpg)$ {
      add_header Cache-Control "public, max-age=31536000, immutable";
  }

  location ~ ^/upload/.*\.(svg|html?)$ {
      add_header Content-Disposition attachment;
  }
  ```

All three are exercised by CI's cross-server deny-rule jobs (`.github/workflows/ci.yml`)
against Apache and the built container image — nginx is docs-only since this project
doesn't ship an nginx-based image.

[SEC-21] uploaded SVG/HTML is additionally forced to download instead of rendering
inline (`Content-Disposition: attachment`), matching the same three-target split above —
see `.htaccess`'s own comment for the full rationale.

## Environment variables

See `.env.example` for the full reference (`PIWIGO_DB_HOST`/`USER`/`PASSWORD`/`BASE`/
`PREFIX`). In containers these are set directly (compose `environment:`, Helm chart env)
rather than via an `.env` file — `include/env.inc.php`'s `pwg_load_env_file()` reads
`getenv()` either way, so no `.env` file needs to exist inside the image.
