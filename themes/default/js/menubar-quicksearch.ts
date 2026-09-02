import { pwg_getPageString } from "./page-data";

function pwg_initQuickSearch() {
  const input = document.getElementById(
    "qsearchInput",
  ) as HTMLInputElement | null;
  const form = document.getElementById("quicksearch") as HTMLFormElement | null;
  if (!input || !form) {
    return;
  }

  const prompt = pwg_getPageString("Quick search");

  if (input.value === "") {
    input.value = prompt;
  }

  input.addEventListener("focus", function () {
    if (input.value === prompt) {
      input.value = "";
    }
  });

  input.addEventListener("blur", function () {
    if (input.value === "") {
      input.value = prompt;
    }
  });

  form.addEventListener("submit", function (e) {
    if (input.value === "" || input.value === prompt) {
      e.preventDefault();
    }
  });
}

pwg_initQuickSearch();
