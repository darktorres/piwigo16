import { css, cssValue, height, html, on, width } from "../utils/dom";

/**
 * Port of jquery.autogrow-textarea.js's own `$.fn.autogrow()` -- there is
 * no real upstream package for this exact "ripped from Facebook" snippet
 * (the one same-named real package found, rotundasoftware/jquery.autogrow-
 * textarea, is confirmed via a real content diff to be a different,
 * unrelated implementation -- see that file's own docblock).
 *
 * Grows each matched `<textarea>` to fit its content as the user types: a
 * hidden shadow `<div>`, styled to match the textarea's own font metrics,
 * renders the current text (HTML-escaped, with `<br/>` substituted for
 * `\n` -- a `<div>` doesn't wrap on a literal newline the way a
 * `<textarea>` does), and its resulting rendered height becomes the
 * textarea's new height. The shadow div is appended once per textarea and
 * never removed, matching the original's own lifetime.
 */
export function autogrow(elements: Iterable<Element>): void {
  for (const el of elements) {
    if (!(el instanceof HTMLTextAreaElement)) {
      continue;
    }

    const minHeight = height(el);

    const shadow = document.createElement("div");
    css(shadow, {
      position: "absolute",
      top: -10000,
      left: -10000,
      width: width(el),
      fontSize: cssValue(el, "fontSize"),
      fontFamily: cssValue(el, "fontFamily"),
      lineHeight: cssValue(el, "lineHeight"),
      resize: "none",
    });
    document.body.appendChild(shadow);

    const update = (): void => {
      const escaped = el.value
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/&/g, "&amp;")
        .replace(/\n/g, "<br/>");
      html(shadow, escaped);
      css(el, "height", Math.max(height(shadow) + 20, minHeight));
    };

    on(el, "change keyup keydown", update);
    update();
  }
}
