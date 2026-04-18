const sessionStorage = window.sessionStorage || {};

let menubar = document.getElementById('menubar');
let menuswitcher = null;
let content = document.querySelector('#the_page > .content');
let pcontent = document.getElementById('content');
let imageInfos = document.getElementById('imageInfos');
let infoswitcher = null;
let theImage = document.getElementById('theImage');
let comments = document.querySelector('#thePicturePage #comments');
let comments_button = null;
let commentsswitcher = null;
let comments_add = null;
let comments_top_offset = 0;

function hideMenu() {
    if (menubar) menubar.style.display = 'none';
    [menuswitcher, content, pcontent].forEach(function (el) {
        if (el) { el.classList.add('menuhidden'); el.classList.remove('menushown'); }
    });
    sessionStorage["picture-menu"] = "hidden";
}

function showMenu() {
    if (menubar) menubar.style.display = '';
    [menuswitcher, content, pcontent].forEach(function (el) {
        if (el) { el.classList.add('menushown'); el.classList.remove('menuhidden'); }
    });
    sessionStorage["picture-menu"] = "visible";
}

function hideInfo() {
    if (imageInfos) imageInfos.style.display = 'none';
    [infoswitcher, theImage].forEach(function (el) {
        if (el) { el.classList.add('infohidden'); el.classList.remove('infoshown'); }
    });
    sessionStorage["side-info"] = "hidden";
}

function showInfo() {
    if (imageInfos) imageInfos.style.display = '';
    [infoswitcher, theImage].forEach(function (el) {
        if (el) { el.classList.add('infoshown'); el.classList.remove('infohidden'); }
    });
    sessionStorage["side-info"] = "visible";
}

function commentsToggle() {
    if (!comments) return;
    if (comments.classList.contains("commentshidden")) {
        comments.classList.remove("commentshidden");
        comments.classList.add("commentsshown");
        if (comments_button) {
            comments_button.classList.add("comments_toggle_off");
            comments_button.classList.remove("comments_toggle_on");
        }
        sessionStorage["comments"] = "visible";
        if (comments_add) {
            const marginTop = parseFloat(
                window.getComputedStyle(comments_add).marginTop.replace(/auto/, 0)
            );
            comments_top_offset = comments_add.getBoundingClientRect().top + window.scrollY - marginTop;
        }
    } else {
        comments.classList.add("commentshidden");
        comments.classList.remove("commentsshown");
        if (comments_button) {
            comments_button.classList.add("comments_toggle_on");
            comments_button.classList.remove("comments_toggle_off");
        }
        sessionStorage["comments"] = "hidden";
        comments_top_offset = 0;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // side-menu show/hide
    if (menubar && window.p_main_menu != "disabled") {
        menuswitcher = document.getElementById('menuSwitcher');
        if (menuswitcher) menuswitcher.innerHTML = '<div class="switchArrow">&nbsp;</div>';

        if (sessionStorage["picture-menu"] === undefined && window.p_main_menu == "off") {
            sessionStorage["picture-menu"] = "hidden";
        }

        if (sessionStorage["picture-menu"] == "hidden") {
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
    }

    // info show/hide
    if (imageInfos && window.p_pict_descr != "disabled") {
        infoswitcher = document.getElementById('infoSwitcher');
        if (infoswitcher) infoswitcher.innerHTML = '<div class="switchArrow">&nbsp;</div>';

        if (sessionStorage["side-info"] === undefined && window.p_pict_descr == "off") {
            sessionStorage["side-info"] = "hidden";
        }

        if (sessionStorage["side-info"] == "hidden") {
            hideInfo();
        } else {
            showInfo();
        }

        if (infoswitcher) {
            infoswitcher.addEventListener('click', function (e) {
                if (window.getComputedStyle(imageInfos).display === 'none') {
                    showInfo();
                } else {
                    hideInfo();
                }
                e.preventDefault();
            });
        }
    }

    // comments show/hide
    if (comments && window.p_pict_comment != "disabled") {
        commentsswitcher = document.getElementById('commentsSwitcher');
        comments_button = document.querySelector('#comments h3');
        comments_add = document.getElementById('commentAdd');

        if (commentsswitcher) commentsswitcher.innerHTML = '<div class="switchArrow">&nbsp;</div>';

        if (!comments_button) {
            const addComment = document.getElementById('addComment');
            if (addComment) {
                const h3 = document.createElement('h3');
                h3.textContent = 'Comments';
                addComment.parentNode.insertBefore(h3, addComment);
                comments_button = document.querySelector('#comments h3');
            }
        }

        if (sessionStorage["comments"] === undefined && window.p_pict_comment == "off") {
            sessionStorage["comments"] = "hidden";
        }

        if (sessionStorage["comments"] == "hidden") {
            comments.classList.add("commentshidden");
            if (comments_button) comments_button.classList.add("comments_toggle", "comments_toggle_on");
        } else {
            comments.classList.add("commentsshown");
            if (comments_button) comments_button.classList.add("comments_toggle", "comments_toggle_off");
        }

        if (comments_button) comments_button.addEventListener('click', commentsToggle);
        if (commentsswitcher) commentsswitcher.addEventListener('click', commentsToggle);

        window.addEventListener("scroll", function () {
            if (comments_top_offset == 0 || !comments_add || !comments) return;

            const y = window.scrollY;
            const commentsTop = comments.getBoundingClientRect().top + window.scrollY;

            if (y >= comments_top_offset) {
                comments_add.style.position = "absolute";
                comments_add.style.top = (y - commentsTop + 10) + "px";
            } else {
                comments_add.style.position = "static";
                comments_add.style.top = "0";
            }
        });

        if (comments_add && comments_add.offsetParent !== null) {
            const marginTop = parseFloat(
                window.getComputedStyle(comments_add).marginTop.replace(/auto/, 0)
            );
            comments_top_offset = comments_add.getBoundingClientRect().top + window.scrollY - marginTop;
        }
    }
});
