export {};

const confirm_msg = pwg_getPageString('Yes, I am sure');
const cancel_msg = pwg_getPageString('No, I have changed my mind');
$(".delete-theme-button").each(function() {
  const theme_name = $(this).closest(".themeBox").find(".themeName").attr("title");
  const title = pwg_getPageString('Are you sure you want to delete the theme "%s"?');
  $(this).pwg_jconfirm_follow_href({
    alert_title: title.replace("%s", theme_name!),
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg
  });
});

jQuery(document).ready(function() {
  $("a.preview-box").colorbox();

  $(document).mouseup(function (e) {
    e.stopPropagation();
    if (!$(event!.target as unknown as Element).hasClass('showInfo')) {
      $('.showInfo-dropdown').fadeOut();
    }
  });

});

$(window).bind("load", function() {
  $('.themeBox').each(function() {

    const box = $(this);
    box.find('.showInfo').on('click', function() {
      const dropdown = box.find('.showInfo-dropdown');
      $('.showInfo-dropdown').each(function() {
        if ($(this) !== dropdown) {
          $(this).fadeOut();
        }
      })
      box.find('.showInfo-dropdown').fadeToggle();
    });

    const screenImage = $(this).find(".preview-box img");
    const imageW = screenImage.innerWidth()!;
    const imageH = screenImage.innerHeight()!;
    const size = $(this).find(".preview-box").innerWidth();

    if (imageW > imageH) {
      screenImage.css('height', size+'px');
      screenImage.css('width', (imageW * size! / imageH)+'px');
    } else {
      screenImage.css('width', size+'px');
      // "heigth" (sic) -- same genuine pre-existing typo already
      // preserved in themes_new.ts's own copy of this scaling logic;
      // jQuery.css() silently no-ops on an unrecognized property.
      screenImage.css('heigth', (imageH * size! / imageW)+'px');
    }
  })
})
