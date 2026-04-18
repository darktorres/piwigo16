import { initModule } from './moduleInit.js';
import Sortable from 'sortablejs';
import tippy from 'tippy.js';

export function init(cfg) {
    function checkOrderOptions() {
      var opts = document.getElementById("image_order_user_define_options");
      if (!opts) return;
      var checked = document.querySelector("input[name=image_order_choice]:checked");
      opts.style.display = (checked && checked.value === "user_define") ? '' : 'none';
    }

    var thumbnailsUl = document.querySelector('ul.thumbnails');
    if (thumbnailsUl) {
      Sortable.create(thumbnailsUl, {
        animation: 150,
        handle: '.rank-of-image',
        onEnd: function() {
          thumbnailsUl.querySelectorAll('li').forEach(function(li, i) {
            li.querySelectorAll("input[name^=rank_of_image]").forEach(function(inp) {
              inp.setAttribute('value', (i + 1) * 10);
            });
          });
          var rankRadio = document.getElementById('image_order_rank');
          if (rankRadio) rankRadio.checked = true;
          checkOrderOptions();
        }
      });
    }

    document.querySelectorAll("input[name=image_order_choice]").forEach(function(el) {
      el.addEventListener('click', function() { checkOrderOptions(); });
    });

    checkOrderOptions();

    tippy('.thumbnail', { delay: 0, placement: 'top' });
}

initModule(init);
