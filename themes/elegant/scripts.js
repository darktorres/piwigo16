(function () {
    var session_storage = window.sessionStorage || {};

    var menubar = document.getElementById('menubar');
    var menuswitcher = document.getElementById('menuSwitcher');
    var content = document.querySelector('#the_page > .content');
    var pcontent = document.getElementById('content');

    function hideMenu() {
        if (menubar) menubar.style.display = 'none';
        [menuswitcher, content, pcontent].forEach(function (el) {
            if (el) { el.classList.add('menuhidden'); el.classList.remove('menushown'); }
        });
        session_storage["page-menu"] = "hidden";
    }

    function showMenu() {
        if (menubar) menubar.style.display = '';
        [menuswitcher, content, pcontent].forEach(function (el) {
            if (el) { el.classList.add('menushown'); el.classList.remove('menuhidden'); }
        });
        session_storage["page-menu"] = "visible";
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (menubar && p_main_menu != "disabled") {
            if (menuswitcher) menuswitcher.innerHTML = '<div class="switchArrow">&nbsp;</div>';

            if (session_storage["page-menu"] === undefined && p_main_menu == "off") {
                session_storage["page-menu"] = "hidden";
            }

            if (session_storage["page-menu"] == "hidden") {
                hideMenu();
            } else {
                showMenu();
            }

            if (menuswitcher) {
                menuswitcher.addEventListener('click', function (e) {
                    if (window.getComputedStyle(menubar).display === 'none') {
                        showMenu();
                    } else {
                        hideMenu();
                    }
                    e.preventDefault();
                });
            }
        } else if (menubar && p_main_menu == "disabled") {
            showMenu();
        }
    });
})();
