# Server & PHP Worker Comparison

Context: evaluating options to optimize `i.php` derivative image generation by keeping PHP bootstrapped between requests, eliminating per-request startup cost (config load, autoloader, DB connection, `ImageStdParams::load_from_db()`).

---

## PHP Worker: FrankenPHP vs RoadRunner

|  | FrankenPHP | RoadRunner |
|---|---|---|
| **Model** | PHP worker loop you control | Go binary manages a pool of PHP workers |
| **Windows support** | Yes (prebuilt binary) | Yes (prebuilt binary) |
| **Parallelism** | Multiple workers (processes) | Multiple workers (processes) |
| **Apache integration** | Awkward — it is the web server; requires a proxy hop from Apache | Clean — Apache proxies via `mod_proxy_fcgi` |
| **Protocol** | HTTP (Caddy built-in) | FastCGI / gRPC |
| **Bootstrap** | You write the bootstrap-once loop; FrankenPHP calls your handler per request | Same pattern via RoadRunner PSR-7 worker |
| **i.php adaptation** | Wrap in worker loop, adapt superglobals | Wrap in PSR-7 worker, map request/response |
| **Config** | Caddyfile | YAML |
| **Maturity** | Young, active | Production-proven |
| **Best fit** | Replacing Apache entirely | Keeping Apache as front door |

### Recommendation
If staying on Apache: **RoadRunner**. It slots in cleanly via FastCGI with no server migration.
If migrating off Apache: **FrankenPHP** — fewer moving parts, tighter integration.

---

## Web Server: Apache vs Caddy

|  | Apache 2.4 | Caddy |
|---|---|---|
| **HTTP/2** | Yes (mod_http2) | Yes, default |
| **HTTP/3 / QUIC** | No | Yes, default |
| **HTTPS** | Manual cert config | Automatic (Let's Encrypt) |
| **Static file serving** | Fast, battle-tested | Fast, comparable |
| **Config syntax** | Verbose, directive-based | Simple, Caddyfile |
| **Windows support** | Excellent, long-standing | Good |
| **PHP integration** | mod_php, mod_fcgid, mod_proxy_fcgi | Via FrankenPHP or reverse proxy |
| **FrankenPHP fit** | Proxy hop required | Native (FrankenPHP is built on Caddy) |
| **Ecosystem / docs** | Vast | Growing |

### Recommendation
For derivative image serving, the persistent PHP worker is the dominant gain — the choice of web server is secondary. Switch to Caddy only if HTTP/3 or simplified config are also goals. Otherwise stay on Apache.

---

## Combined Options

| Stack | Persistent bootstrap | Parallel workers | Disruption |
|---|---|---|---|
| Apache + mod_php (current) | Runtime only | Yes (threads) | — |
| Apache + RoadRunner | Yes | Yes | Low |
| Caddy + FrankenPHP | Yes | Yes | High (server migration) |
| Apache → Caddy + FrankenPHP | Yes | Yes | High |
