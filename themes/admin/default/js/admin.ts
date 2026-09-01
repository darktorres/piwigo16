import { pwg_getPageData } from "../../../default/js/page-data";
import {
  css,
  delegate,
  hide,
  html,
  htmlOf,
  ready,
  slideDown,
  slideUp,
} from "../../../default/js/vendor/dom";
export {};

interface LightAccordionOptions {
  header?: string;
  content?: string;
  active?: number;
}

// Real declarer of the accordion this page's own #menubar sidebar uses --
// this file is its one real call site (build/ambient-globals.d.ts's own
// former jQuery.fn.lightAccordion, before this conversion), so it converts
// to a plain function scoped to that one container rather than keeping the
// jQuery-plugin "runs per matched element" shape a set of callers would need.
function lightAccordion(
  container: Element,
  options?: LightAccordionOptions,
): void {
  const settings = { header: "dt", content: "dd", active: 0, ...options };

  const contents = Array.from(container.querySelectorAll(settings.content));
  const activeContent = contents[settings.active];

  contents.forEach((content) => {
    if (content !== activeContent) {
      hide(content);
    }
  });

  // Delegated, as the original was: a click anywhere inside a header
  // (not just on the header element itself) still opens its content.
  delegate(container, "click", settings.header, function (this: Element): void {
    const next = this.nextElementSibling;
    if (next === null || !next.matches(settings.content)) {
      return;
    }

    slideDown(next);
    contents.forEach((content) => {
      if (content !== next) {
        slideUp(content);
      }
    });
  });
}

const menubar = document.getElementById("menubar");
if (menubar !== null) {
  lightAccordion(menubar, {
    active: pwg_getPageData<number>("active_menu"),
  });
}

/* in case we have several infos/errors/warnings display bullets */
ready(function () {
  const eiw = ["infos", "erros", "warnings", "messages"];

  for (let i = 0; i < eiw.length; i++) {
    const boxType = eiw[i];

    const items = document.querySelectorAll("." + boxType + " ul li");
    if (items.length > 1) {
      css(items, "list-style-type", "square");
      css(
        document.querySelectorAll("." + boxType + " .eiw-icon"),
        "margin-right",
        "20px",
      );
    }
  }

  if (document.querySelectorAll("h2").length > 0) {
    html(
      document.querySelectorAll("h1"),
      htmlOf(document.querySelectorAll("h2")) ?? "",
    );
  }
});
