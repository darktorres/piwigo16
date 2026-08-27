import { hide, show } from "./vendor/dom";

export {};

if (window.opener || window.name) {
  const closeLink = document.getElementById("closeLink");
  const homeLink = document.getElementById("homeLink");
  if (closeLink !== null) {
    show(closeLink);
  }
  if (homeLink !== null) {
    hide(homeLink);
  }
}
