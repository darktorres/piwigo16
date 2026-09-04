import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax, AjaxError } from "../../../default/js/vendor/utils/ajax";
import {
  append,
  attr,
  hasClass,
  hide,
  html,
  is,
  on,
  prepend,
  ready,
  val,
} from "../../../default/js/vendor/utils/dom";
import type { operations } from "../../../../openapi/client/schema";

const piwigoNeedUpdateMsg =
  '<a href="admin.php?page=updates">' +
  pwg_getPageString("A new version of Piwigo is available.") +
  ' <i class="icon-right"></i></a>';
const extNeedUpdateMsg =
  '<a href="admin.php?page=updates&amp;tab=ext">' +
  pwg_getPageString("Some upgrades are available for extensions.") +
  ' <i class="icon-right"></i></a>';
const strGbUsed = pwg_getPageString("%s GB used");
const strMbUsed = pwg_getPageString("%s MB used");
export const strGb = pwg_getPageString("%sGB").replace(" ", "&nbsp;");
export const strMb = pwg_getPageString("%sMB").replace(" ", "&nbsp;");
const storageTotal = pwg_getPageData<number>("storage_total");
export const storageDetails =
  pwg_getPageData<StorageDetails>("storage_chart_data");
export const translateFiles = pwg_getPageString("%d files");
const newsletterBaseUrl = pwg_getPageData<string | null>("subscribe_base_url");

export const translateType: Record<string, string> = {};
Object.keys(storageDetails).forEach(function (type) {
  translateType[type] = pwg_getPageString(type);
});

ready(function () {
  // No `.cluetip`-classed markup is ever rendered on this page (verified:
  // neither statically in intro.latte nor dynamically by this file's own
  // newsletter-promo injection below, which uses `.tiptip` instead,
  // already ported in P49-B group 2) -- the old jQuery `.cluetip()` call
  // that used to sit here was a genuine no-op and is not ported.

  if (pwg_getPageData<boolean>("check_for_updates")) {
    void (async () => {
      try {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
        const data = (await ajax({
          type: "GET",
          url: "api/v1/extensions/updates",
          dataType: "json",
          timeout: 5000,
        })) as operations["extensionsCheckUpdates"]["responses"][200]["content"]["application/json"];

        const piwigoUpdate = data.piwigoNeedUpdate;
        const extUpdate = data.extNeedUpdate;
        if (
          (piwigoUpdate === true || extUpdate === true) &&
          !is(document.querySelectorAll(".warnings"), "div")
        )
          prepend(
            document.querySelectorAll(".eiw"),
            '<div class="warnings"><i class="eiw-icon icon-attention"></i><ul></ul></div>',
          );
        if (piwigoUpdate === true)
          append(
            document.querySelectorAll(".warnings ul"),
            "<li>" + piwigoNeedUpdateMsg + "</li>",
          );
        if (extUpdate === true)
          append(
            document.querySelectorAll(".warnings ul"),
            "<li>" + extNeedUpdateMsg + "</li>",
          );
      } catch (e) {
        console.error(e instanceof AjaxError ? e.responseText : e);
      }
    })();
  }

  if (newsletterBaseUrl !== null && newsletterBaseUrl !== "") {
    const newsletterEmail = pwg_getPageData<string | null>("email") ?? "";
    const oldNewslettersUrl =
      pwg_getPageData<string | null>("old_newsletters_url") ?? "";
    prepend(
      document.querySelectorAll(".eiw"),
      `
    <div class="promote-newsletter">
      <div class="promote-content">

        <img class="promote-image" src="themes/admin/default/images/promote-newsletter.png">

        <div class="promote-newsletter-content">
          <span class="promote-newsletter-title">${pwg_getPageString("Subscribe to our newsletter and stay updated!")}</span>
          <div class="promote-content subscribe-newsletter">
            <input type="text" id="newsletterSubscribeInput" value="${newsletterEmail}" class="left-side">
            <a href="${newsletterBaseUrl}${newsletterEmail}" id="newsletterSubscribeLink" class="right-side go-to-porg icon-thumbs-up newsletter-hide">${pwg_getPageString("Sign up to the newsletter")}</a>
          </div>
          <a href="${oldNewslettersUrl}" class="promote-link">${pwg_getPageString("See previous newsletters")}</a>
        </div>

      </div>
      <a href="#" class="dont-show-again icon-cancel tiptip newsletter-hide" title="${pwg_getPageString("Understood, do not show again")}"></a>
    </div>`,
    );
  }

  on(
    document.querySelectorAll("#newsletterSubscribeInput"),
    "change",
    function (): void {
      attr(
        document.querySelectorAll("#newsletterSubscribeLink"),
        "href",
        (newsletterBaseUrl ?? "") +
          String(val(document.querySelectorAll("#newsletterSubscribeInput"))),
      );
    },
  );

  on(
    document.querySelectorAll(".newsletter-hide"),
    "click",
    function (this: Element, event: Event): void {
      hide(document.querySelectorAll(".promote-newsletter"));

      void (async () => {
        try {
          await ajax({
            type: "GET",
            url: "admin.php?action=hide_newsletter_subscription",
          });
        } catch (e) {
          console.error(e instanceof AjaxError ? e.responseText : e);
        }
      })();

      if (hasClass(this, "newsletter-hide")) {
        event.preventDefault();
        event.stopPropagation();
      }
    },
  );
  const sizeInfo = storageTotal > 1000000 ? strGbUsed : strMbUsed;
  const sizeNb =
    storageTotal > 1000000
      ? (storageTotal / 1000000).toFixed(2)
      : (storageTotal / 1000).toFixed(0);
  html(
    document.querySelectorAll(".chart-title-infos"),
    sizeInfo.replace("%s", sizeNb),
  );
});
