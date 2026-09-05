import { pwg_getPageData } from "./pageData";
import { on } from "./vendor/utils/dom";

function resolveNavUrl(event: KeyboardEvent): string | undefined {
  const docElem = document.documentElement;
  switch (event.key) {
    case "ArrowRight":
      if (
        event.ctrlKey ||
        docElem.scrollLeft === docElem.scrollWidth - docElem.clientWidth
      )
        return pwg_getPageData<string | undefined>("nav_next_url");
      return undefined;
    case "ArrowLeft":
      if (event.ctrlKey || docElem.scrollLeft === 0)
        return pwg_getPageData<string | undefined>("nav_previous_url");
      return undefined;
    case "Home":
      if (event.ctrlKey)
        return pwg_getPageData<string | undefined>("nav_first_url");
      return undefined;
    case "End":
      if (event.ctrlKey)
        return pwg_getPageData<string | undefined>("nav_last_url");
      return undefined;
    case "ArrowUp":
      if (event.ctrlKey)
        return pwg_getPageData<string | undefined>("nav_up_url");
      return undefined;
    case " ":
      // Pause / Play
      return (
        pwg_getPageData<string | undefined>("nav_slideshow_start_url") ??
        pwg_getPageData<string | undefined>("nav_slideshow_stop_url")
      );
    default:
      return undefined;
  }
}

on(document, "keydown", function (e: Event) {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keydown" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
  const event = e as KeyboardEvent;
  if (event.altKey) return;
  // eslint-disable-next-line @typescript-eslint/no-unnecessary-type-assertion -- tsc genuinely needs this: plain EventTarget has no `.type` member, and removing the cast is a real TS2339 (confirmed directly against tsc). The lint rule appears to treat `T & {optional?: U}` as assignability-equivalent to bare `T` and false-positives here.
  const target = event.target as (EventTarget & { type?: string }) | null;
  if (target?.type !== undefined && target.type !== "") return; // an input editable element
  const url = resolveNavUrl(event);
  if (url !== undefined && url !== "") {
    window.location.href = url.replace("&amp;", "&");
    // A DOM0 handler property's own `return false` suppresses only the
    // default action, not propagation (unlike jQuery's `return false`,
    // which does both) -- this real listener form has no return-value
    // control at all, so the same suppression needs preventDefault().
    e.preventDefault();
  }
});
