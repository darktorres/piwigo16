import "./common";

import { hide, ready, show } from "../../../default/js/vendor/dom";

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

// Scroll mini to show the selected one. Two real, independent bugs found
// live, both root-caused by comparing screenshots pixel-by-pixel across
// repeated runs of the same page:
// - `.std_pgs_mini_previews` (the scroll container) has no `position` rule
//   of its own (`position: static`), so it is never the real `offsetParent`
//   of its `<img>` children -- `.position()` (jQuery-style, always relative
//   to the *real* offsetParent) returned that container's own distance from
//   an unrelated positioned ancestor further up the page, not the selected
//   thumbnail's distance from the top of the container's own scrollable
//   content. For the real default ("default", the first thumbnail, needing
//   zero scroll), this scrolled the container by however far down the page
//   it happened to sit -- landing on an arbitrary later thumbnail instead.
//   `scrollIntoView()` has no such assumption: it walks the real scrollable
//   ancestor chain itself, so it lands correctly regardless of what does or
//   doesn't establish a positioning context.
// - Even with that fixed, computing any position at `ready()` time races
//   the 11 real `<img>` mini-previews' own loads: one still-loading image
//   above `selected` leaves the layout mid-shift, changing the measured
//   result depending on real network/decode timing. Waiting for every
//   mini-preview to settle (load or error) first removes that dependency.
ready(function () {
  const container = document.querySelector<HTMLElement>(
    ".std_pgs_mini_previews",
  );
  const selected = container?.querySelector<HTMLElement>(".selected") ?? null;

  if (container === null || selected === null) {
    return;
  }

  const scrollToSelected = () => {
    selected.scrollIntoView({ block: "nearest" });
  };

  const pending = Array.from(
    container.querySelectorAll<HTMLImageElement>("img"),
  ).filter((image) => !image.complete);

  if (pending.length === 0) {
    scrollToSelected();
    return;
  }

  let remaining = pending.length;
  const onSettled = () => {
    remaining -= 1;
    if (remaining === 0) {
      scrollToSelected();
    }
  };
  pending.forEach((image) => {
    image.addEventListener("load", onSettled, { once: true });
    image.addEventListener("error", onSettled, { once: true });
  });
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
