import { initModule } from './moduleInit.js';

export function init(cfg) {
  const { rootUrl } = cfg;

  function onWatermarkChange() {
    const wSelect = document.getElementById("wSelect");
    const wImg = document.getElementById("wImg");
    if (!wSelect || !wImg) return;
    const val = wSelect.value;
    if (val.length) {
      wImg.setAttribute('src', rootUrl + val);
      wImg.style.display = '';
    } else {
      wImg.style.display = 'none';
    }
  }

  onWatermarkChange();

  const wSelect = document.getElementById("wSelect");
  if (wSelect) wSelect.addEventListener("change", onWatermarkChange);

  const positionChecked = document.querySelector("input[name='w[position]']:checked");
  const positionCustomDetails = document.getElementById("positionCustomDetails");
  if (positionChecked && positionCustomDetails) {
    positionCustomDetails.style.display = positionChecked.value === 'custom' ? '' : 'none';
  }

  document.querySelectorAll("input[name='w[position]']").forEach(function(el) {
    el.addEventListener('change', function() {
      const positionCustomDetails = document.getElementById("positionCustomDetails");
      if (positionCustomDetails) {
        positionCustomDetails.style.display = this.value === 'custom' ? '' : 'none';
      }
    });
  });

  document.querySelectorAll(".addWatermarkOpen").forEach(function(el) {
    el.addEventListener('click', function(event) {
      event.preventDefault();
      const addWatermark = document.getElementById("addWatermark");
      const selectWatermark = document.getElementById("selectWatermark");
      if (addWatermark) addWatermark.style.display = addWatermark.style.display === 'none' ? '' : 'none';
      if (selectWatermark) selectWatermark.style.display = selectWatermark.style.display === 'none' ? '' : 'none';
    });
  });
}

initModule(init);
