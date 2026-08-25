import { pwg_getPageData } from "./page-data?dup";
export {};

document.onkeydown = function (e: KeyboardEvent) {
  e = e || (window.event as KeyboardEvent);
  if (e.altKey) return true;
  // eslint-disable-next-line @typescript-eslint/no-unnecessary-type-assertion -- tsc genuinely needs this: plain EventTarget has no `.type` member, and removing the cast is a real TS2339 (confirmed directly against tsc). The lint rule appears to treat `T & {optional?: U}` as assignability-equivalent to bare `T` and false-positives here.
  const target = (e.target || e.srcElement) as
    (EventTarget & { type?: string }) | null;
  if (target && target.type) return true; // an input editable element
  const keyCode = e.keyCode || e.which,
    docElem = document.documentElement;
  let url: string | undefined;
  switch (keyCode) {
    case 63235:
    case 39:
      if (
        e.ctrlKey ||
        docElem.scrollLeft === docElem.scrollWidth - docElem.clientWidth
      )
        url = pwg_getPageData<string | undefined>("nav_next_url");
      break;
    case 63234:
    case 37:
      if (e.ctrlKey || docElem.scrollLeft === 0)
        url = pwg_getPageData<string | undefined>("nav_previous_url");
      break;
    case 36:
      // Home
      if (e.ctrlKey) url = pwg_getPageData<string | undefined>("nav_first_url");
      break;
    case 35:
      // End
      if (e.ctrlKey) url = pwg_getPageData<string | undefined>("nav_last_url");
      break;
    case 38:
      // Up
      if (e.ctrlKey) url = pwg_getPageData<string | undefined>("nav_up_url");
      break;
    case 32:
      // Pause / Play
      url =
        pwg_getPageData<string | undefined>("nav_slideshow_start_url") ||
        pwg_getPageData<string | undefined>("nav_slideshow_stop_url");
      break;
  }
  if (url) {
    window.location.href = url.replace("&amp;", "&");
    return false;
  }
  return true;
};
