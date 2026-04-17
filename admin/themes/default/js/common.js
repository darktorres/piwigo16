function initFontCheckbox(container) {
    /* checkbox */
    container.querySelectorAll("input[type=checkbox]").forEach(function(cb) {
        var prev = cb.previousElementSibling;
        if (prev && !cb.checked) {
            prev.classList.toggle("icon-check");
            prev.classList.toggle("icon-check-empty");
        }
        cb.addEventListener("change", function() {
            var prev = this.previousElementSibling;
            if (!prev) return;
            prev.className = '';
            prev.classList.add(this.checked ? "icon-check" : "icon-check-empty");
        });
    });

    /* radio */
    container.querySelectorAll("input[type=radio]").forEach(function(radio) {
        var prev = radio.previousElementSibling;
        if (prev && !radio.checked) {
            prev.classList.toggle("icon-dot-circled");
            prev.classList.toggle("icon-circle-empty");
        } else if (prev && radio.checked) {
            var label = radio.closest("label");
            if (label) label.classList.add("selected");
        }
        radio.addEventListener("change", function() {
            document.querySelectorAll('.font-checkbox input[type=radio][name="' + this.name + '"]').forEach(function(r) {
                var p = r.previousElementSibling;
                if (p) p.className = '';
                var label = r.closest("label");
                if (label) label.classList.remove("selected");
                if (p) p.classList.add(r.checked ? "icon-dot-circled" : "icon-circle-empty");
                if (r.checked && label) label.classList.add("selected");
            });
        });
    });
}

// init fontCheckbox everywhere
document.querySelectorAll(".font-checkbox").forEach(function(el) {
    initFontCheckbox(el);
});

function array_delete(arr, item) {
    var i = arr.indexOf(item);
    if (i != -1) arr.splice(i, 1);
}

function str_repeat(i, m) {
    for (var o = []; m > 0; o[--m] = i);
    return o.join("");
}

if (!Array.prototype.indexOf) {
    Array.prototype.indexOf = function (elt /*, from*/) {
        var len = this.length;

        var from = Number(arguments[1]) || 0;
        from = from < 0 ? Math.ceil(from) : Math.floor(from);
        if (from < 0) from += len;

        for (; from < len; from++) {
            if (from in this && this[from] === elt) return from;
        }
        return -1;
    };
}

function getRandomInt(min, max) {
    min = Math.ceil(min);
    max = Math.floor(max);
    return Math.floor(Math.random() * (max - min)) + min;
}

function sprintf() {
    var i = 0;
    var a;
    var f = arguments[i++];
    var o = [];
    var m;
    var p;
    var c;
    var x;
    var s = "";
    while (f) {
        if ((m = /^[^\x25]+/.exec(f))) {
            o.push(m[0]);
        } else if ((m = /^\x25{2}/.exec(f))) {
            o.push("%");
        } else if (
            (m =
                /^\x25(?:(\d+)\$)?(\+)?(0|'[^$])?(-)?(\d+)?(?:\.(\d+))?([b-fosuxX])/.exec(
                    f,
                ))
        ) {
            if ((a = arguments[m[1] || i++]) == null || a == undefined) {
                throw "Too few arguments.";
            }
            if (/[^s]/.test(m[7]) && typeof a != "number") {
                throw "Expecting number but found " + typeof a;
            }

            switch (m[7]) {
                case "b":
                    a = a.toString(2);
                    break;
                case "c":
                    a = String.fromCharCode(a);
                    break;
                case "d":
                    a = parseInt(a);
                    break;
                case "e":
                    a = m[6] ? a.toExponential(m[6]) : a.toExponential();
                    break;
                case "f":
                    a = m[6] ? parseFloat(a).toFixed(m[6]) : parseFloat(a);
                    break;
                case "o":
                    a = a.toString(8);
                    break;
                case "s":
                    a = (a = String(a)) && m[6] ? a.substring(0, m[6]) : a;
                    break;
                case "u":
                    a = Math.abs(a);
                    break;
                case "x":
                    a = a.toString(16);
                    break;
                case "X":
                    a = a.toString(16).toUpperCase();
                    break;
            }

            a = /[def]/.test(m[7]) && m[2] && a >= 0 ? "+" + a : a;
            c = m[3] ? (m[3] == "0" ? "0" : m[3].charAt(1)) : " ";
            x = m[5] - String(a).length - s.length;
            p = m[5] ? str_repeat(c, x) : "";
            o.push(s + (m[4] ? a + p : p + a));
        } else {
            throw "Huh ?!";
        }

        f = f.substring(m[0].length);
    }

    return o.join("");
}

