import "../common";

import { pwg_getPageData } from "../../../../default/js/page-data";
import { hide, show } from "../../../../default/js/vendor/dom";

const filtersNames = pwg_getPageData<string[]>("filters_names");

/**
 * jQuery quietly skips a selector that matches nothing, and several of the
 * ids below are optional per filter. Collecting the ones that exist keeps
 * that behaviour without a null check at every call.
 */
function present(...elements: (HTMLElement | null)[]): HTMLElement[] {
  return elements.filter((element): element is HTMLElement => element !== null);
}

for (const filterName of filtersNames) {
  const toggle = document.querySelector<HTMLInputElement>(
    "#" + filterName + "Filters",
  );
  const select = document.querySelector<HTMLSelectElement>(
    "#f" + filterName + "Select",
  );
  const arrow = document.getElementById(filterName + "Arrow");
  const adminIcon = document.getElementById(filterName + "AdminIcon");
  const defaultInput = document.querySelector<HTMLInputElement>(
    "#default_" + filterName,
  );
  const defaultContainer = defaultInput?.parentElement ?? null;

  if (toggle?.checked !== true) {
    hide(present(select, arrow));
    hide(present(defaultContainer));
  }

  if (select?.value !== "admins-only") {
    hide(present(adminIcon));
  }

  if (defaultInput?.checked === true) {
    defaultContainer?.classList.add("selected-filter-container");
  }

  toggle?.addEventListener("click", function () {
    if (toggle.checked) {
      show(present(select, arrow));
      show(present(defaultContainer));
      if (select?.value === "admins-only") {
        show(present(adminIcon));
      }
    } else {
      hide(present(select, arrow, adminIcon));
      hide(present(defaultContainer));
    }
  });

  select?.addEventListener("click", function () {
    if (select.value === "admins-only") {
      show(present(adminIcon));
    } else {
      hide(present(adminIcon));
    }
  });

  defaultInput?.addEventListener("click", function () {
    if (defaultInput.checked) {
      defaultContainer?.classList.add("selected-filter-container");
    } else {
      defaultContainer?.classList.remove("selected-filter-container");
    }
  });
}
