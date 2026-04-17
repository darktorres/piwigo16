function getCookieVal(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : undefined;
}

function setCookieVal(name, value) {
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/';
}

function applyStyles(selector, styles) {
    document.querySelectorAll(selector).forEach(function (el) {
        Object.assign(el.style, styles);
    });
}

function applyStyle(selector, prop, value) {
    document.querySelectorAll(selector).forEach(function (el) {
        el.style[prop] = value;
    });
}

function removeHoverEffect(elements) {
    elements.forEach(function (el) {
        if (el._hoverEnter) { el.removeEventListener('mouseenter', el._hoverEnter); delete el._hoverEnter; }
        if (el._hoverLeave) { el.removeEventListener('mouseleave', el._hoverLeave); delete el._hoverLeave; }
    });
}

function setDisplayCompact() {
    removeIconDesc();

    applyStyle(".albumActions", "display", "flex");
    removeHoverEffect(Array.from(document.querySelectorAll(".categoryBox")));
    removeHoverEffect(Array.from(document.querySelectorAll(".categoryBox .albumActions a")));

    document.querySelectorAll(".categoryBox .albumActions a").forEach(function (el) {
        el._hoverEnter = function () { this.style.color = "#000000"; };
        el._hoverLeave = function () { this.style.color = "#848484"; };
        el.addEventListener('mouseenter', el._hoverEnter);
        el.addEventListener('mouseleave', el._hoverLeave);
    });

    document.querySelectorAll(".categoryBox").forEach(function (el) {
        el.classList.remove("line_cat", "tile_cat");
    });
    document.querySelectorAll(".addAlbum").forEach(function (el) {
        el.classList.remove("tile_add");
    });
    applyStyles(".categoryBox", {
        minWidth: "250px",
        maxWidth: "350px",
        flexDirection: "column",
        maxHeight: "180px",
        alignItems: "unset",
        margin: "15px",
    });
    applyStyles(".albumInfos", { marginLeft: "0", flexDirection: "column" });
    applyStyles(".albumIcon", { height: "60px" });
    applyStyles(".albumIcon span", { fontSize: "14px", width: "20px", padding: "8px" });
    applyStyles(".albumInfos p", { margin: "0", textAlign: "center", whiteSpace: "normal" });
    applyStyle(".albumInfos p:last-child", "width", "auto");
    applyStyles(".albumTop", {
        width: "auto",
        justifyContent: "center",
        flexDirection: "row",
        alignItems: "baseline",
        height: "65px",
    });
    applyStyle(".albumTitle", "padding", "0 15px");
    applyStyles(".addAlbum", {
        minWidth: "250px",
        maxWidth: "350px",
        flexDirection: "column",
        maxHeight: "180px",
        margin: "15px",
    });
    applyStyle(".addAlbum form label", "display", "none");
    applyStyles(".addAlbumHead", {
        flexDirection: "column",
        transform: "translateY(55px)",
        alignItems: "center",
        marginTop: "-10px",
        transition: "0.4s ease",
        marginBottom: "0px",
    });
    applyStyles(".addAlbum form", {
        flexDirection: "column",
        marginTop: "0",
        marginBottom: "0",
        transitionDelay: "0s",
    });
    applyStyles(".addAlbum.input-mode form", { transitionDelay: "0.4s" });
    applyStyle(".addAlbum form input", "margin", "0px 10px 0px 10px");
    applyStyle(".addAlbum form button", "margin", "10px auto 0 auto");
    applyStyle(".addAlbum p", "marginBottom", "0px");
    applyStyle(".addAlbumHead p", "marginLeft", "0");
    applyStyles(".addAlbumHead span", { fontSize: "14px", width: "20px", height: "20px", padding: "8px" });
    applyStyles(".albumActions", { flexDirection: "row", marginTop: "auto", width: "100%" });
    applyStyle(".albumActions a", "minWidth", "0px");
    applyStyle(".albumActions a:first-child", "marginLeft", "35px");
    applyStyle(".albumActions a:last-child", "marginRight", "35px");
}

