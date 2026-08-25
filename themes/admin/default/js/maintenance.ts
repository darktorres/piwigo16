import type { operations } from "../../../../openapi/client/schema";

import { pwg_getPageString } from "../../../default/js/page-data?dup";
export {};

const no_time_elapsed = pwg_getPageString("right now");
const unit_MB = pwg_getPageString("%s MB");

type CacheSizeResponse =
  operations["cacheSize"]["responses"][200]["content"]["application/json"];

function displayResponse(
  domElem: JQuery[],
  values: string[],
  mDivs: JQuery,
  mValues: Record<string, string>,
) {
  for (let index = 0; index < domElem.length; index++) {
    domElem[index]!.html(unit_MB.replace("%s", values[index]!));
  }

  for (let index = 0; index < mDivs.length; index++) {
    const mDivName = (mDivs[index] as HTMLElement).getAttribute("name")!;
    (mDivs[index] as HTMLElement).title = unit_MB.replace(
      "%s",
      mValues[mDivName]!,
    );
  }

  $(".cache-lastCalculated-value").html(no_time_elapsed);
}

$(document).ready(function () {
  // eslint-disable-next-line @typescript-eslint/no-misused-promises -- returns a Promise jQuery's .on() never awaits either way, same as the original .js; fire-and-forget by design.
  $(".refresh-cache-size").on("click", function () {
    $(this).find(".refresh-icon").addClass("animate-spin");

    return new Promise<void>((res, rej) => {
      jQuery.ajax({
        url: "api/v1/cache-size",
        type: "GET",
        dataType: "json",
        success: function (data: CacheSizeResponse) {
          res();

          const domElemToRefresh = [
            $(".cache-size-value"),
            $(".multiple-pictures-sizes"),
            $(".multiple-compiledTemplate-sizes"),
          ];
          const domElemValues: string[] = [
            data.cacheSize,
            data.msizes["all"],
            data.templatesSize,
          ].map((v) => ((v ?? 0) / 1024 / 1024).toFixed(2));

          const multipleSizes = $(".delete-check-container").children(
            ".delete-size-check",
          );
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

          $(".animate-spin").removeClass("animate-spin");
        },
        error: function (message: JQuery.jqXHR) {
          // eslint-disable-next-line @typescript-eslint/prefer-promise-reject-errors -- rejects with the real jqXHR error object, matching the original .js's own console.log(message) usage; not a new Error.
          rej(message);
          console.log(message);
        },
      });
    });
  });
});
