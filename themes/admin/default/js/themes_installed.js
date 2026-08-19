var confirm_msg = pwg_getPageString('Yes, I am sure');
var cancel_msg = pwg_getPageString('No, I have changed my mind');
$(".delete-theme-button").each(function() {
  var theme_name = $(this).closest(".themeBox").find(".themeName").attr("title");
  var title = pwg_getPageString('Are you sure you want to delete the theme "%s"?');
  $(this).pwg_jconfirm_follow_href({
    alert_title: title.replace("%s", theme_name),
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg
  });
});

jQuery(document).ready(function() {
  $("a.preview-box").colorbox();

  $(document).mouseup(function (e) {
    e.stopPropagation();
    if (!$(event.target).hasClass('showInfo')) {
      $('.showInfo-dropdown').fadeOut();
    }
  });

});

$(window).bind("load", function() {
  $('.themeBox').each(function() {

    var box = $(this);
    box.find('.showInfo').on('click', function() {
      var dropdown = box.find('.showInfo-dropdown');
      $('.showInfo-dropdown').each(function() {
        if ($(this) !== dropdown) {
          $(this).fadeOut();
        }
      })
      box.find('.showInfo-dropdown').fadeToggle();
    });

    var screenImage = $(this).find(".preview-box img");
    var imageW = screenImage.innerWidth();
    var imageH = screenImage.innerHeight();
    var size = $(this).find(".preview-box").innerWidth();

    if (imageW > imageH) {
      screenImage.css('height', size+'px');
      screenImage.css('width', (imageW * size / imageH)+'px');
    } else {
      screenImage.css('width', size+'px');
      screenImage.css('heigth', (imageH * size / imageW)+'px');
    }
  })
})
