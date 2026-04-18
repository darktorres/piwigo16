import Sortable from 'sortablejs';

document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll(".menuPos").forEach(function(el) { el.style.display = 'none'; });
  document.querySelectorAll(".drag_button").forEach(function(el) { el.style.display = ''; });
  document.querySelectorAll(".menuLi").forEach(function(el) { el.style.cursor = "move"; });

  var menuUl = document.querySelector(".menuUl");
  var menuSortable = menuUl ? Sortable.create(menuUl, { animation: 150 }) : null;

  document.querySelectorAll("input[name^='hide_']").forEach(function(el) {
    el.addEventListener('click', function() {
      var men = this.name.split('hide_');
      var menuEl = document.getElementById("menu_" + men[1]);
      if (menuEl) menuEl.classList.toggle('menuLi_hidden', this.checked);
    });
  });

  var menuOrdering = document.getElementById("menuOrdering");
  if (menuOrdering) {
    menuOrdering.addEventListener('submit', function() {
      if (menuSortable) {
        var ar = menuSortable.toArray();
        for (var i = 0; i < ar.length; i++) {
          var men = ar[i].split('menu_');
          var posInput = document.getElementsByName('pos_' + men[1])[0];
          if (posInput) posInput.value = i + 1;
        }
      }
    });
  }
});
