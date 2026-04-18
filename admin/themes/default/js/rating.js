import { initModule } from './moduleInit.js';
import { CategoriesCache } from './LocalStorageCache.js';
import { PwgWS } from './pwgws.js';

export function init(cfg) {
  const { categoriesServerKey, categoriesServerId, rootUrl, nbElements } = cfg;

  const categoriesCache = new CategoriesCache({
    serverKey: categoriesServerKey || '',
    serverId: categoriesServerId || '',
    rootUrl: rootUrl || ''
  });

  categoriesCache.selectize(document.querySelectorAll('[data-selectize=categories]'));

  const removeAlbumFilter = document.getElementById("removeAlbumFilter");
  if (removeAlbumFilter) {
    removeAlbumFilter.addEventListener('click', function(event) {
      event.preventDefault();
      const catSelect = document.querySelector("select[name=cat]");
      if (catSelect && catSelect.tomselect) catSelect.tomselect.setValue(null);
    });
  }

  function checkCatFilter() {
    const catSelect = document.querySelector("select[name=cat]");
    const filterBtn = document.getElementById("removeAlbumFilter");
    if (!filterBtn) return;
    filterBtn.style.display = (catSelect && catSelect.value !== "") ? '' : 'none';
  }

  checkCatFilter();
  const catSelectEl = document.querySelector("select[name=cat]");
  if (catSelectEl) catSelectEl.addEventListener('change', function() { checkCatFilter(); });

  const h1 = document.querySelector('h1');
  if (h1) h1.insertAdjacentHTML('beforeend', "<span class='badge-number'>" + nbElements + "</span>");

  window.del = function(node, id, uid, aid) {
    const trEl = node.closest("tr");
    const data = { image_id: id, user_id: uid };
    if (aid) data.anonymous_id = aid;

    const anim = trEl ? trEl.animate([{opacity: 1}, {opacity: 0.4}], {duration: 1000, fill: 'forwards'}) : null;
    (new PwgWS(rootUrl)).callService(
      'pwg.rates.delete', data, {
        method: 'POST',
        onFailure: function(num, text) {
          if (anim) { anim.cancel(); }
          if (trEl) trEl.style.opacity = '1';
          alert(num + " " + text);
        },
        onSuccess: function(result) {
          if (result) {
            if (trEl) trEl.remove();
          } else {
            alert(result);
          }
        }
      }
    );
    return false;
  };
}

initModule(init);
