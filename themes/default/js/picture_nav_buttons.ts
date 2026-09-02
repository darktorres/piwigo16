import { pwg_getPageData } from "./page-data";
export {};

document.onkeydown = function (e: KeyboardEvent) {
  if (e.altKey) return true;
  // eslint-disable-next-line @typescript-eslint/no-unnecessary-type-assertion -- tsc genuinely needs this: plain EventTarget has no `.type` member, and removing the cast is a real TS2339 (confirmed directly against tsc). The lint rule appears to treat `T & {optional?: U}` as assignability-equivalent to bare `T` and false-positives here.
  const target = e.target as (EventTarget & { type?: string }) | null;
  if (target && target.type !== undefined && target.type !== "") return true; // an input editable element
  const docElem = document.documentElement;
  let url: string | undefined;
  switch (e.key) {
    case "ArrowRight":
      if (
        e.ctrlKey ||
        docElem.scrollLeft === docElem.scrollWidth - docElem.clientWidth
      )
        url = pwg_getPageData<string | undefined>("nav_next_url");
      break;
    case "ArrowLeft":
      if (e.ctrlKey || docElem.scrollLeft === 0)
        url = pwg_getPageData<string | undefined>("nav_previous_url");
      break;
    case "Home":
      if (e.ctrlKey) url = pwg_getPageData<string | undefined>("nav_first_url");
      break;
    case "End":
      if (e.ctrlKey) url = pwg_getPageData<string | undefined>("nav_last_url");
      break;
    case "ArrowUp":
      if (e.ctrlKey) url = pwg_getPageData<string | undefined>("nav_up_url");
      break;
    case " ":
      // Pause / Play
      url =
        pwg_getPageData<string | undefined>("nav_slideshow_start_url") ??
        pwg_getPageData<string | undefined>("nav_slideshow_stop_url");
      break;
  }
  if (url !== undefined && url !== "") {
    window.location.href = url.replace("&amp;", "&");
    return false;
  }
  return true;
};
