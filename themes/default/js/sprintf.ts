// str_repeat stays module-private (P48) -- sprintf() below is its only
// real caller anywhere in this codebase; array_delete (the same
// original comment's other established shared-global) had zero real
// callers anywhere, `.ts` or `.latte`, and was removed outright rather
// than exported to nothing (Legacy porting: no permanent facades).
function str_repeat(i: string, m: number): string {
  const o: string[] = [];
  for (let count = m; count > 0; o[--count] = i);
  return o.join("");
}

/**
 * Uniform integer in `[min, max)`. Its one real caller
 * (`users/list.ts`'s own `genPassword()`) feeds this into a generated
 * password, so this draws from `crypto.getRandomValues()` rather than
 * `Math.random()` -- the latter is not a cryptographically secure PRNG
 * in any JS engine, and its internal state can be predicted from
 * enough samples. Rejection sampling (drop values above the largest
 * multiple of `range` a `Uint32` can hold) keeps the result uniform
 * rather than introducing modulo bias.
 */
export function getRandomInt(min: number, max: number): number {
  const lo = Math.ceil(min);
  const hi = Math.floor(max);
  const range = hi - lo;
  if (range <= 0) {
    return lo;
  }

  const totalUint32Values = 0x100000000;
  const rejectionLimit = Math.floor(totalUint32Values / range) * range;
  const buf = new Uint32Array(1);
  let value: number;
  do {
    crypto.getRandomValues(buf);
    value = buf[0]!;
  } while (value >= rejectionLimit);

  return lo + (value % range);
}

/**
 * Part of `sprintf()`'s own extraction, below -- the per-specifier
 * value conversion (`%b`/`%c`/`%d`/`%e`/`%f`/`%o`/`%s`/`%u`/`%x`/`%X`).
 * Genuinely polymorphic (see `sprintf()`'s own leading comment on `a`).
 */
function convertSprintfValue(
  a: any,
  spec: string,
  precision: number | undefined,
): any {
  switch (spec) {
    case "b":
      return a.toString(2);
    case "c":
      return String.fromCharCode(a);
    case "d":
      return parseInt(a);
    case "e":
      return precision !== undefined
        ? a.toExponential(precision)
        : a.toExponential();
    case "f":
      return precision !== undefined
        ? parseFloat(a).toFixed(precision)
        : parseFloat(a);
    case "o":
      return a.toString(8);
    case "s": {
      const str = String(a);
      return precision !== undefined ? str.substring(0, precision) : str;
    }
    case "u":
      return Math.abs(a);
    case "x":
      return a.toString(16);
    case "X":
      return a.toString(16).toUpperCase();
    default:
      return a;
  }
}

/** Part of `sprintf()`'s own extraction, below -- the `%+d`-style sign prefix. */
function applySprintfSignPrefix(
  value: any,
  spec: string,
  hasPlusFlag: boolean,
): any {
  if (/[def]/.test(spec) && hasPlusFlag && value >= 0) {
    return "+" + String(value);
  }
  return value;
}

/** Part of `sprintf()`'s own extraction, below -- the padding character a `0`/`'`-flag selects. */
function resolveSprintfPaddingChar(flag: string | undefined): string {
  if (flag === undefined) {
    return " ";
  }
  if (flag === "0") {
    return "0";
  }
  return flag.charAt(1);
}

/**
 * Part of `sprintf()`'s own extraction, below -- formats one matched
 * `%...` conversion specifier into its final substituted text, and
 * returns the next positional-argument index to consume from (`i`
 * only actually advances for a non-positional `%s`-style specifier;
 * an explicit `%N$` one reads `args[N]` directly, same as before).
 */
function formatSprintfSpecifier(
  m: RegExpExecArray,
  args: (string | number)[],
  i: number,
  s: string,
): { text: string; nextI: number } {
  let nextI = i;
  // Genuinely polymorphic per format specifier (%b/%d/%x reinterpret as
  // number, %s coerces to string, %c reinterprets as a char code) --
  // irreducible without a much larger rewrite of this well-known
  // sprintf implementation, not this phase's job.
  let a: any = m[1] !== undefined ? args[Number(m[1])] : args[nextI++];
  if (a == null || a === undefined) {
    throw new Error("Too few arguments.");
  }
  if (/[^s]/.test(m[7]!) && typeof a !== "number") {
    throw new Error("Expecting number but found " + typeof a);
  }

  a = convertSprintfValue(
    a,
    m[7]!,
    m[6] !== undefined ? Number(m[6]) : undefined,
  );
  a = applySprintfSignPrefix(a, m[7]!, m[2] !== undefined);
  const c = resolveSprintfPaddingChar(m[3]);
  const x = Number(m[5]) - String(a).length - s.length;
  const p = m[5] !== undefined ? str_repeat(c, x) : "";
  const text = s + (m[4] !== undefined ? String(a) + p : p + String(a));
  return { text, nextI };
}

export function sprintf(...args: (string | number)[]): string {
  let i = 0,
    // The first argument is always the format-pattern string, never one
    // of the `%s`/`%d`-substituted values `args`'s own looser type
    // covers -- every real call site passes a literal string here.
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified above.
    f = args[i++] as string,
    m: RegExpExecArray | null;
  const o: string[] = [],
    s = "";
  while (f) {
    m = /^[^\x25]+/.exec(f);
    if (m) {
      o.push(m[0]);
      f = f.substring(m[0].length);
      continue;
    }
    m = /^\x25{2}/.exec(f);
    if (m) {
      o.push("%");
      f = f.substring(m[0].length);
      continue;
    }
    m =
      /^\x25(?:(\d+)\$)?(\+)?(0|'[^$])?(-)?(\d+)?(?:\.(\d+))?([b-fosuxX])/.exec(
        f,
      );
    if (m) {
      const { text, nextI } = formatSprintfSpecifier(m, args, i, s);
      i = nextI;
      o.push(text);
    } else {
      throw new Error("Huh ?!");
    }

    f = f.substring(m[0].length);
  }

  return o.join("");
}
