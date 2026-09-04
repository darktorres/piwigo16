import { hide, show } from "./vendor/utils/dom";

if (Boolean(window.opener) || window.name !== "") {
  const closeLink = document.getElementById("closeLink");
  const homeLink = document.getElementById("homeLink");
  if (closeLink !== null) {
    show(closeLink);
  }
  if (homeLink !== null) {
    hide(homeLink);
  }
}
