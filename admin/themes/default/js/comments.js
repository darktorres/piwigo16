const _docReady = function(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); };

_docReady(function() {
  document.addEventListener('DOMContentLoaded', function() {
    var h1 = document.querySelector("h1");
    if (h1) h1.insertAdjacentHTML('beforeend', "<span class='badge-number'>"+window.comments_nb_total+"</span>");

    function highlightComments() {
      document.querySelectorAll(".checkComment").forEach(function(el) {
        var parent = el.closest('tr');
        var checkbox = el.querySelector("input[type=checkbox]");
        if (parent && checkbox) {
          parent.classList.toggle('selectedComment', checkbox.checked);
        }
      });
    }

    document.querySelectorAll(".checkComment").forEach(function(el) {
      el.addEventListener('click', function(event) {
        var checkbox = this.querySelector("input[type=checkbox]");
        if (event.target.type !== 'checkbox' && checkbox) {
          checkbox.checked = !checkbox.checked;
        }
        highlightComments();
      });
    });

    var selectAllBtn = document.getElementById("commentSelectAll");
    if (selectAllBtn) {
      selectAllBtn.addEventListener('click', function(event) {
        event.preventDefault();
        document.querySelectorAll(".checkComment input[type=checkbox]").forEach(function(el) { el.checked = true; });
        highlightComments();
      });
    }

    var selectNoneBtn = document.getElementById("commentSelectNone");
    if (selectNoneBtn) {
      selectNoneBtn.addEventListener('click', function(event) {
        event.preventDefault();
        document.querySelectorAll(".checkComment input[type=checkbox]").forEach(function(el) { el.checked = false; });
        highlightComments();
      });
    }

    var selectInvertBtn = document.getElementById("commentSelectInvert");
    if (selectInvertBtn) {
      selectInvertBtn.addEventListener('click', function(event) {
        event.preventDefault();
        document.querySelectorAll(".checkComment input[type=checkbox]").forEach(function(el) { el.checked = !el.checked; });
        highlightComments();
      });
    }
  });
});
