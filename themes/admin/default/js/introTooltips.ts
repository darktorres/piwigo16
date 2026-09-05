import {
  strGb,
  strMb,
  storageDetails,
  translateFiles,
  translateType,
} from "./intro";
import {
  css,
  cssValue,
  data as readData,
  hide,
  html,
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
} from "../../../default/js/vendor/utils/dom";

ready(function () {
  Object.entries(storageDetails).forEach(([type, rawInfos]) => {
    // `Object.entries()` infers `StorageDetails`'s index-signature value
    // type directly, so `rawInfos` needs no cast. It used to: intro.ts
    // imported `pwg_getPageData` through a `?dup` specifier, which
    // resolved to `any`, so `storageDetails` was `any` and the generic
    // overload had nothing to infer from -- a cast two files away from
    // the actual cause.
    const infos = rawInfos;
    // Determine if we use MB or GB and show it correctly
    const size = infos.total.filesize;
    const strSizeTypeString = size > 1048576 ? strGb : strMb;
    const sizeNb =
      size > 1048576 ? (size / 1048576).toFixed(2) : (size / 1024).toFixed(0);
    const strSize = strSizeTypeString.replace("%s", sizeNb);

    // Display head of Tooltip
    html(
      document.querySelectorAll("#storage-title-" + type),
      "<b>" + (translateType[type] ?? "") + "</b>",
    );
    html(
      document.querySelectorAll("#storage-size-" + type),
      "<b>" + strSize + "</b>",
    );
    html(
      document.querySelectorAll("#storage-files-" + type),
      "<p>" +
        (infos.total.nb_files
          ? translateFiles.replace("%d", String(infos.total.nb_files))
          : "~") +
        "</p>",
    );

    // Display body of Tooltip
    if (infos.details) {
      // `$.each(object, fn)` walks a plain object's own keys.
      Object.entries(infos.details).forEach(
        ([ext, data]: [string, { filesize: number; nb_files: number }]) => {
          // Determinate if we use MB or GB and show it correctly (duplicate code from total size for scaling code)
          const detailSize = data.filesize;
          let detailStrSizeTypeString;
          let detailSizeNb: number | string;
          if (detailSize > 1048576) {
            detailStrSizeTypeString = strGb;
            detailSizeNb = (detailSize / 1048576).toFixed(2);
          } else {
            detailStrSizeTypeString = strMb;
            detailSizeNb =
              Number((detailSize / 1024).toFixed(0)) < 1
                ? (detailSize / 1024).toFixed(2)
                : (detailSize / 1024).toFixed(0);
          }
          const detailStrSize = detailStrSizeTypeString.replace(
            "%s",
            detailSizeNb,
          );
          const markup =
            '<span class="tooltip-details-cont">' +
            '<span class="tooltip-details-ext"><b>' +
            ext +
            "</b></span>" +
            '<span class="tooltip-details-size"><b>' +
            detailStrSize +
            "</b></span>" +
            '<span class="tooltip-details-files">' +
            translateFiles.replace("%d", String(data.nb_files)) +
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
          const extBgColor =
            swatch === null ? "" : cssValue(swatch, "background-color");
          css(
            document.querySelectorAll(
              "#storage-" + type + " .tooltip-details-ext b",
            ),
            "color",
            extBgColor,
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
function resizeStorageTooltips(resize = false) {
  document
    .querySelectorAll<HTMLElement>(".storage-chart span")
    .forEach((segment) => {
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
      const [firstTooltip] = tooltips;
      const tooltipWidth =
        firstTooltip === undefined ? Number.NaN : innerWidth(firstTooltip);
      const chartTitle = document.querySelector<HTMLElement>(
        "#chart-title-storage",
      );

      let left = position(segment).left + width(segment) / 2 - tooltipWidth / 2;
      // Move tooltip if he create horizontal scrollbar
      const storageWidth =
        chartTitle === null ? Number.NaN : innerWidth(chartTitle);
      if (left + tooltipWidth > storageWidth) {
        const diff = left + tooltipWidth - storageWidth;
        left = left - diff;
        css(arrows, "left", "calc(50% + " + String(diff) + "px)");
      }
      css(tooltips, "left", String(left) + "px");
      // Move tooltip if he create vertical scrollbar
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- the page's own ".storage-chart" element is always real.
      const chart = document.querySelector<HTMLElement>(".storage-chart")!;
      const strChartPos = offset(chart).top;
      const strChartHeight = innerHeight(chart);
      const tooltipHeight =
        (firstTooltip === undefined ? Number.NaN : innerHeight(firstTooltip)) +
        strChartHeight;
      const windowsHeight = windowHeight();

      if (resize) {
        if (strChartPos + tooltipHeight > windowsHeight) {
          css(
            tooltips,
            "bottom",
            "calc(100% + " + String(strChartHeight) + "px)",
          );
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
        if (strChartPos + tooltipHeight > windowsHeight) {
          css(
            tooltips,
            "bottom",
            "calc(100% + " + String(strChartHeight) + "px)",
          );
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

      const maxWidth = (main === null ? Number.NaN : innerWidth(main)) - 20;
      const left =
        position(container).left +
        innerWidth(container) / 2 +
        innerWidth(tooltip) / 2;
      if (left > maxWidth) {
        const arrows =
          container.querySelectorAll<HTMLElement>(".tooltip-arrow");
        const diff = maxWidth - left;

        css(tooltip, "left", "calc(50% + " + String(diff) + "px)");
        css(arrows, "left", "calc(50% - " + String(diff) + "px)");
      }
    });
}
