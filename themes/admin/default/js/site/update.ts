import { hide, show } from "../../../../default/js/vendor/utils/dom";

document.querySelectorAll("#syncFiles label").forEach((label) => {
  label.addEventListener("click", () => {
    const filesInput = document.querySelector<HTMLInputElement>(
      "input[value='files']",
    );
    if (filesInput === null) {
      return;
    }

    // jQuery's `.find("ul")` matched every descendant list, not just the
    // first, and show()/hide() applied to all of them.
    const lists = filesInput.closest("li")?.querySelectorAll("ul");
    if (lists === undefined) {
      return;
    }

    // `$("input[value='files']:checked").val()` -- truthy only when the box
    // is checked AND its value is a non-empty string.
    if (filesInput.checked && filesInput.value !== "") {
      show(lists);
    } else {
      hide(lists);
    }
  });
});
