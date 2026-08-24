export {};

document
  .querySelectorAll<HTMLImageElement>(".themeShotImg")
  .forEach(function (img) {
    img.addEventListener("error", function () {
      img.src = pwg_getPageData("default_screenshot");
    });
  });

$(window).bind("load", function () {
  $(".themeBox").each(function () {
    const screenImage = $(this).find(".preview-box img");
    const imageW = screenImage.innerWidth()!;
    const imageH = screenImage.innerHeight()!;
    const size = $(this).find(".preview-box").innerWidth();

    if (imageW > imageH) {
      screenImage.css("height", size + "px");
      screenImage.css("width", (imageW * size!) / imageH + "px");
    } else {
      screenImage.css("width", size + "px");
      // "heigth" (sic) -- a genuine pre-existing typo in the original
      // .js. Preserved exactly: jQuery.css() silently no-ops on an
      // unrecognized CSS property, so this has always been a harmless
      // dead statement, not a real bug worth fixing here.
      screenImage.css("heigth", (imageH * size!) / imageW + "px");
    }
  });
});
