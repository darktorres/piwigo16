export {};

(function () {
  const sbFunc = function (link: string, box: string) {
    jQuery(link).click(function () {
      const elt = jQuery(box);
      elt
        .css(
          "left",
          Math.min(
            jQuery(this).position().left,
            jQuery(window).width()! - elt.outerWidth(true)! - 5,
          ),
        )
        .css(
          "top",
          jQuery(this).position().top + jQuery(this).outerHeight(true)!,
        )
        .toggle();
      return false;
    });
    jQuery(box).on("mouseleave click", function () {
      jQuery(this).hide();
    });
  };

  if (window.SwitchBox) {
    for (let i = 0; i < window.SwitchBox.length; i += 2)
      sbFunc(window.SwitchBox[i], window.SwitchBox[i + 1]);
  }

  window.SwitchBox = {
    push: sbFunc,
  };
})();
