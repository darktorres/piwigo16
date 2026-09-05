import type { operations } from "../../../../../openapi/client/schema";

import { pwg_getPageString } from "../../../../default/js/pageData";
import { ajax, AjaxError } from "../../../../default/js/vendor/utils/ajax";
import { ready, valueAt } from "../../../../default/js/vendor/utils/dom";

const noTimeElapsed = pwg_getPageString("right now");
const unitMb = pwg_getPageString("%s MB");

type CacheSizeResponse =
  operations["cacheSize"]["responses"][200]["content"]["application/json"];

function displayResponse(
  domElem: NodeListOf<HTMLElement>[],
  values: string[],
  mDivs: HTMLElement[],
  mValues: Record<string, string>,
) {
  for (const [index, elements] of domElem.entries()) {
    // jQuery's `.html()` writes to every element of the set, not just the
    // first, so each of these three selectors keeps writing to all matches.
    elements.forEach((node) => {
      node.innerHTML = unitMb.replace("%s", valueAt(values, index));
    });
  }

  for (const mDiv of mDivs) {
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real mDiv here (collectMultipleSizeCheckboxes()'s own ".delete-size-check" children) always has a real name attribute.
    const mDivName = mDiv.getAttribute("name")!;
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- the API's own response always includes a msizes entry for every real cache-size checkbox name.
    mDiv.title = unitMb.replace("%s", mValues[mDivName]!);
  }

  document.querySelectorAll(".cache-lastCalculated-value").forEach((node) => {
    node.innerHTML = noTimeElapsed;
  });
}

function collectMultipleSizeCheckboxes(): HTMLElement[] {
  // `.children(selector)` is direct children only, gathered across
  // every container in the set.
  const multipleSizes: HTMLElement[] = [];
  document.querySelectorAll(".delete-check-container").forEach((container) => {
    for (const child of container.children) {
      if (child instanceof HTMLElement && child.matches(".delete-size-check")) {
        multipleSizes.push(child);
      }
    }
  });

  return multipleSizes;
}

async function refreshCacheSize(button: Element): Promise<void> {
  button.querySelectorAll(".refresh-icon").forEach((icon) => {
    icon.classList.add("animate-spin");
  });

  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const data = (await ajax({
      url: "api/v1/cache-size",
      type: "GET",
      dataType: "json",
    })) as CacheSizeResponse;

    const domElemToRefresh = [
      document.querySelectorAll<HTMLElement>(".cache-size-value"),
      document.querySelectorAll<HTMLElement>(".multiple-pictures-sizes"),
      document.querySelectorAll<HTMLElement>(
        ".multiple-compiledTemplate-sizes",
      ),
    ];
    const domElemValues: string[] = [
      data.cacheSize,
      data.msizes["all"],
      data.templatesSize,
    ].map((v) => ((v ?? 0) / 1024 / 1024).toFixed(2));

    const multipleSizes = collectMultipleSizeCheckboxes();

    const multipleSizesValues: Record<string, string> = {};
    for (const [key, value] of Object.entries(data.msizes)) {
      multipleSizesValues[key] = (value / 1024 / 1024).toFixed(2);
    }

    displayResponse(
      domElemToRefresh,
      domElemValues,
      multipleSizes,
      multipleSizesValues,
    );

    document.querySelectorAll(".animate-spin").forEach((node) => {
      node.classList.remove("animate-spin");
    });
  } catch (e) {
    console.error(e instanceof AjaxError ? e.responseText : e);
  }
}

ready(function () {
  document.querySelectorAll(".refresh-cache-size").forEach((button) => {
    button.addEventListener("click", function () {
      void refreshCacheSize(button);
    });
  });
});