document.querySelectorAll(".search-cancel").forEach(function (el) {
    el.addEventListener("click", function () {
        document.querySelectorAll(".search-input").forEach(function (input) {
            input.value = "";
            input.dispatchEvent(new Event("input"));
        });
    });
});

document.querySelectorAll(".search-input").forEach(function (el) {
    el.addEventListener("input", function () {
        if (this.value == "") {
            document.querySelectorAll(".search-cancel").forEach(function (cancel) {
                cancel.style.display = 'none';
            });
        } else {
            document.querySelectorAll(".search-cancel").forEach(function (cancel) {
                cancel.style.display = '';
            });
        }
    });
});

// Class to implement a temporary state and reverse it
class TemporaryState {
    constructor() {
        //Arrays to reverse changes
        this.attrChanges = []; //Attribute changes : {object(s), attribute, value}
        this.classChanges = []; //Class changes : {object(s), state(add:true/remove:false), class}
        this.htmlChanges = []; //Html changes : {object(s), html}
    }

    /**
     * Change temporarily an attribute of an object
     * @param {DOM Element(s)} obj HTML Element(s) or NodeList
     * @param {String} attr Attribute
     * @param {String} tempVal Temporary value of the attribute
     */
    changeAttribute(obj, attr, tempVal) {
        let elements = obj instanceof NodeList ? Array.from(obj) : (obj instanceof Element ? [obj] : []);
        for (let i = 0; i < elements.length; i++) {
            this.attrChanges.push({
                object: elements[i],
                attribute: attr,
                value: elements[i].getAttribute(attr),
            });
            elements[i].setAttribute(attr, tempVal);
        }
    }

    /**
     * Add/remove a class temporarily
     * @param {DOM Element(s)} obj HTML Element(s) or NodeList
     * @param {Boolean} st Add (true) or Remove (false) the class
     * @param {String} tempclass Class Name
     */
    changeClass(obj, st, tempclass) {
        let elements = obj instanceof NodeList ? Array.from(obj) : (obj instanceof Element ? [obj] : []);
        for (let i = 0; i < elements.length; i++) {
            if (!(elements[i].classList.contains(tempclass) && st)) {
                this.classChanges.push({
                    object: elements[i],
                    state: !st,
                    class: tempclass,
                });
                if (st) elements[i].classList.add(tempclass);
                else elements[i].classList.remove(tempclass);
            }
        }
    }

    /**
     * Add temporarily a class to the object
     * @param {DOM Element(s)} obj
     * @param {string} tempclass
     */
    addClass(obj, tempclass) {
        this.changeClass(obj, true, tempclass);
    }

    /**
     * Remove temporarily a class to the object
     * @param {DOM Element(s)} obj
     * @param {string} tempclass
     */
    removeClass(obj, tempclass) {
        this.changeClass(obj, false, tempclass);
    }

    /**
     * Change temporarily the html of objects (remove event handlers on the actual content)
     * @param {DOM Element(s)} obj
     * @param {string} temphtml
     */
    changeHTML(obj, temphtml) {
        let elements = obj instanceof NodeList ? Array.from(obj) : (obj instanceof Element ? [obj] : []);
        for (let i = 0; i < elements.length; i++) {
            this.htmlChanges.push({
                object: elements[i],
                html: elements[i].innerHTML,
            });
            elements[i].innerHTML = temphtml;
        }
    }

    /**
     * Reverse all the changes and clear the history
     */
    reverse() {
        this.attrChanges.forEach(function (change) {
            if (change.value == undefined) {
                change.object.removeAttribute(change.attribute);
            } else {
                change.object.setAttribute(change.attribute, change.value);
            }
        });
        this.classChanges.forEach(function (change) {
            if (change.state) change.object.classList.add(change.class);
            else change.object.classList.remove(change.class);
        });
        this.htmlChanges.forEach(function (change) {
            change.object.innerHTML = change.html;
        });
        this.attrChanges = [];
        this.classChanges = [];
        this.htmlChanges = [];
    }
}

