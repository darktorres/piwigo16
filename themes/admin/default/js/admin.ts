export {};

jQuery.fn.lightAccordion = function (
  this: JQuery,
  options?: LightAccordionOptions,
) {
  const settings = $.extend(
    {
      header: "dt",
      content: "dd",
      active: 0,
    },
    options,
  );

  return this.each(function () {
    const self = jQuery(this);

    const contents = self.find(settings.content),
      headers = self.find(settings.header);

    contents.not(contents[settings.active ?? 0]!).hide();

    self.on("click", settings.header, function () {
      const content = jQuery(this).next(settings.content);
      content.slideDown();
      contents.not(content).slideUp();
    });
  });
};

$("#menubar").lightAccordion({
  active: pwg_getPageData<number>("active_menu"),
});

/* in case we have several infos/errors/warnings display bullets */
jQuery(document).ready(function () {
  const eiw = ["infos", "erros", "warnings", "messages"];

  for (let i = 0; i < eiw.length; i++) {
    const boxType = eiw[i];

    if (jQuery("." + boxType + " ul li").length > 1) {
      jQuery("." + boxType + " ul li").css("list-style-type", "square");
      jQuery("." + boxType + " .eiw-icon").css("margin-right", "20px");
    }
  }

  if (jQuery("h2").length > 0) {
    jQuery("h1").html(jQuery("h2").html());
  }
});
