(function () {
    var session_storage = window.sessionStorage || {};

    var menubar = document.getElementById('menubar');
    var menuswitcher = null;
    var content = document.querySelector('#the_page > .content');
    var pcontent = document.getElementById('content');
    var imageInfos = document.getElementById('imageInfos');
    var infoswitcher = null;
    var theImage = document.getElementById('theImage');
    var comments = document.querySelector('#thePicturePage #comments');
    var comments_button = null;
    var commentsswitcher = null;
    var comments_add = null;
    var comments_top_offset = 0;

    function hideMenu() {
        if (menubar) menubar.style.display = 'none';
        [menuswitcher, content, pcontent].forEach(function (el) {
            if (el) { el.classList.add('menuhidden'); el.classList.remove('menushown'); }
        });
        session_storage["picture-menu"] = "hidden";
    }

    function showMenu() {
        if (menubar) menubar.style.display = '';
        [menuswitcher, content, pcontent].forEach(function (el) {
            if (el) { el.classList.add('menushown'); el.classList.remove('menuhidden'); }
        });
        session_storage["picture-menu"] = "visible";
    }

    function hideInfo() {
        if (imageInfos) imageInfos.style.display = 'none';
        [infoswitcher, theImage].forEach(function (el) {
            if (el) { el.classList.add('infohidden'); el.classList.remove('infoshown'); }
        });
        session_storage["side-info"] = "hidden";
    }

    function showInfo() {
        if (imageInfos) imageInfos.style.display = '';
        [infoswitcher, theImage].forEach(function (el) {
            if (el) { el.classList.add('infoshown'); el.classList.remove('infohidden'); }
        });
        session_storage["side-info"] = "visible";
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
            session_storage["comments"] = "visible";
            if (comments_add) {
                var marginTop = parseFloat(
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
            session_storage["comments"] = "hidden";
            comments_top_offset = 0;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        // side-menu show/hide
        if (menubar && p_main_menu != "disabled") {
            menuswitcher = document.getElementById('menuSwitcher');
            if (menuswitcher) menuswitcher.innerHTML = '<div class="switchArrow">&nbsp;</div>';

            if (session_storage["picture-menu"] === undefined && p_main_menu == "off") {
                session_storage["picture-menu"] = "hidden";
            }

            if (session_storage["picture-menu"] == "hidden") {
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
        if (imageInfos && p_pict_descr != "disabled") {
            infoswitcher = document.getElementById('infoSwitcher');
            if (infoswitcher) infoswitcher.innerHTML = '<div class="switchArrow">&nbsp;</div>';

            if (session_storage["side-info"] === undefined && p_pict_descr == "off") {
                session_storage["side-info"] = "hidden";
            }

            if (session_storage["side-info"] == "hidden") {
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
        if (comments && p_pict_comment != "disabled") {
            commentsswitcher = document.getElementById('commentsSwitcher');
            comments_button = document.querySelector('#comments h3');
            comments_add = document.getElementById('commentAdd');

            if (commentsswitcher) commentsswitcher.innerHTML = '<div class="switchArrow">&nbsp;</div>';

            if (!comments_button) {
                var addComment = document.getElementById('addComment');
                if (addComment) {
                    var h3 = document.createElement('h3');
                    h3.textContent = 'Comments';
                    addComment.parentNode.insertBefore(h3, addComment);
                    comments_button = document.querySelector('#comments h3');
                }
            }

            if (session_storage["comments"] === undefined && p_pict_comment == "off") {
                session_storage["comments"] = "hidden";
            }

            if (session_storage["comments"] == "hidden") {
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

                var y = window.scrollY;
                var commentsTop = comments.getBoundingClientRect().top + window.scrollY;

                if (y >= comments_top_offset) {
                    comments_add.style.position = "absolute";
                    comments_add.style.top = (y - commentsTop + 10) + "px";
                } else {
                    comments_add.style.position = "static";
                    comments_add.style.top = "0";
                }
            });

            if (comments_add && comments_add.offsetParent !== null) {
                var marginTop = parseFloat(
                    window.getComputedStyle(comments_add).marginTop.replace(/auto/, 0)
                );
                comments_top_offset = comments_add.getBoundingClientRect().top + window.scrollY - marginTop;
            }
        }
    });
})();
