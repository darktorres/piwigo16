import { ready } from "../../../default/js/vendor/dom";

/**
 * `return false` from a jQuery handler is both preventDefault() and
 * stopPropagation(), not just the former -- these links must not navigate
 * and must not bubble.
 */
function setAllCheckboxes(selector: string, checked: boolean): void {
  document
    .querySelectorAll<HTMLInputElement>(selector)
    .forEach((box) => (box.checked = checked));
}

ready(() => {
  document
    .getElementById("checkAllLink")
    ?.addEventListener("click", (event) => {
      setAllCheckboxes("#notification_by_mail input[type=checkbox]", true);
      event.preventDefault();
      event.stopPropagation();
    });

  document
    .getElementById("uncheckAllLink")
    ?.addEventListener("click", (event) => {
      setAllCheckboxes("#notification_by_mail input[type=checkbox]", false);
      event.preventDefault();
      event.stopPropagation();
    });
});
