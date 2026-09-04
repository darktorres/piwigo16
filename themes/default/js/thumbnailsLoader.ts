import { pwg_getPageData } from "./pageData";
import { AjaxQueue } from "./vendor/utils/ajaxQueue";
import { attr, data, hide, ready, show } from "./vendor/utils/dom";

let maxRequests = pwg_getPageData<number | undefined>("max_requests");
if (typeof maxRequests === "undefined") maxRequests = 3;

const thumbnailsQueue = new AjaxQueue({
  maxRequests,
  preventDoubleRequests: false,
});

function addThumbnailToQueue(img: Element, loop: number) {
  const src = data(img, "src");
  if (typeof src !== "string") {
    return;
  }

  thumbnailsQueue.add({
    type: "GET",
    url: src,
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
      if (loop < 3) addThumbnailToQueue(img, loop + 1); // Retry 3 times
      const errorIcon = pwg_getPageData<string>("error_icon");
      if (typeof errorIcon !== "undefined") attr(img, "src", errorIcon);
      hide(document.querySelectorAll(".loader"));
    },
  });
}

function pwg_ajax_thumbnails_loader() {
  document.querySelectorAll("img[data-src]").forEach(function (img) {
    addThumbnailToQueue(img, 0);
  });
}

ready(pwg_ajax_thumbnails_loader);
