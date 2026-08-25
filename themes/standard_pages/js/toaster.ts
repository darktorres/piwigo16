interface PwgToasterInfo {
  text: string;
  icon: "success" | "error";
  time?: number;
}

function pwgToaster(info: PwgToasterInfo) {
  if (!info.text || !info.icon) {
    console.log("set info.text or info.icon");
    return;
  }

  if (typeof info.text !== "string") {
    console.log("info.text is not a string");
    return;
  }

  if (info.icon !== "success" && info.icon !== "error") {
    console.log("info.icon must be success or error");
    return;
  }

  const template = $("#toast_template").clone();

  template.find(".toast_text").html(info.text);
  template
    .find(".toast_icon")
    .addClass(info.icon === "success" ? "icon-ok" : "icon-cancel");
  template.addClass(info.icon === "success" ? info.icon : "error");

  template.removeClass("template-pwg-toaster");
  template.appendTo("#pwg_toaster");

  const time = info.time ?? 3600;
  setTimeout(() => {
    template.fadeOut(() => {
      template.remove();
    });
  }, time);
}

// Explicit `window.` exposure -- required for the same reason as
// page-data.ts's own copy of this comment: profile.ts calls this bare,
// and Vite/Rollup's per-entry IIFE-wrapping (vite.config.ts's own
// banner/footer) hides an un-exposed top-level declaration from any
// other loaded <script> tag, even though this file has no `export {}`
// of its own (its own declaration is still a real ambient global for
// `tsc`'s whole-program type-check either way).
window.pwgToaster = pwgToaster;
