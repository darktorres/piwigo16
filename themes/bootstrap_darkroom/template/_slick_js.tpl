{combine_css path="themes/bootstrap_darkroom/node_modules/swiper/swiper-bundle.min.css" order=-22}
{combine_script id="swiper.carousel" path="themes/bootstrap_darkroom/node_modules/swiper/swiper-bundle.min.js" load="footer"}
{footer_script require="swiper.carousel"}<script>
  document.addEventListener('DOMContentLoaded', function() {
    var thumbnailCarousel = document.getElementById('thumbnailCarousel');
    if (!thumbnailCarousel || typeof Swiper === 'undefined') return;

    var swiper = new Swiper(thumbnailCarousel, {
      loop: {if $theme_config->slick_infinite}true{else}false{/if},
      {if $theme_config->slick_centered}
      centeredSlides: true,
      slidesPerView: {if count($thumbnails) <= 7}{if count($thumbnails) > 2 && (count($thumbnails) % 2 == 0)}{count($thumbnails) -1}{else}{count($thumbnails)}{/if}{else}7{/if},
      slidesPerGroup: 1,
      breakpoints: {
        1200: {
          slidesPerView: {if count($thumbnails) <= 5}{if count($thumbnails) > 2 && (count($thumbnails) % 2 == 0)}{count($thumbnails) -1}{else}{count($thumbnails)}{/if}{else}5{/if}
        }
      },
      {else}
      centeredSlides: false,
      slidesPerView: {if count($thumbnails) <= 7}{count($thumbnails)}{else}7{/if},
      slidesPerGroup: {if count($thumbnails) <= 7}{if count($thumbnails) > 1}{count($thumbnails) - 1}{else}1{/if}{else}6{/if},
      breakpoints: {
        1200: {
          slidesPerView: {if count($thumbnails) <= 5}{count($thumbnails)}{else}5{/if},
          slidesPerGroup: {if count($thumbnails) <= 5}{if count($thumbnails) > 1}{count($thumbnails) - 1}{else}1{/if}{else}4{/if}
        },
        1024: {
          slidesPerView: {if count($thumbnails) <= 4}{count($thumbnails)}{else}4{/if},
          slidesPerGroup: {if count($thumbnails) <= 4}{if count($thumbnails) > 1}{count($thumbnails) - 1}{else}1{/if}{else}3{/if}
        },
        768: { slidesPerView: 3, slidesPerGroup: 3 },
        420: { centeredSlides: false, slidesPerView: 2, slidesPerGroup: 2 }
      },
      {/if}
    });

    var currentThumb = thumbnailCarousel.querySelector('.thumbnail-active');
    if (currentThumb) {
      var slides = Array.from(thumbnailCarousel.querySelectorAll('.swiper-slide'));
      var idx = slides.indexOf(currentThumb);
      if (idx >= 0) swiper.slideTo(idx, 0);
    }
  });
</script>{/footer_script}
