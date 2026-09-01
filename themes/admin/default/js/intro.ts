import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
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
} from "../../../default/js/vendor/dom";

const piwigo_need_update_msg =
  '<a href="admin.php?page=updates">' +
  pwg_getPageString("A new version of Piwigo is available.") +
  ' <i class="icon-right"></i></a>';
const ext_need_update_msg =
  '<a href="admin.php?page=updates&amp;tab=ext">' +
  pwg_getPageString("Some upgrades are available for extensions.") +
  ' <i class="icon-right"></i></a>';
const str_gb_used = pwg_getPageString("%s GB used");
const str_mb_used = pwg_getPageString("%s MB used");
export const str_gb = pwg_getPageString("%sGB").replace(" ", "&nbsp;");
export const str_mb = pwg_getPageString("%sMB").replace(" ", "&nbsp;");
const storage_total = pwg_getPageData<number>("storage_total");
export const storage_details =
  pwg_getPageData<StorageDetails>("storage_chart_data");
export const translate_files = pwg_getPageString("%d files");
const newsletter_base_url = pwg_getPageData<string | null>(
  "subscribe_base_url",
);

export const translate_type: Record<string, string> = {};
Object.keys(storage_details).forEach(function (type) {
  translate_type[type] = pwg_getPageString(type);
});

ready(function () {
  // No `.cluetip`-classed markup is ever rendered on this page (verified:
  // neither statically in intro.latte nor dynamically by this file's own
  // newsletter-promo injection below, which uses `.tiptip` instead,
  // already ported in P49-B group 2) -- the old jQuery `.cluetip()` call
  // that used to sit here was a genuine no-op and is not ported.

  if (pwg_getPageData<boolean>("check_for_updates")) {
    void ajax({
      type: "GET",
      url: "api/v1/extensions/updates",
      dataType: "json",
      timeout: 5000,
      success: function (
        data: import("../../../../openapi/client/schema").operations["extensionsCheckUpdates"]["responses"][200]["content"]["application/json"],
      ) {
        const piwigo_update = data["piwigoNeedUpdate"];
        const ext_update = data["extNeedUpdate"];
        if (
          (piwigo_update || ext_update) &&
          !is(document.querySelectorAll(".warnings"), "div")
        )
          prepend(
            document.querySelectorAll(".eiw"),
            '<div class="warnings"><i class="eiw-icon icon-attention"></i><ul></ul></div>',
          );
        if (piwigo_update)
          append(
            document.querySelectorAll(".warnings ul"),
            "<li>" + piwigo_need_update_msg + "</li>",
          );
        if (ext_update)
          append(
            document.querySelectorAll(".warnings ul"),
            "<li>" + ext_need_update_msg + "</li>",
          );
      },
    });
  }

  if (pwg_getPageData<string | null>("subscribe_base_url")) {
    prepend(
      document.querySelectorAll(".eiw"),
      `
    <div class="promote-newsletter">
      <div class="promote-content">

        <img class="promote-image" src="themes/admin/default/images/promote-newsletter.png">

        <div class="promote-newsletter-content">
          <span class="promote-newsletter-title">${pwg_getPageString("Subscribe to our newsletter and stay updated!")}</span>
          <div class="promote-content subscribe-newsletter">
            <input type="text" id="newsletterSubscribeInput" value="${pwg_getPageData<string | null>("email") || ""}" class="left-side">
            <a href="${pwg_getPageData<string | null>("subscribe_base_url")}${pwg_getPageData<string | null>("email") || ""}" id="newsletterSubscribeLink" class="right-side go-to-porg icon-thumbs-up newsletter-hide">${pwg_getPageString("Sign up to the newsletter")}</a>
          </div>
          <a href="${pwg_getPageData<string | null>("old_newsletters_url") || ""}" class="promote-link">${pwg_getPageString("See previous newsletters")}</a>
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
        newsletter_base_url +
          String(val(document.querySelectorAll("#newsletterSubscribeInput"))),
      );
    },
  );

  on(
    document.querySelectorAll(".newsletter-hide"),
    "click",
    function (event: Event): void {
      hide(document.querySelectorAll(".promote-newsletter"));

      void ajax({
        type: "GET",
        url: "admin.php?action=hide_newsletter_subscription",
      });

      if (hasClass(event.currentTarget as Element, "newsletter-hide")) {
        event.preventDefault();
        event.stopPropagation();
      }
    },
  );
  const size_info = storage_total > 1000000 ? str_gb_used : str_mb_used;
  const size_nb =
    storage_total > 1000000
      ? (storage_total / 1000000).toFixed(2)
      : (storage_total / 1000).toFixed(0);
  html(
    document.querySelectorAll(".chart-title-infos"),
    size_info.replace("%s", size_nb),
  );
});
