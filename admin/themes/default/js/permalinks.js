import { initModule } from './moduleInit.js';

export function init(cfg) {
  const { nbCats } = cfg;

  const h1 = document.querySelector("h1");
  if (h1) h1.insertAdjacentHTML('beforeend', "<span class='badge-number'>" + nbCats + "</span>");

  const addPermalink = document.getElementById("addPermalink");
  const showAddPermalink = document.getElementById("showAddPermalink");

  const openBtn = document.getElementById("addPermalinkOpen");
  if (openBtn) {
    openBtn.addEventListener('click', function(e) {
      e.preventDefault();
      if (addPermalink) addPermalink.style.display = '';
      if (showAddPermalink) showAddPermalink.style.display = 'none';
    });
  }

  const closeBtn = document.getElementById("addPermalinkClose");
  if (closeBtn) {
    closeBtn.addEventListener('click', function(e) {
      e.preventDefault();
      if (addPermalink) addPermalink.style.display = 'none';
      if (showAddPermalink) showAddPermalink.style.display = '';
    });
  }
}

initModule(init);
