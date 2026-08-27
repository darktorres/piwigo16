import { pwg_getPageData } from "../../../default/js/page-data";
import { ready } from "../../../default/js/vendor/dom";
export {};

ready(() => {
  document
    .getElementById("checkAllLink")
    ?.addEventListener("click", (event) => {
      setAllC13yCheckboxes(true);
      event.preventDefault();
      event.stopPropagation();
    });

  document
    .getElementById("uncheckAllLink")
    ?.addEventListener("click", (event) => {
      setAllC13yCheckboxes(false);
      event.preventDefault();
      event.stopPropagation();
    });

  document
    .getElementById("checkAutomaticCorrectionsLink")
    ?.addEventListener("click", (event) => {
      DeselectAll(document.getElementById("c13y") as HTMLFormElement);
      const ids = pwg_getPageData<string[] | null>("c13y_do_check_ids") ?? [];
      ids.forEach(function (id: string) {
        (
          document.getElementById("c13y_selection-" + id) as HTMLInputElement
        ).checked = true;
      });
      event.preventDefault();
      event.stopPropagation();
    });
});

/**
 * `return false` from a jQuery handler is preventDefault() *and*
 * stopPropagation(); both are reproduced at each call site above.
 */
function setAllC13yCheckboxes(checked: boolean): void {
  document
    .querySelectorAll<HTMLInputElement>("#c13y input[type=checkbox]")
    .forEach((box) => (box.checked = checked));
}

function DeselectAll(formulaire: HTMLFormElement) {
  const elts = formulaire.elements;
  for (let i = 0; i < elts.length; i++) {
    if ((elts[i] as HTMLInputElement).type === "checkbox") {
      (elts[i] as HTMLInputElement).checked = false;
    }
  }
}