function setDisplayLine() {
    removeIconDesc();
    applyStyle(".albumActions", "display", "flex");
    removeHoverEffect(Array.from(document.querySelectorAll(".categoryBox")));

    document.querySelectorAll(".categoryBox").forEach(function (el) {
        el._hoverEnter = function () {
            this.style.background = "#ffd7ad";
            var albumInfos = this.querySelector(".albumInfos");
            if (albumInfos) albumInfos.style.color = "#515151";
            this.querySelectorAll(".albumActions a").forEach(function (a) { a.style.color = "#515151"; });
            this.querySelectorAll(".albumTop .albumIcon span").forEach(function (span) {
                span.classList.add("albumIconLineHover");
            });
        };
        el._hoverLeave = function () {
            this.style.background = "#fafafa";
            var albumInfos = this.querySelector(".albumInfos");
            if (albumInfos) albumInfos.style.color = "#a9a9a9";
            this.querySelectorAll(".albumActions a").forEach(function (a) { a.style.color = "#848484"; });
            this.querySelectorAll(".albumTop .albumIcon span").forEach(function (span) {
                span.classList.remove("albumIconLineHover");
            });
        };
        el.addEventListener('mouseenter', el._hoverEnter);
        el.addEventListener('mouseleave', el._hoverLeave);
    });

    document.querySelectorAll(".categoryBox .albumActions a").forEach(function (el) {
        el._hoverEnter = function () { this.style.color = "#000000"; };
        el._hoverLeave = function () { this.style.color = "#515151"; };
        el.addEventListener('mouseenter', el._hoverEnter);
        el.addEventListener('mouseleave', el._hoverLeave);
    });

    document.querySelectorAll(".categoryBox").forEach(function (el) {
        el.classList.add("line_cat");
        el.classList.remove("tile_cat");
    });
    document.querySelectorAll(".addAlbum").forEach(function (el) {
        el.classList.remove("tile_add");
    });
    applyStyles(".categoryBox", {
        minWidth: "90%",
        maxWidth: "100%",
        flexDirection: "row",
        maxHeight: "60px",
        alignItems: "unset",
        margin: "5px 15px",
    });
    applyStyles(".albumIcon", { height: "60px" });
    applyStyles(".albumIcon span", { fontSize: "14px", width: "20px", padding: "8px" });
    applyStyles(".addAlbumHead span", { fontSize: "14px", width: "20px", height: "20px", padding: "8px" });
    applyStyles(".albumInfos", {
        marginLeft: "auto",
        flexDirection: "row",
        justifyContent: "space-around",
        width: "auto",
    });
    applyStyles(".albumInfos p", { textAlign: "right", margin: "0", whiteSpace: "nowrap" });
    applyStyle(".albumInfos p:last-child", "width", "270px");
    applyStyles(".albumTop", {
        width: "35%",
        justifyContent: "flex-start",
        flexDirection: "row",
        alignItems: "baseline",
        height: "75px",
    });
    applyStyle(".albumTitle", "padding", "0 15px");
    applyStyles(".addAlbum", {
        minWidth: "90%",
        maxWidth: "100%",
        flexDirection: "row",
        maxHeight: "60px",
        margin: "15px 15px 5px 15px",
    });
    applyStyle(".addAlbum form label", "display", "none");
    applyStyles(".addAlbumHead", {
        flexDirection: "row",
        transform: "translateX(200px)",
        alignItems: "center",
        marginTop: "0",
        marginBottom: "0",
    });
    applyStyles(".addAlbum form", {
        flexDirection: "row",
        marginTop: "0",
        marginBottom: "0",
        transitionDelay: "0s",
    });
    applyStyles(".addAlbum.input-mode form", { transitionDelay: "0s" });
    applyStyle(".addAlbum form", "alignItems", "center");
    applyStyle(".addAlbum form input", "margin", "0px 10px 0px 10px");
    applyStyle(".addAlbum form button", "margin", "0px 20px");
    applyStyle(".addAlbum p", "marginBottom", "0px");
    applyStyle(".addAlbumHead p", "marginLeft", "15px");
    applyStyles(".albumActions", { flexDirection: "row", margin: "auto 0px", width: "300px" });
    applyStyle(".albumActions a", "minWidth", "30px");
    applyStyle(".albumActions a:first-child", "marginLeft", "35px");
    applyStyle(".albumActions a:last-child", "marginRight", "35px");
}

