import TomSelect from 'tom-select';

const _docReady = function(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); };

_docReady(function() {
  document.addEventListener('DOMContentLoaded', function() {
    function checkWhoOptions() {
      var checkedEl = document.querySelector("input[name=who]:checked");
      var option = checkedEl ? checkedEl.value : '';
      document.querySelectorAll(".who_option").forEach(function(el) { el.style.display = 'none'; });
      var target = document.querySelector(".who_" + option);
      if (target) target.style.display = '';
    }

    document.querySelectorAll("input[name=who]").forEach(function(el) {
      el.addEventListener('change', function() { checkWhoOptions(); });
    });

    checkWhoOptions();

    document.querySelectorAll(".who_option select").forEach(function(el) {
      new TomSelect(el, { plugins: ['remove_button'] });
    });

    var categoryNotify = document.getElementById("categoryNotify");
    if (categoryNotify) {
      categoryNotify.addEventListener('submit', function(e) {
        var who_selected = false;
        var checkedEl = document.querySelector("input[name=who]:checked");
        var who_option = checkedEl ? checkedEl.value : '';
        var selectEl = document.querySelector(".who_" + who_option + " select");
        if (selectEl && selectEl.querySelector("option:checked")) {
          who_selected = true;
        }
        var errorsEl = document.querySelector(".actionButtons .errors");
        if (!who_selected) {
          if (errorsEl) errorsEl.style.display = '';
          e.preventDefault();
        } else {
          if (errorsEl) errorsEl.style.display = 'none';
          console.log("form can be submitted");
        }
      });
    }
  });
});
