document.querySelectorAll('.themeShotImg').forEach(function(img) {
  img.addEventListener('error', function() {
    img.src = pwg_getPageData('default_screenshot');
  });
});

$(window).bind("load", function() {
  $('.themeBox').each(function() {

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
