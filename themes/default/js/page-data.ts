interface PwgPageDataPayload {
  data: Record<string, unknown>;
  strings: Record<string, string>;
}

let pwg_pageDataPayload: PwgPageDataPayload | null = null;

function pwg_getPageDataPayload(): PwgPageDataPayload {
  if (pwg_pageDataPayload === null) {
    const el = document.getElementById("page-data");
    pwg_pageDataPayload = el
      ? (JSON.parse(el.textContent ?? "{}") as PwgPageDataPayload)
      : { data: {}, strings: {} };
  }
  return pwg_pageDataPayload;
}

export function pwg_getPageData<T = unknown>(key: string): T {
  return pwg_getPageDataPayload().data[key] as T;
}

export function pwg_getPageString(key: string): string {
  return pwg_getPageDataPayload().strings[key]!;
}
