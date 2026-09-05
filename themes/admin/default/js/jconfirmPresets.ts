import { attrOf, on } from "../../../default/js/vendor/utils/dom";
import { confirm } from "../../../default/js/vendor/widgets/jconfirm";

// `draggable`/`theme`/`animation`/`useBootstrap`/`animateFromElement`/
// `typeAnimated`/`backgroundDismiss` all dropped from these 4 presets:
// every real call site across the whole app set them to the exact same
// values (confirmed via a full grep, not assumed), so `themes/default/js/
// vendor/widgets/jconfirm.ts`'s own port of `$.confirm`/`$.alert` (P49-B group 5)
// hardcodes them instead of taking them as options at all.
export const jConfirmAlertOptions = {
  icon: "icon-ok",
  titleClass: "jconfirmAlert",
  closeIcon: true,
  boxWidth: "20%",
};

export const jConfirmConfirmOptions = {
  titleClass: "jconfirmDeleteConfirm",
  boxWidth: "40%",
  type: "red",
};

export const jConfirmWarningOptions = {
  icon: "icon-attention",
  titleClass: "jconfirmWarning jconfirmAlert",
  type: "orange",
  closeIcon: true,
  boxWidth: "20%",
};

export const jConfirmConfirmWithContentOptions = {
  boxWidth: "40%",
  type: "red",
};

export function pwg_jconfirm_follow_href(
  el: Element,
  {
    alert_title = "TITLE",
    alert_confirm = "CONFIRM",
    alert_cancel = "CANCEL",
    alert_content = "",
  }: {
    alert_title?: string;
    alert_confirm?: string;
    alert_cancel?: string;
    alert_content?: string;
  } = {},
): void {
  const buttonHref = attrOf(el, "href");
  const options =
    alert_content === ""
      ? jConfirmConfirmOptions
      : jConfirmConfirmWithContentOptions;
  on(el, "click", (e) => {
    e.preventDefault();
    confirm({
      content: alert_content,
      title: alert_title,
      buttons: {
        confirm: {
          text: alert_confirm,
          btnClass: "btn-red",
          action: function () {
            // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- pwg_jconfirm_follow_href()'s own name/contract: every real caller passes a real <a href> element.
            window.location.href = buttonHref!;
          },
        },
        cancel: {
          text: alert_cancel,
        },
      },
      ...options,
    });
  });
}
