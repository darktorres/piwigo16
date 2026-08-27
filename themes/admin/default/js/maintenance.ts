import type { operations } from "../../../../openapi/client/schema";

import { pwg_getPageString } from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import { ready } from "../../../default/js/vendor/dom";
export {};

const no_time_elapsed = pwg_getPageString("right now");
const unit_MB = pwg_getPageString("%s MB");

type CacheSizeResponse =
  operations["cacheSize"]["responses"][200]["content"]["application/json"];

function displayResponse(
  domElem: NodeListOf<HTMLElement>[],
  values: string[],
  mDivs: HTMLElement[],
  mValues: Record<string, string>,
) {
  for (let index = 0; index < domElem.length; index++) {
    // jQuery's `.html()` writes to every element of the set, not just the
    // first, so each of these three selectors keeps writing to all matches.
    domElem[index]!.forEach((node) => {
      node.innerHTML = unit_MB.replace("%s", values[index]!);
    });
  }

  for (let index = 0; index < mDivs.length; index++) {
    const mDivName = mDivs[index]!.getAttribute("name")!;
    mDivs[index]!.title = unit_MB.replace("%s", mValues[mDivName]!);
  }

  document.querySelectorAll(".cache-lastCalculated-value").forEach((node) => {
    node.innerHTML = no_time_elapsed;
  });
}

ready(function () {
  document.querySelectorAll(".refresh-cache-size").forEach((button) => {
    button.addEventListener("click", function () {
      button.querySelectorAll(".refresh-icon").forEach((icon) => {
        icon.classList.add("animate-spin");
      });

      // The original wrapped this in a `new Promise` returned from the
      // handler, purely to satisfy a lint rule during the TypeScript
      // conversion -- jQuery's `.on()` ignores any return value that is not
      // `false`, so nothing ever observed it. Dropped with the two
      // eslint-disable comments it needed.
      void ajax({
        url: "api/v1/cache-size",
        type: "GET",
        dataType: "json",
        success: function (payload) {
          const data = payload as CacheSizeResponse;

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

          // `.children(selector)` is direct children only, gathered across
          // every container in the set.
          const multipleSizes: HTMLElement[] = [];
          document
            .querySelectorAll(".delete-check-container")
            .forEach((container) => {
              for (const child of container.children) {
                if (
                  child instanceof HTMLElement &&
                  child.matches(".delete-size-check")
                ) {
                  multipleSizes.push(child);
                }
              }
            });

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
        },
        error: function (message) {
          console.log(message);
        },
      });
    });
  });
});
