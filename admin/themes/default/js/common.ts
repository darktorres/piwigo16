export function initFontCheckbox(container: Element): void {
    container.querySelectorAll<HTMLInputElement>("input[type=checkbox]").forEach(function (cb) {
        const prev = cb.previousElementSibling;
        if (prev && !cb.checked) {
            prev.classList.toggle("icon-check");
            prev.classList.toggle("icon-check-empty");
        }
        cb.addEventListener("change", function () {
            const p = this.previousElementSibling;
            if (!p) return;
            p.className = '';
            p.classList.add(this.checked ? "icon-check" : "icon-check-empty");
        });
    });

    container.querySelectorAll<HTMLInputElement>("input[type=radio]").forEach(function (radio) {
        const prev = radio.previousElementSibling;
        if (prev && !radio.checked) {
            prev.classList.toggle("icon-dot-circled");
            prev.classList.toggle("icon-circle-empty");
        } else if (prev && radio.checked) {
            const label = radio.closest("label");
            if (label) label.classList.add("selected");
        }
        radio.addEventListener("change", function () {
            document.querySelectorAll<HTMLInputElement>('.font-checkbox input[type=radio][name="' + this.name + '"]').forEach(function (r) {
                const p = r.previousElementSibling;
                if (p) p.className = '';
                const label = r.closest("label");
                if (label) label.classList.remove("selected");
                if (p) p.classList.add(r.checked ? "icon-dot-circled" : "icon-circle-empty");
                if (r.checked && label) label.classList.add("selected");
            });
        });
    });
}

document.querySelectorAll(".font-checkbox").forEach(function (el) {
    initFontCheckbox(el);
});

export function array_delete<T>(arr: T[], item: T): void {
    const i = arr.indexOf(item);
    if (i !== -1) arr.splice(i, 1);
}

export function str_repeat(i: string, m: number): string {
    const o: string[] = [];
    let n = m;
    while (n > 0) o[--n] = i;
    return o.join("");
}

export function getRandomInt(min: number, max: number): number {
    return Math.floor(Math.random() * (Math.floor(max) - Math.ceil(min))) + Math.ceil(min);
}

export function sprintf(f: string, ...args: (string | number)[]): string {
    let i = 0;
    const o: string[] = [];
    let remaining = f;
    let m: RegExpExecArray | null;

    while (remaining) {
        if ((m = /^[^\x25]+/.exec(remaining))) {
            o.push(m[0]);
        } else if ((m = /^\x25{2}/.exec(remaining))) {
            o.push("%");
        } else if (
            (m = /^\x25(?:(\d+)\$)?(\+)?(0|'[^$])?(-)?(\d+)?(?:\.(\d+))?([b-fosuxX])/.exec(remaining))
        ) {
            let a: string | number = args[(m[1] ? parseInt(m[1]) - 1 : i++)];
            if (a == null) throw "Too few arguments.";
            if (/[^s]/.test(m[7]) && typeof a !== "number") throw "Expecting number but found " + typeof a;

            let aStr: string;
            const n = a as number;
            switch (m[7]) {
                case "b": aStr = n.toString(2); break;
                case "c": aStr = String.fromCharCode(n); break;
                case "d": aStr = parseInt(String(a)).toString(); break;
                case "e": aStr = m[6] ? n.toExponential(parseInt(m[6])) : n.toExponential(); break;
                case "f": aStr = m[6] ? parseFloat(String(a)).toFixed(parseInt(m[6])) : String(parseFloat(String(a))); break;
                case "o": aStr = n.toString(8); break;
                case "s": aStr = m[6] ? String(a).substring(0, parseInt(m[6])) : String(a); break;
                case "u": aStr = String(Math.abs(n)); break;
                case "x": aStr = n.toString(16); break;
                case "X": aStr = n.toString(16).toUpperCase(); break;
                default: throw "Huh ?!";
            }

            if (/[def]/.test(m[7]) && m[2] && n >= 0) aStr = "+" + aStr;
            const c = m[3] ? (m[3] === "0" ? "0" : m[3].charAt(1)) : " ";
            const width = m[5] ? parseInt(m[5]) : 0;
            const pad = width > aStr.length ? str_repeat(c, width - aStr.length) : "";
            o.push(m[4] ? aStr + pad : pad + aStr);
        } else {
            throw "Huh ?!";
        }

        remaining = remaining.substring(m[0].length);
    }

    return o.join("");
}

document.querySelectorAll(".search-cancel").forEach(function (el) {
    el.addEventListener("click", function () {
        document.querySelectorAll<HTMLInputElement>(".search-input").forEach(function (input) {
            input.value = "";
            input.dispatchEvent(new Event("input"));
        });
    });
});

document.querySelectorAll<HTMLInputElement>(".search-input").forEach(function (el) {
    el.addEventListener("input", function () {
        const show = (this).value !== "";
        document.querySelectorAll<HTMLElement>(".search-cancel").forEach(function (cancel) {
            cancel.style.display = show ? '' : 'none';
        });
    });
});

type AttrChange = { object: Element; attribute: string; value: string | null };
type ClassChange = { object: Element; state: boolean; class: string };
type HtmlChange = { object: Element; html: string };

export class TemporaryState {
    private attrChanges: AttrChange[] = [];
    private classChanges: ClassChange[] = [];
    private htmlChanges: HtmlChange[] = [];

    changeAttribute(obj: Element | NodeList, attr: string, tempVal: string): void {
        const elements: Element[] = obj instanceof NodeList ? Array.from(obj) as Element[] : [obj];
        for (const el of elements) {
            this.attrChanges.push({ object: el, attribute: attr, value: el.getAttribute(attr) });
            el.setAttribute(attr, tempVal);
        }
    }

    changeClass(obj: Element | NodeList, st: boolean, tempclass: string): void {
        const elements: Element[] = obj instanceof NodeList ? Array.from(obj) as Element[] : [obj];
        for (const el of elements) {
            if (!(el.classList.contains(tempclass) && st)) {
                this.classChanges.push({ object: el, state: !st, class: tempclass });
                if (st) el.classList.add(tempclass);
                else el.classList.remove(tempclass);
            }
        }
    }

    addClass(obj: Element | NodeList, tempclass: string): void {
        this.changeClass(obj, true, tempclass);
    }

    removeClass(obj: Element | NodeList, tempclass: string): void {
        this.changeClass(obj, false, tempclass);
    }

    changeHTML(obj: Element | NodeList, temphtml: string): void {
        const elements: Element[] = obj instanceof NodeList ? Array.from(obj) as Element[] : [obj];
        for (const el of elements) {
            this.htmlChanges.push({ object: el, html: el.innerHTML });
            el.innerHTML = temphtml;
        }
    }

    reverse(): void {
        for (const change of this.attrChanges) {
            if (change.value == null) change.object.removeAttribute(change.attribute);
            else change.object.setAttribute(change.attribute, change.value);
        }
        for (const change of this.classChanges) {
            if (change.state) change.object.classList.add(change.class);
            else change.object.classList.remove(change.class);
        }
        for (const change of this.htmlChanges) {
            change.object.innerHTML = change.html;
        }
        this.attrChanges = [];
        this.classChanges = [];
        this.htmlChanges = [];
    }
}
