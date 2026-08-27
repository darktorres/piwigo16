import "./common";

import { hide, position, ready, show } from "../../../default/js/vendor/dom";

export {};

// Update preview when user clicks on mini previews
const miniPreviewImages = document.querySelectorAll<HTMLImageElement>(
  ".std_pgs_mini_previews img",
);

miniPreviewImages.forEach((image) => {
  image.addEventListener("click", function () {
    //Make selected skin outlined
    miniPreviewImages.forEach((other) => {
      other.classList.remove("selected");
    });
    image.classList.add("selected");

    //Update preview when useer clicks on mini
    document
      .querySelectorAll<HTMLInputElement>("input[name=std_pgs_selected_skin]")
      .forEach((input) => {
        input.value = image.id;
      });

    const preview_light_path =
      "themes/standard_pages/skins/light-" + image.id + ".jpg";
    const preview_dark_path =
      "themes/standard_pages/skins/dark-" + image.id + ".jpg";

    document
      .querySelectorAll(".std_pgs_selected_preview img#preview-light")
      .forEach((preview) => {
        preview.setAttribute("src", preview_light_path);
      });
    document
      .querySelectorAll(".std_pgs_selected_preview img#preview-dark")
      .forEach((preview) => {
        preview.setAttribute("src", preview_dark_path);
      });
  });
});

document
  .querySelectorAll<HTMLInputElement>("input[name=std_pgs_display_logo]")
  .forEach((radio) => {
    radio.addEventListener("click", function () {
      const previews = document.querySelectorAll(".custom_logo_preview");

      previews.forEach((preview) => {
        if (radio.value === "custom_logo") {
          preview.classList.add("show");
          preview.classList.remove("hide");
        } else {
          preview.classList.add("hide");
          preview.classList.remove("show");
        }
      });
    });
  });

// Scroll mini to show the selected one
ready(function () {
  const container = document.querySelector<HTMLElement>(
    ".std_pgs_mini_previews",
  );
  const selected = container?.querySelector<HTMLElement>(".selected") ?? null;

  if (container !== null && selected !== null) {
    // `.position()` is relative to the offset parent with the element's own
    // margins removed, which is what makes this land on the thumbnail rather
    // than a margin's width away from it.
    container.scrollTop = position(selected).top + container.scrollTop;
  }
});

//Switch between change logo and use existing logo

document.getElementById("change_logo")?.addEventListener("click", function () {
  show(document.querySelectorAll(".use_existing_logo_container"));
  hide(document.querySelectorAll(".change_logo_container"));
});
document
  .getElementById("use_existing_logo")
  ?.addEventListener("click", function () {
    show(document.querySelectorAll(".change_logo_container"));
    hide(document.querySelectorAll(".use_existing_logo_container"));

    const logo = document.getElementById(
      "std_pgs_logo",
    ) as HTMLInputElement | null;
    if (logo !== null) {
      logo.value = "";
    }
  });
