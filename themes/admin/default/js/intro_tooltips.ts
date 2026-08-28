import {
  str_gb,
  str_mb,
  storage_details,
  translate_files,
  translate_type,
} from "./intro";
import {
  css,
  cssValue,
  data as readData,
  hide,
  innerHeight,
  innerWidth,
  offset,
  on,
  off,
  parseHtml,
  position,
  ready,
  show,
  width,
  windowHeight,
} from "../../../default/js/vendor/dom";

/** jQuery's `.html(value)` writes to every element of the set. */
function setHtml(selector: string, value: string): void {
  document.querySelectorAll(selector).forEach((element) => {
    element.innerHTML = value;
  });
}

ready(function () {
  Object.entries(storage_details).forEach(([type, rawInfos]) => {
    // `Object.entries()` infers `StorageDetails`'s index-signature value
    // type directly, so `rawInfos` needs no cast. It used to: intro.ts
    // imported `pwg_getPageData` through a `?dup` specifier, which
    // resolved to `any`, so `storage_details` was `any` and the generic
    // overload had nothing to infer from -- a cast two files away from
    // the actual cause.
    const infos = rawInfos;
    // Determine if we use MB or GB and show it correctly
    const size = infos.total.filesize;
    const str_size_type_string = size > 1048576 ? str_gb : str_mb;
    const size_nb =
      size > 1048576 ? (size / 1048576).toFixed(2) : (size / 1024).toFixed(0);
    const str_size = str_size_type_string.replace("%s", size_nb);

    // Display head of Tooltip
    setHtml("#storage-title-" + type, "<b>" + translate_type[type] + "</b>");
    setHtml("#storage-size-" + type, "<b>" + str_size + "</b>");
    setHtml(
      "#storage-files-" + type,
      "<p>" +
        (infos.total.nb_files
          ? translate_files.replace("%d", String(infos.total.nb_files))
          : "~") +
        "</p>",
    );

    // Display body of Tooltip
    if (infos.details) {
      // `$.each(object, fn)` walks a plain object's own keys.
      Object.entries(infos.details).forEach(
        ([ext, data]: [string, { filesize: number; nb_files: number }]) => {
          // Determinate if we use MB or GB and show it correctly (duplicate code from total size for scaling code)
          const detail_size = data.filesize;
          let detail_str_size_type_string;
          let detail_size_nb: number | string;
          if (detail_size > 1048576) {
            detail_str_size_type_string = str_gb;
            detail_size_nb = (detail_size / 1048576).toFixed(2);
          } else {
            detail_str_size_type_string = str_mb;
            detail_size_nb =
              Number((detail_size / 1024).toFixed(0)) < 1
                ? (detail_size / 1024).toFixed(2)
                : (detail_size / 1024).toFixed(0);
          }
          const detail_str_size = detail_str_size_type_string.replace(
            "%s",
            detail_size_nb,
          );
          const markup =
            '<span class="tooltip-details-cont">' +
            '<span class="tooltip-details-ext"><b>' +
            ext +
            "</b></span>" +
            '<span class="tooltip-details-size"><b>' +
            detail_str_size +
            "</b></span>" +
            '<span class="tooltip-details-files">' +
            translate_files.replace("%d", String(data.nb_files)) +
            "</span>" +
            "</span>";
          document
            .querySelectorAll("#storage-detail-" + type)
            .forEach((container) => {
              for (const node of parseHtml(markup)) {
                container.appendChild(node);
              }
            });

          // `.css(name)` is a *getter* here: the computed background colour
          // of the chart segment, read off the first match of the set.
          const swatch = document.querySelector(
            '.storage-chart span[data-type="storage-' + type + '"]',
          );
          const ext_bg_color =
            swatch === null ? "" : cssValue(swatch, "background-color");
          css(
            document.querySelectorAll(
              "#storage-" + type + " .tooltip-details-ext b",
            ),
            "color",
            ext_bg_color,
          );
        },
      );
    } else {
      document
        .querySelectorAll("#storage-" + type + " .separated")
        .forEach((element) => {
          element.setAttribute("style", "display: none !important");
        });
      css(
        document.querySelectorAll("#storage-" + type + " .tooltip-header"),
        "margin",
        "0",
      );
    }

    // Fixing storage chart tooltip bug in little screen
    // Keep showing tooltip and his % when hovered
    document.querySelectorAll("#storage-" + type).forEach((tooltip) => {
      tooltip.addEventListener("mouseenter", function () {
        css(tooltip, "display", "block");
        css(
          document.querySelectorAll(
            '.storage-chart span[data-type="storage-' + type + '"] p',
          ),
          "opacity",
          "0.4",
        );
      });
      tooltip.addEventListener("mouseleave", function () {
        css(tooltip, "display", "none");
        css(
          document.querySelectorAll(
            '.storage-chart span[data-type="storage-' + type + '"] p',
          ),
          "opacity",
          "0",
        );
      });
    });

    document
      .querySelectorAll('.storage-chart span[data-type="storage-' + type + '"]')
      .forEach((segment) => {
        segment.addEventListener("mouseover", function () {
          css(segment.querySelectorAll("p"), "opacity", "0.4");
        });
        segment.addEventListener("mouseout", function () {
          css(segment.querySelectorAll("p"), "opacity", "0");
        });
      });
  });

  //Tooltip for the storage chart
  resizeStorageTooltips();
  //Tooltip for the activity chart
  resizeActivityTooltips();

  // Resize
  window.addEventListener("resize", function () {
    // resize storage tooltips
    resizeStorageTooltips(true);
    // resize activity tooltips
    resizeActivityTooltips();
  });
});

