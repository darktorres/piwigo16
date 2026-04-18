const sessionStorage = window.sessionStorage || {};

const menubar = document.getElementById('menubar');
const menuswitcher = document.getElementById('menuSwitcher');
const content = document.querySelector('#the_page > .content');
const pcontent = document.getElementById('content');

function hideMenu() {
    if (menubar) menubar.style.display = 'none';
    [menuswitcher, content, pcontent].forEach(function (el) {
        if (el) { el.classList.add('menuhidden'); el.classList.remove('menushown'); }
    });
    sessionStorage["page-menu"] = "hidden";
}

function showMenu() {
    if (menubar) menubar.style.display = '';
    [menuswitcher, content, pcontent].forEach(function (el) {
        if (el) { el.classList.add('menushown'); el.classList.remove('menuhidden'); }
    });
    sessionStorage["page-menu"] = "visible";
}

document.addEventListener('DOMContentLoaded', function () {
    if (menubar && window.p_main_menu != "disabled") {
        if (menuswitcher) menuswitcher.innerHTML = '<div class="switchArrow">&nbsp;</div>';

        if (sessionStorage["page-menu"] === undefined && window.p_main_menu == "off") {
            sessionStorage["page-menu"] = "hidden";
        }

        if (sessionStorage["page-menu"] == "hidden") {
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
    } else if (menubar && window.p_main_menu == "disabled") {
        showMenu();
    }
});
