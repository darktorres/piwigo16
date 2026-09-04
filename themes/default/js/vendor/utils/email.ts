/**
 * A deliberate approximation of PHP's `FILTER_VALIDATE_EMAIL`, not a
 * byte-for-byte mirror (not practically replicable in JS) -- every real
 * caller treats the server as authoritative on submit; this is a
 * client-side format hint only.
 *
 * Written as plain string operations rather than the single regex this
 * replaced (`/^[^\s@]+@[^\s@]+\.[^\s@]+$/`, independently duplicated
 * across 3 files) -- that regex's middle `[^\s@]+` group can also match
 * the literal `.` that follows it, so a non-matching input backtracks
 * over every possible split point (super-linear runtime, flagged by
 * `sonarjs/super-linear-regex`). Linear string scans have no such
 * ambiguity to backtrack over.
 */
export function looksLikeEmail(value: string): boolean {
  const at = value.indexOf("@");
  if (at <= 0 || at !== value.lastIndexOf("@")) {
    return false;
  }

  const local = value.slice(0, at);
  const domain = value.slice(at + 1);
  if (/\s/.test(local) || /\s/.test(domain)) {
    return false;
  }

  const dot = domain.lastIndexOf(".");
  return dot > 0 && dot < domain.length - 1;
}
