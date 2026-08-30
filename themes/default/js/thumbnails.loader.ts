import { pwg_getPageData } from "./page-data";
import { attr, data, hide, ready, show } from "./vendor/dom";
export {};

let max_requests = pwg_getPageData<number | undefined>("max_requests");
if (typeof max_requests == "undefined") max_requests = 3;

// Still jQuery: jquery.ajaxmanager is a library, ported in P49-B group 2.
const thumbnails_queue = jQuery.manageAjax.create("queued", {
  queue: true,
  cacheResponse: false,
  maxRequests: max_requests,
  preventDoubleRequests: false,
});

function add_thumbnail_to_queue(img: Element, loop: number) {
  thumbnails_queue.add({
    type: "GET",
    url: data(img, "src"),
    data: { ajaxload: "true" },
    dataType: "json",
    beforeSend: function () {
      show(document.querySelectorAll(".loader"));
    },
    success: function (result: { url: string }) {
      attr(img, "src", result.url);
      hide(document.querySelectorAll(".loader"));
    },
    error: function () {
      if (loop < 3) add_thumbnail_to_queue(img, ++loop); // Retry 3 times
      const error_icon = pwg_getPageData<string>("error_icon");
      if (typeof error_icon != "undefined") attr(img, "src", error_icon);
      hide(document.querySelectorAll(".loader"));
    },
  });
}

function pwg_ajax_thumbnails_loader() {
  document.querySelectorAll("img[data-src]").forEach(function (img) {
    add_thumbnail_to_queue(img, 0);
  });
}

ready(pwg_ajax_thumbnails_loader);
