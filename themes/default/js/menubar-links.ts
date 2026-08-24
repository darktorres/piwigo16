export {};

function pwg_initMenubarLinks() {
  const links = document.querySelectorAll<HTMLAnchorElement>(
    "a[data-window-name]",
  );
  links.forEach(function (link) {
    link.addEventListener("click", function (e) {
      window.open(
        link.href,
        link.dataset.windowName,
        link.dataset.windowFeatures,
      );
      e.preventDefault();
    });
  });
}

pwg_initMenubarLinks();
