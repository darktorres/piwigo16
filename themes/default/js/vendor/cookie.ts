/**
 * Port of jquery.cookie.js v1.4.1's own `$.cookie()` getter/setter
 * (CDN-vendored, P46-0's own CDN migration -- real source read from
 * https://cdn.jsdelivr.net/npm/jquery.cookie@1.4.1/jquery.cookie.js).
 * Real call sites here never pass a 3rd `options` argument (expires/
 * path/domain/secure), read/write more than one cookie at a time, or use
 * the plugin's own `raw`/`json`/converter-function modes, so only the
 * plain string get/set this app actually uses is ported; `$.removeCookie()`
 * has no real call site either.
 */

/**
 * jquery.cookie.js's own `parseCookieValue()`: strips RFC2068 quoting
 * (`\"..\"`) a server might have written, then decodes -- a cookie this
 * app can't decode is treated as absent, matching the original's silent
 * try/catch.
 */
function parseCookieValue(raw: string): string | undefined {
  let value = raw;
  if (value.startsWith('"')) {
    value = value.slice(1, -1).replace(/\\"/g, '"').replace(/\\\\/g, "\\");
  }
  try {
    return decodeURIComponent(value.replace(/\+/g, " "));
  } catch {
    return undefined;
  }
}

/** `$.cookie(name)` -- `undefined` when absent, matching the original. */
export function cookie(name: string): string | undefined {
  const cookies = document.cookie ? document.cookie.split("; ") : [];
  for (const entry of cookies) {
    const parts = entry.split("=");
    const entryName = decodeURIComponent(parts.shift() ?? "");
    if (entryName === name) {
      return parseCookieValue(parts.join("="));
    }
  }

  return undefined;
}

/**
 * `$.cookie(name, value)` -- a session cookie, no expires/path/domain
 * (the original's own defaults when no `options` argument is passed).
 */
export function setCookie(name: string, value: string | number): void {
  document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(String(value))}`;
}
