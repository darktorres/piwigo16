document.addEventListener("DOMContentLoaded", function () {
    // Grid view button click
    var btnGrid = document.getElementById("btn-grid");
    if (btnGrid) {
        btnGrid.addEventListener("click", function () {
            if (this.classList.contains("active")) {
                return;
            }
            setCookie("view", "grid");
            btnGrid.classList.add("active");
            document.getElementById("btn-list").classList.remove("active");
            var content = document.getElementById("content");
            content.classList.remove("content-list");
            content.classList.add("content-grid");

            var colOuters = content.querySelectorAll(".col-outer");
            colOuters.forEach(function (colOuter) {
                var cardBody = colOuter.querySelector(".card-body");
                if (cardBody) cardBody.removeAttribute("style");

                var link = colOuter.querySelector("a");
                if (link) {
                    link.classList.add("d-block");
                    var cardImgLeft = link.querySelector(".card-img-left");
                    if (cardImgLeft) {
                        cardImgLeft.classList.add("card-img-top");
                        cardImgLeft.classList.remove("card-img-left");
                    }
                }

                var listViewOnly = colOuter.querySelector(".card-body.list-view-only");
                if (listViewOnly) listViewOnly.classList.add("d-none");

                var addCollection = colOuter.querySelector(".addCollection");
                if (addCollection) addCollection.removeAttribute("style");

                var gridClasses = colOuter.dataset.gridClasses;
                colOuter.classList.remove("col-12");
                if (gridClasses) colOuter.classList.add(gridClasses);

                colOuter.addEventListener("webkitTransitionEnd", function () {
                    document.querySelectorAll("#content .card-body").forEach(function (cb) {
                        cb.removeAttribute("style");
                    });
                    equalHeights();
                }, { once: true });
            });
        });
    }

    // List view button click
    var btnList = document.getElementById("btn-list");
    if (btnList) {
        btnList.addEventListener("click", function () {
            if (this.classList.contains("active")) {
                return;
            }
            setCookie("view", "list");
            btnList.classList.add("active");
            document.getElementById("btn-grid").classList.remove("active");
            var content = document.getElementById("content");
            content.classList.remove("content-grid");
            content.classList.add("content-list");
            content.style.height = "auto";

            var colOuters = content.querySelectorAll(".col-outer");
            colOuters.forEach(function (colOuter) {
                var link = colOuter.querySelector("a");
                if (link) {
                    link.classList.remove("d-block");
                    var cardImgTop = link.querySelector(".card-img-top");
                    if (cardImgTop) {
                        cardImgTop.classList.add("card-img-left");
                        cardImgTop.classList.remove("card-img-top");
                    }
                }

                var listViewOnly = colOuter.querySelector(".card-body.list-view-only");
                if (listViewOnly) listViewOnly.classList.remove("d-none");

                var addCollection = colOuter.querySelector(".addCollection");
                if (addCollection) {
                    var img = colOuter.querySelector("img");
                    var width = img ? img.width : 0;
                    addCollection.setAttribute("style", "width: " + width + "px");
                }

                var gridClasses = colOuter.dataset.gridClasses;
                if (gridClasses) colOuter.classList.remove(gridClasses);
                colOuter.classList.add("col-12");

                colOuter.addEventListener("webkitTransitionEnd", function () {
                    document.querySelectorAll("#content .card-body").forEach(function (cb) {
                        cb.removeAttribute("style");
                    });
                    equalHeights();
                }, { once: true });
            });
        });
    }

    // Side bar
    var sidebar = document.getElementById("sidebar");
    var navigationButtons = document.getElementById("navigationButtons");
    if (sidebar && navigationButtons) {
        var navTop = navigationButtons.getBoundingClientRect().top + window.scrollY;
        sidebar.style.top = (navTop + 1) + "px";

        var infoLink = document.getElementById("info-link");
        if (infoLink) {
            infoLink.addEventListener("click", function (e) {
                e.preventDefault();
                var sidebarEl = document.getElementById("sidebar");
                if (sidebarEl) {
                    var rightValue = parseInt(window.getComputedStyle(sidebarEl).right);
                    var newRight = rightValue < 0 ? rightValue + 250 : rightValue - 250;
                    sidebarEl.style.right = newRight + "px";
                }
                return false;
            });
        }
    }
});

/* help popup */
function bd_popup(url) {
    window.open(
        url,
        "bd_popup",
        "alwaysRaised=yes,dependent=yes,toolbar=no,height=420,width=500,menubar=no,resizable=yes,scrollbars=yes,status=no",
    );
}

/* Set cookie using native API */
function setCookie(name, value, days) {
    var expires = "";
    if (days) {
        var date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        expires = "; expires=" + date.toUTCString();
    }
    document.cookie = name + "=" + (value || "") + expires + "; path=/";
}

/* Get cookie using native API */
function getCookie(name) {
    var nameEQ = name + "=";
    var cookies = document.cookie.split(';');
    for (var i = 0; i < cookies.length; i++) {
        var cookie = cookies[i].trim();
        if (cookie.indexOf(nameEQ) === 0) return cookie.substring(nameEQ.length);
    }
    return null;
}

/* Equal heights helper - if equalHeights is used elsewhere, we provide a stub */
function equalHeights() {
    // Placeholder for any custom equal heights logic
    // This may be defined in other scripts or can be extended as needed
}

/* changeElementType: this function changes element types. e.g. <div> to <ul> */
function changeElementType(element, newType) {
    var attrs = {};
    if (!element || !element.attributes) return;

    for (var i = 0; i < element.attributes.length; i++) {
        var attr = element.attributes[i];
        attrs[attr.nodeName] = attr.nodeValue;
    }

    var newElement = document.createElement(newType);
    for (var key in attrs) {
        if (attrs.hasOwnProperty(key)) {
            newElement.setAttribute(key, attrs[key]);
        }
    }

    while (element.firstChild) {
        newElement.appendChild(element.firstChild);
    }

    element.parentNode.replaceChild(newElement, element);
}

/* change rgba alpha */
function setColorOpacity(colorStr, opacity) {
    if (colorStr.indexOf("rgb(") == 0) {
        var rgbaCol = colorStr.replace("rgb(", "rgba(");
        rgbaCol = rgbaCol.replace(")", ", " + opacity + ")");
        return rgbaCol;
    }

    if (colorStr.indexOf("rgba(") == 0) {
        var rgbaCol =
            colorStr.substr(0, colorStr.lastIndexOf(",") + 1) + opacity + ")";
        return rgbaCol;
    }

    if (colorStr.length == 6) colorStr = "#" + colorStr;

    if (colorStr.indexOf("#") == 0) {
        var rgbaCol =
            "rgba(" +
            parseInt(colorStr.slice(-6, -4), 16) +
            "," +
            parseInt(colorStr.slice(-4, -2), 16) +
            "," +
            parseInt(colorStr.slice(-2), 16) +
            "," +
            opacity +
            ")";
        return rgbaCol;
    }
    return colorStr;
}