function setDisplayTile() {
    ShowIconDesc();

    applyStyle(".albumActions", "display", "flex");
    removeHoverEffect(Array.from(document.querySelectorAll(".categoryBox")));
    removeHoverEffect(Array.from(document.querySelectorAll(".categoryBox .albumActions a")));

    document.querySelectorAll(".categoryBox .albumActions a").forEach(function (el) {
        el._hoverEnter = function () { this.style.color = "#FFA646"; };
        el._hoverLeave = function () { this.style.color = "#848484"; };
        el.addEventListener('mouseenter', el._hoverEnter);
        el.addEventListener('mouseleave', el._hoverLeave);
    });

    AddHoverOnAlbumActions();

    applyStyles(".addAlbum.input-mode form", { transitionDelay: "0s" });
    document.querySelectorAll(".categoryBox").forEach(function (el) {
        el.classList.remove("line_cat");
        el.classList.add("tile_cat");
    });
    document.querySelectorAll(".addAlbum").forEach(function (el) {
        el.classList.add("tile_add");
    });
    applyStyles(".categoryBox", {
        minWidth: "220px",
        maxWidth: "280px",
        flexDirection: "column",
        maxHeight: "320px",
        alignItems: "center",
        margin: "15px",
    });
    applyStyles(".albumActions", {
        flexDirection: "column",
        margin: "auto",
        alignItems: "flex-start",
        width: "75%",
    });
    applyStyles(".albumInfos", { marginLeft: "0", flexDirection: "column" });
    applyStyle(".albumInfos p:last-child", "width", "auto");
    applyStyles(".albumInfos p", { margin: "0", textAlign: "center", whiteSpace: "normal" });
    applyStyles(".albumIcon", { height: "80px" });
    applyStyles(".albumIcon span", { fontSize: "19px", width: "27px", padding: "10px" });
    applyStyles(".albumTop", {
        width: "85%",
        flexDirection: "column",
        alignItems: "unset",
        height: "110px",
    });
    applyStyle(".albumTitle", "padding", "0");
    applyStyles(".addAlbum", {
        minWidth: "220px",
        maxWidth: "280px",
        flexDirection: "column",
        maxHeight: "320px",
        margin: "15px",
    });
    applyStyles(".addAlbumHead", {
        flexDirection: "column",
        transform: "translateY(75px)",
        alignItems: "center",
        marginTop: "10px",
        transition: "0.4s ease",
        marginBottom: "0",
    });
    applyStyles(".addAlbum form", {
        flexDirection: "column",
        marginTop: "auto",
        marginBottom: "20px",
        transitionDelay: "0s",
    });
    applyStyle(".addAlbum form input", "margin", "0px 10px 10px 10px");
    applyStyle(".addAlbum form button", "margin", "10px auto 0 auto");
    applyStyle(".addAlbum p", "marginBottom", "20px");
    applyStyles(".addAlbum form label", { display: "flex", margin: "-25px 0 0 15px" });
    applyStyle(".addAlbumHead p", "marginLeft", "0");
    applyStyles(".addAlbumHead span", { fontSize: "19px", width: "27px", height: "27px", padding: "10px" });
    applyStyle(".albumInfos p", "margin", "0");
    applyStyle(".albumActions a", "minWidth", "0px");
    applyStyle(".albumActions a:first-child", "marginLeft", "5px");
    applyStyle(".albumActions a:last-child", "marginLeft", "5px");
}

