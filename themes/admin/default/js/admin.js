jQuery.fn.lightAccordion = function(options) {
  var settings = $.extend({
    header: 'dt',
    content: 'dd',
    active: 0
  }, options);

  return this.each(function() {
    var self = jQuery(this);

    var contents = self.find(settings.content),
        headers = self.find(settings.header);

    contents.not(contents[settings.active]).hide();

    self.on('click', settings.header, function() {
        var content = jQuery(this).next(settings.content);
        content.slideDown();
        contents.not(content).slideUp();
    });
  });
};

$('#menubar').lightAccordion({
  active: pwg_getPageData('active_menu')
});

/* in case we have several infos/errors/warnings display bullets */
jQuery(document).ready(function() {
  var eiw = ["infos","erros","warnings", "messages"];

  for (var i = 0; i < eiw.length; i++) {
    var boxType = eiw[i];

    if (jQuery("."+boxType+" ul li").length > 1) {
      jQuery("."+boxType+" ul li").css("list-style-type", "square");
      jQuery("."+boxType+" .eiw-icon").css("margin-right", "20px");
    }
  }

  if (jQuery('h2').length > 0) {
    jQuery('h1').html(jQuery('h2').html());
  }
});
