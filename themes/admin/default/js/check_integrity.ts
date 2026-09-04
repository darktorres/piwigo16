import { pwg_getPageData } from "../../../default/js/page-data";
import { ready } from "../../../default/js/vendor/dom";

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
      deselectAll(document.querySelector<HTMLFormElement>("#c13y")!);
      const ids = pwg_getPageData<string[] | null>("c13y_do_check_ids") ?? [];
      ids.forEach(function (id: string) {
        document.querySelector<HTMLInputElement>(
          "#c13y_selection-" + id,
        )!.checked = true;
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

function deselectAll(formulaire: HTMLFormElement) {
  for (const elt of formulaire.elements) {
    if (elt instanceof HTMLInputElement && elt.type === "checkbox") {
      elt.checked = false;
    }
  }
}
