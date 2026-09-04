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

export function sprintf(...args: (string | number)[]): string {
  let i = 0,
    // Genuinely polymorphic per format specifier (%b/%d/%x reinterpret
    // as number, %s coerces to string, %c reinterprets as a char code)
    // -- irreducible without a much larger rewrite of this well-known
    // sprintf implementation, not this phase's job.
    a: any,
    // The first argument is always the format-pattern string, never one
    // of the `%s`/`%d`-substituted values `args`'s own looser type
    // covers -- every real call site passes a literal string here.
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified above.
    f = args[i++] as string,
    m: RegExpExecArray | null,
    p: string,
    c: string,
    x: number;
  const o: string[] = [],
    s = "";
  while (f) {
    if ((m = /^[^\x25]+/.exec(f))) {
      o.push(m[0]);
    } else if ((m = /^\x25{2}/.exec(f))) {
      o.push("%");
    } else if (
      (m =
        /^\x25(?:(\d+)\$)?(\+)?(0|'[^$])?(-)?(\d+)?(?:\.(\d+))?([b-fosuxX])/.exec(
          f,
        ))
    ) {
      if (
        (a = args[m[1] !== undefined ? Number(m[1]) : i++]) == null ||
        a === undefined
      ) {
        throw new Error("Too few arguments.");
      }
      if (/[^s]/.test(m[7]!) && typeof a !== "number") {
        throw new Error("Expecting number but found " + typeof a);
      }

      switch (m[7]!) {
        case "b":
          a = a.toString(2);
          break;
        case "c":
          a = String.fromCharCode(a);
          break;
        case "d":
          a = parseInt(a);
          break;
        case "e":
          a =
            m[6] !== undefined
              ? a.toExponential(Number(m[6]))
              : a.toExponential();
          break;
        case "f":
          a =
            m[6] !== undefined
              ? parseFloat(a).toFixed(Number(m[6]))
              : parseFloat(a);
          break;
        case "o":
          a = a.toString(8);
          break;
        case "s":
          a =
            (a = String(a)) && m[6] !== undefined
              ? a.substring(0, Number(m[6]))
              : a;
          break;
        case "u":
          a = Math.abs(a);
          break;
        case "x":
          a = a.toString(16);
          break;
        case "X":
          a = a.toString(16).toUpperCase();
          break;
      }

      a =
        /[def]/.test(m[7]!) && m[2] !== undefined && a >= 0
          ? "+" + String(a)
          : a;
      c = m[3] !== undefined ? (m[3] === "0" ? "0" : m[3].charAt(1)) : " ";
      x = Number(m[5]) - String(a).length - s.length;
      p = m[5] !== undefined ? str_repeat(c, x) : "";
      o.push(s + (m[4] !== undefined ? String(a) + p : p + String(a)));
    } else {
      throw new Error("Huh ?!");
    }

    f = f.substring(m[0].length);
  }

  return o.join("");
}