function ShowIconDesc() {
    document.querySelectorAll(".albumActions span.iconLegend").forEach(function (el) {
        el.style.display = '';
    });
}

function removeIconDesc() {
    document.querySelectorAll(".albumActions span.iconLegend").forEach(function (el) {
        el.style.display = 'none';
    });
}

function AddHoverOnAlbumActions() {
    applyStyle(".albumActions", "display", "none");
    document.querySelectorAll(".categoryBox").forEach(function (el) {
        el._hoverEnter = function () {
            var actions = this.querySelector(".albumActions");
            if (actions) actions.style.display = 'flex';
        };
        el._hoverLeave = function () {
            var actions = this.querySelector(".albumActions");
            if (actions) actions.style.display = 'none';
        };
        el.addEventListener('mouseenter', el._hoverEnter);
        el.addEventListener('mouseleave', el._hoverLeave);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll(".addAlbumHead").forEach(function (el) {
        el.addEventListener('click', function () {
            var input = document.querySelector(".addAlbum input[name=virtual_name]");
            if (input) input.focus();
        });
    });
});

document.addEventListener('DOMContentLoaded', function () {
    if (!getCookieVal("pwg_album_manager_view")) {
        setCookieVal("pwg_album_manager_view", "tile");
    }

    document.querySelectorAll(".addAlbum").forEach(function (el) {
        el.addEventListener("click", function (e) {
            if (e.target.className !== "cancelAddAlbum") {
                document.querySelectorAll(".addAlbum").forEach(function (a) {
                    a.classList.add("input-mode");
                });
                if (getCookieVal("pwg_album_manager_view") !== "tile") {
                    document.querySelectorAll(".addAlbum p").forEach(function (p) {
                        p.style.display = 'none';
                    });
                }
            }
        });
    });

    document.querySelectorAll(".cancelAddAlbum").forEach(function (btn) {
        btn.addEventListener("click", function () {
            document.querySelectorAll(".addAlbum").forEach(function (el) {
                el.classList.remove("input-mode");
            });
            document.querySelectorAll(".addAlbum p").forEach(function (p) {
                p.style.display = '';
            });
        });
    });

    var displayCompact = document.getElementById("displayCompact");
    var displayLine = document.getElementById("displayLine");
    var displayTile = document.getElementById("displayTile");

    if (displayCompact && displayCompact.checked) setDisplayCompact();
    if (displayLine && displayLine.checked) setDisplayLine();
    if (displayTile && displayTile.checked) setDisplayTile();

    if (displayCompact) {
        displayCompact.addEventListener("change", function () {
            setDisplayCompact();
            var addAlbumInputMode = document.querySelector(".addAlbum.input-mode");
            if (addAlbumInputMode) {
                document.querySelectorAll(".addAlbum p").forEach(function (p) { p.style.display = 'none'; });
            }
            setCookieVal("pwg_album_manager_view", "compact");
        });
    }

    if (displayLine) {
        displayLine.addEventListener("change", function () {
            setDisplayLine();
            var addAlbumInputMode = document.querySelector(".addAlbum.input-mode");
            if (addAlbumInputMode) {
                document.querySelectorAll(".addAlbum p").forEach(function (p) { p.style.display = 'none'; });
            }
            setCookieVal("pwg_album_manager_view", "line");
        });
    }

    if (displayTile) {
        displayTile.addEventListener("change", function () {
            setDisplayTile();
            var addAlbumInputMode = document.querySelector(".addAlbum.input-mode");
            if (addAlbumInputMode) {
                document.querySelectorAll(".addAlbum p").forEach(function (p) { p.style.display = ''; });
            }
            setCookieVal("pwg_album_manager_view", "tile");
        });
    }
});
