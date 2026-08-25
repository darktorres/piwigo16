import { pwg_getPageData } from "./page-data?dup";
export {};

let max_requests = pwg_getPageData<number | undefined>("max_requests");
if (typeof max_requests == "undefined") max_requests = 3;

const thumbnails_queue = jQuery.manageAjax.create("queued", {
  queue: true,
  cacheResponse: false,
  maxRequests: max_requests,
  preventDoubleRequests: false,
});

function add_thumbnail_to_queue(img: JQuery, loop: number) {
  thumbnails_queue.add({
    type: "GET",
    url: img.data("src"),
    data: { ajaxload: "true" },
    dataType: "json",
    beforeSend: function () {
      jQuery(".loader").show();
    },
    success: function (result: { url: string }) {
      img.attr("src", result.url);
      jQuery(".loader").hide();
    },
    error: function () {
      if (loop < 3) add_thumbnail_to_queue(img, ++loop); // Retry 3 times
      const error_icon = pwg_getPageData<string>("error_icon");
      if (typeof error_icon != "undefined") img.attr("src", error_icon);
      jQuery(".loader").hide();
    },
  });
}

function pwg_ajax_thumbnails_loader() {
  jQuery("img[data-src]").each(function () {
    add_thumbnail_to_queue(jQuery(this), 0);
  });
}

jQuery(document).ready(pwg_ajax_thumbnails_loader);
