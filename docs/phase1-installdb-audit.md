# Phase 1 — install/db audit

Audit performed 2026-04-27 against `install/db/*.php` (122 files, scripts 61 through 181).

## Patterns checked

| Pattern | Matches |
|---|---|
| `create_function` | **0** |
| `utf8_encode` | **0** |
| `utf8_decode` | **0** |
| `each($x)` (real calls, not `foreach`) | **0** |
| `${varname}` curly-brace variable interpolation | **0** |

## Conclusion

The 122 `install/db/*.php` upgrade scripts contain zero canonical PHP 8 breaks.
The DB-upgrade-compatibility constraint (constraint #3) holds with no surgery.
`UpgradeChainTest` remains the gating regression check for this chain.
