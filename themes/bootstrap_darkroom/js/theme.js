export function bd_popup(url) {
    window.open(
        url,
        "bd_popup",
        "alwaysRaised=yes,dependent=yes,toolbar=no,height=420,width=500,menubar=no,resizable=yes,scrollbars=yes,status=no",
    );
}

export function setCookie(name, value, days) {
    let expires = "";
    if (days) {
        const date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        expires = "; expires=" + date.toUTCString();
    }
    document.cookie = name + "=" + (value || "") + expires + "; path=/";
}

export function getCookie(name) {
    const nameEQ = name + "=";
    const cookies = document.cookie.split(';');
    for (let i = 0; i < cookies.length; i++) {
        const cookie = cookies[i].trim();
        if (cookie.indexOf(nameEQ) === 0) return cookie.substring(nameEQ.length);
    }
    return null;
}

export function equalHeights() {
    // Placeholder for any custom equal heights logic
    // This may be defined in other scripts or can be extended as needed
}

export function changeElementType(element, newType) {
    const attrs = {};
    if (!element || !element.attributes) return;

    for (let i = 0; i < element.attributes.length; i++) {
        const attr = element.attributes[i];
        attrs[attr.nodeName] = attr.nodeValue;
    }

    const newElement = document.createElement(newType);
    for (const key in attrs) {
        if (attrs.hasOwnProperty(key)) {
            newElement.setAttribute(key, attrs[key]);
        }
    }

    while (element.firstChild) {
        newElement.appendChild(element.firstChild);
    }

    element.parentNode.replaceChild(newElement, element);
}

export function setColorOpacity(colorStr, opacity) {
    if (colorStr.indexOf("rgb(") == 0) {
        let rgbaCol = colorStr.replace("rgb(", "rgba(");
        rgbaCol = rgbaCol.replace(")", ", " + opacity + ")");
        return rgbaCol;
    }

    if (colorStr.indexOf("rgba(") == 0) {
        const rgbaCol =
            colorStr.substr(0, colorStr.lastIndexOf(",") + 1) + opacity + ")";
        return rgbaCol;
    }

    if (colorStr.length == 6) colorStr = "#" + colorStr;

    if (colorStr.indexOf("#") == 0) {
        const rgbaCol =
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

document.addEventListener("DOMContentLoaded", function () {
    // Grid view button click
    const btnGrid = document.getElementById("btn-grid");
    if (btnGrid) {
        btnGrid.addEventListener("click", function () {
            if (this.classList.contains("active")) {
                return;
            }
            setCookie("view", "grid");
            btnGrid.classList.add("active");
            document.getElementById("btn-list").classList.remove("active");
            const content = document.getElementById("content");
            content.classList.remove("content-list");
            content.classList.add("content-grid");

            const colOuters = content.querySelectorAll(".col-outer");
            colOuters.forEach(function (colOuter) {
                const cardBody = colOuter.querySelector(".card-body");
                if (cardBody) cardBody.removeAttribute("style");

                const link = colOuter.querySelector("a");
                if (link) {
                    link.classList.add("d-block");
                    const cardImgLeft = link.querySelector(".card-img-left");
                    if (cardImgLeft) {
                        cardImgLeft.classList.add("card-img-top");
                        cardImgLeft.classList.remove("card-img-left");
                    }
                }

                const listViewOnly = colOuter.querySelector(".card-body.list-view-only");
                if (listViewOnly) listViewOnly.classList.add("d-none");

                const addCollection = colOuter.querySelector(".addCollection");
                if (addCollection) addCollection.removeAttribute("style");

                const gridClasses = colOuter.dataset.gridClasses;
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
    const btnList = document.getElementById("btn-list");
    if (btnList) {
        btnList.addEventListener("click", function () {
            if (this.classList.contains("active")) {
                return;
            }
            setCookie("view", "list");
            btnList.classList.add("active");
            document.getElementById("btn-grid").classList.remove("active");
            const content = document.getElementById("content");
            content.classList.remove("content-grid");
            content.classList.add("content-list");
            content.style.height = "auto";

            const colOuters = content.querySelectorAll(".col-outer");
            colOuters.forEach(function (colOuter) {
                const link = colOuter.querySelector("a");
                if (link) {
                    link.classList.remove("d-block");
                    const cardImgTop = link.querySelector(".card-img-top");
                    if (cardImgTop) {
                        cardImgTop.classList.add("card-img-left");
                        cardImgTop.classList.remove("card-img-top");
                    }
                }

                const listViewOnly = colOuter.querySelector(".card-body.list-view-only");
                if (listViewOnly) listViewOnly.classList.remove("d-none");

                const addCollection = colOuter.querySelector(".addCollection");
                if (addCollection) {
                    const img = colOuter.querySelector("img");
                    const width = img ? img.width : 0;
                    addCollection.setAttribute("style", "width: " + width + "px");
                }

                const gridClasses = colOuter.dataset.gridClasses;
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
    const sidebar = document.getElementById("sidebar");
    const navigationButtons = document.getElementById("navigationButtons");
    if (sidebar && navigationButtons) {
        const navTop = navigationButtons.getBoundingClientRect().top + window.scrollY;
        sidebar.style.top = (navTop + 1) + "px";

        const infoLink = document.getElementById("info-link");
        if (infoLink) {
            infoLink.addEventListener("click", function (e) {
                e.preventDefault();
                const sidebarEl = document.getElementById("sidebar");
                if (sidebarEl) {
                    const rightValue = parseInt(window.getComputedStyle(sidebarEl).right);
                    const newRight = rightValue < 0 ? rightValue + 250 : rightValue - 250;
                    sidebarEl.style.right = newRight + "px";
                }
                return false;
            });
        }
    }
});