/*----------------
General function
----------------*/
function resizeStorageTooltips(resize: boolean = false) {
  document.querySelectorAll(".storage-chart span").forEach((segment) => {
    const type = String(readData(segment, "type"));
    const tooltips = document.querySelectorAll<HTMLElement>(
      ".storage-tooltips #" + type,
    );
    const arrows = document.querySelectorAll<HTMLElement>(
      ".storage-tooltips #" + type + " .tooltip-arrow",
    );

    // jQuery's dimension getters read the *first* element of a set and give
    // `undefined` for an empty one, which this arithmetic then turns into
    // NaN. The NaN survives into a "NaNpx" string the browser ignores, so
    // an absent tooltip is a silent no-op rather than an error -- kept
    // rather than guarded, because guarding would change which branches run
    // below.
    const firstTooltip = tooltips[0];
    const tooltipWidth =
      firstTooltip === undefined ? Number.NaN : innerWidth(firstTooltip);
    const chartTitle = document.querySelector<HTMLElement>(
      "#chart-title-storage",
    );

    let left =
      position(segment as HTMLElement).left +
      width(segment as HTMLElement) / 2 -
      tooltipWidth / 2;
    // Move tooltip if he create horizontal scrollbar
    const storage_width =
      chartTitle === null ? Number.NaN : innerWidth(chartTitle);
    if (left + tooltipWidth > storage_width) {
      const diff = left + tooltipWidth - storage_width;
      left = left - diff;
      css(arrows, "left", "calc(50% + " + diff + "px)");
    }
    css(tooltips, "left", left + "px");
    // Move tooltip if he create vertical scrollbar
    const chart = document.querySelector<HTMLElement>(".storage-chart")!;
    const str_chart_pos = offset(chart).top;
    const str_chart_height = innerHeight(chart);
    const tooltip_height =
      (firstTooltip === undefined ? Number.NaN : innerHeight(firstTooltip)) +
      str_chart_height;
    const windows_height = windowHeight();

    if (resize) {
      if (str_chart_pos + tooltip_height > windows_height) {
        css(tooltips, "bottom", "calc(100% + " + str_chart_height + "px)");
        arrows.forEach((arrow) => {
          arrow.classList.add("bottom");
        });
      } else {
        css(tooltips, "bottom", "");
        arrows.forEach((arrow) => {
          arrow.classList.remove("bottom");
        });
      }
    } else {
      if (str_chart_pos + tooltip_height > windows_height) {
        css(tooltips, "bottom", "calc(100% + " + str_chart_height + "px)");
        arrows.forEach((arrow) => {
          arrow.classList.add("bottom");
        });
      }
      // off-then-on, so a second call replaces the previous registration
      // instead of stacking another one. Both sides go through the helper
      // because `removeEventListener` has no way to say "every handler of
      // this type".
      off(segment, "mouseenter");
      on(segment, "mouseenter", function () {
        show(tooltips);
      });
      off(segment, "mouseleave");
      on(segment, "mouseleave", function () {
        hide(tooltips);
      });
    }
  });
}

function resizeActivityTooltips() {
  const main = document.querySelector<HTMLElement>("#pwgMain");

  document
    .querySelectorAll<HTMLElement>(".activity_tooltips")
    .forEach((container) => {
      // `.has(selector)` keeps only the elements that contain a match.
      const tooltip = container.querySelector<HTMLElement>(".tooltip");
      if (tooltip === null) {
        return;
      }

      const max_width = (main === null ? Number.NaN : innerWidth(main)) - 20;
      const left =
        position(container).left +
        innerWidth(container) / 2 +
        innerWidth(tooltip) / 2;
      if (left > max_width) {
        const arrows =
          container.querySelectorAll<HTMLElement>(".tooltip-arrow");
        const diff = max_width - left;

        css(tooltip, "left", "calc(50% + " + diff + "px)");
        css(arrows, "left", "calc(50% - " + diff + "px)");
      }
    });
}
