import { fadeOut } from "../../default/js/vendor/dom";

export interface PwgToasterInfo {
  text: string;
  icon: "success" | "error";
  time?: number;
}

export function pwgToaster(info: PwgToasterInfo) {
  if (!info.text || !info.icon) {
    console.error("set info.text or info.icon");
    return;
  }

  if (typeof info.text !== "string") {
    console.error("info.text is not a string");
    return;
  }

  if (info.icon !== "success" && info.icon !== "error") {
    console.error("info.icon must be success or error");
    return;
  }

  const source = document.getElementById("toast_template");
  const host = document.getElementById("pwg_toaster");
  if (source === null || host === null) {
    return;
  }

  // cloneNode(true) keeps the id, exactly as jQuery's .clone() did. That
  // leaves duplicate #toast_template ids in the DOM, but harmlessly: the
  // template lives inside #pwg_toaster and clones are appended after it, so
  // getElementById still returns the original. Preserved rather than tidied
  // -- this is a translation, and no CSS targets the id (the template is
  // hidden through .toast.template-pwg-toaster).
  const template = source.cloneNode(true) as HTMLElement;

  const text = template.querySelector(".toast_text");
  if (text !== null) {
    text.innerHTML = info.text;
  }
  template
    .querySelector(".toast_icon")
    ?.classList.add(info.icon === "success" ? "icon-ok" : "icon-cancel");
  template.classList.add(info.icon === "success" ? info.icon : "error");

  template.classList.remove("template-pwg-toaster");
  host.appendChild(template);

  const time = info.time ?? 3600;
  setTimeout(() => {
    fadeOut(template, () => {
      template.remove();
    });
  }, time);
}
