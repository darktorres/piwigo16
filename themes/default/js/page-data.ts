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

// eslint-disable-next-line @typescript-eslint/no-unnecessary-type-parameters -- the single use is the point: page-data is an untyped JSON blob, and `T` is the caller's own assertion about the shape behind a key, exactly as `ajax<T>` works. Removing it would push a cast to every one of ~100 call sites.
export function pwg_getPageData<T = unknown>(key: string): T {
  return pwg_getPageDataPayload().data[key] as T;
}

export function pwg_getPageString(key: string): string {
  return pwg_getPageDataPayload().strings[key]!;
}
