// ----- font-checkbox ---------------------------------------------------------

function applyFontCheckbox(container: HTMLElement): void {
    container.querySelectorAll<HTMLInputElement>('input[type=checkbox]').forEach((input) => {
        if (!input.checked) {
            input.previousElementSibling?.classList.toggle('icon-check', false);
            input.previousElementSibling?.classList.toggle('icon-check-empty', true);
        }
        input.addEventListener('change', () => {
            const prev = input.previousElementSibling as HTMLElement | null;
            if (!prev) return;
            prev.className = input.checked ? 'icon-check' : 'icon-check-empty';
        });
    });
    container.querySelectorAll<HTMLInputElement>('input[type=radio]').forEach((input) => {
        const prev = input.previousElementSibling as HTMLElement | null;
        if (!input.checked) {
            prev?.classList.toggle('icon-dot-circled', false);
            prev?.classList.toggle('icon-circle-empty', true);
        } else {
            input.closest('label')?.classList.add('selected');
        }
        input.addEventListener('change', () => {
            const name = input.name;
            container
                .querySelectorAll<HTMLInputElement>(
                    `.font-checkbox input[type=radio][name="${name}"]`
                )
                .forEach((r) => {
                    const rPrev = r.previousElementSibling as HTMLElement | null;
                    if (rPrev)
                        rPrev.className = r.checked ? 'icon-dot-circled' : 'icon-circle-empty';
                    r.closest('label')?.classList.toggle('selected', r.checked);
                });
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll<HTMLElement>('.font-checkbox').forEach(applyFontCheckbox);
});

// ----- helpers ---------------------------------------------------------------

function array_delete<T>(arr: T[], item: T): void {
    const i = arr.indexOf(item);
    if (i !== -1) arr.splice(i, 1);
}

function str_repeat(s: string, n: number): string {
    return n > 0 ? s.repeat(n) : '';
}

function getRandomInt(minIn: number, maxIn: number): number {
    const min = Math.ceil(minIn);
    const max = Math.floor(maxIn);
    return Math.floor(Math.random() * (max - min)) + min;
}

function sprintf(format: string, ...args: unknown[]): string {
    let i = 0;
    let f = format;
    const o: string[] = [];
    let m: RegExpExecArray | null;
    const s = '';
    while (f !== '') {
        if ((m = /^[^\x25]+/.exec(f))) {
            o.push(m[0]);
        } else if ((m = /^\x25{2}/.exec(f))) {
            o.push('%');
        } else if (
            (m = /^\x25(?:(\d+)\$)?(\+)?(0|'[^$])?(-)?(\d+)?(?:\.(\d+))?([b-fosuxX])/.exec(f))
        ) {
            const m1 = m[1], m2 = m[2] ?? '', m3 = m[3] ?? '', m4 = m[4] ?? '', m5 = m[5] ?? '', m6 = m[6] ?? '', m7 = m[7]!;
            const argRaw: unknown = args[m1 ? parseInt(m1) - 1 : i++];
            if (argRaw === null || argRaw === undefined) throw new Error('Too few arguments.');
            if (/[^s]/.test(m7) && typeof argRaw !== 'number')
                throw new Error('Expecting number but found ' + typeof argRaw);
            let a: string | number = argRaw as string | number;
            switch (m7) {
                case 'b':
                    a = Number(a).toString(2);
                    break;
                case 'c':
                    a = String.fromCharCode(Number(a));
                    break;
                case 'd':
                    a = parseInt(String(a));
                    break;
                case 'e':
                    a =
                        m6 !== ''
                            ? Number(a).toExponential(parseInt(m6))
                            : Number(a).toExponential();
                    break;
                case 'f':
                    a =
                        m6 !== ''
                            ? parseFloat(String(a)).toFixed(parseInt(m6))
                            : parseFloat(String(a));
                    break;
                case 'o':
                    a = Number(a).toString(8);
                    break;
                case 's':
                    a = m6 !== '' ? String(a).substring(0, parseInt(m6)) : String(a);
                    break;
                case 'u':
                    a = Math.abs(Number(a));
                    break;
                case 'x':
                    a = Number(a).toString(16);
                    break;
                case 'X':
                    a = Number(a).toString(16).toUpperCase();
                    break;
            }
            a = /[def]/.test(m7) && m2 !== '' && Number(a) >= 0 ? '+' + String(a) : a;
            const c = m3 !== '' ? (m3 === '0' ? '0' : m3.charAt(1)) : ' ';
            const x = (m5 !== '' ? parseInt(m5) : 0) - String(a).length - s.length;
            const p = m5 !== '' ? str_repeat(c, x) : '';
            o.push(s + (m4 !== '' ? String(a) + p : p + String(a)));
        } else {
            throw new Error('Huh ?!');
        }
        f = f.substring(m[0].length);
    }
    return o.join('');
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll<HTMLElement>('.search-cancel').forEach((el) => {
        el.addEventListener('click', () => {
            const input = document.querySelector<HTMLInputElement>('.search-input');
            if (input) {
                input.value = '';
                input.dispatchEvent(new Event('input'));
            }
        });
    });
    document.querySelectorAll<HTMLInputElement>('.search-input').forEach((el) => {
        el.addEventListener('input', function (this: HTMLInputElement) {
            document.querySelectorAll<HTMLElement>('.search-cancel').forEach((c) => {
                c.style.display = this.value === '' ? 'none' : '';
            });
        });
    });
});

// ----- TemporaryState --------------------------------------------------------

interface AttrChange {
    element: HTMLElement;
    attribute: string;
    value: string | null;
}
interface ClassChange {
    element: HTMLElement;
    state: boolean;
    cls: string;
}
interface HtmlChange {
    element: HTMLElement;
    html: string;
}

class TemporaryState {
    private attrChanges: AttrChange[] = [];
    private classChanges: ClassChange[] = [];
    private htmlChanges: HtmlChange[] = [];

    changeAttribute(el: HTMLElement, attr: string, tempVal: string): void {
        this.attrChanges.push({ element: el, attribute: attr, value: el.getAttribute(attr) });
        el.setAttribute(attr, tempVal);
    }

    private changeClass(el: HTMLElement, state: boolean, cls: string): void {
        if (!(el.classList.contains(cls) && state)) {
            this.classChanges.push({ element: el, state: !state, cls });
            if (state) el.classList.add(cls);
            else el.classList.remove(cls);
        }
    }

    addClass(el: HTMLElement, cls: string): void {
        this.changeClass(el, true, cls);
    }
    removeClass(el: HTMLElement, cls: string): void {
        this.changeClass(el, false, cls);
    }

    changeHTML(el: HTMLElement, html: string): void {
        this.htmlChanges.push({ element: el, html: el.innerHTML });
        el.innerHTML = html;
    }

    reverse(): void {
        this.attrChanges.forEach(({ element, attribute, value }) => {
            if (value === null) element.removeAttribute(attribute);
            else element.setAttribute(attribute, value);
        });
        this.classChanges.forEach(({ element, state, cls }) => {
            if (state) element.classList.add(cls);
            else element.classList.remove(cls);
        });
        this.htmlChanges.forEach(({ element, html }) => {
            element.innerHTML = html;
        });
        this.attrChanges = [];
        this.classChanges = [];
        this.htmlChanges = [];
    }
}

// ----- jConfirm option sets (kept for albums.ts and unconverted callers) -----

const jConfirm_alert_options = {
    icon: 'icon-ok',
    titleClass: 'jconfirmAlert',
    theme: 'modern',
    closeIcon: true,
    draggable: false,
    animation: 'zoom',
    boxWidth: '20%',
    useBootstrap: false,
    backgroundDismiss: true,
    animateFromElement: false,
    typeAnimated: false,
};

const jConfirm_confirm_options = {
    draggable: false,
    titleClass: 'jconfirmDeleteConfirm',
    theme: 'modern',
    animation: 'zoom',
    boxWidth: '40%',
    useBootstrap: false,
    type: 'red',
    animateFromElement: false,
    backgroundDismiss: true,
    typeAnimated: false,
};

const jConfirm_warning_options = {
    icon: 'icon-attention',
    draggable: false,
    titleClass: 'jconfirmWarning jconfirmAlert',
    theme: 'modern',
    type: 'orange',
    closeIcon: true,
    animation: 'zoom',
    boxWidth: '20%',
    useBootstrap: false,
    backgroundDismiss: true,
    animateFromElement: false,
    typeAnimated: false,
};

const jConfirm_confirm_with_content_options = {
    draggable: false,
    theme: 'modern',
    animation: 'zoom',
    boxWidth: '40%',
    useBootstrap: false,
    type: 'red',
    animateFromElement: false,
    backgroundDismiss: true,
    typeAnimated: false,
};

// ----- standalone pwg_jconfirm_follow_href -----------

function pwg_jconfirm_follow_href_fn(
    el: HTMLElement,
    options: {
        alert_title?: string;
        alert_confirm?: string;
        alert_cancel?: string;
        alert_content?: string;
    } = {}
): void {
    const href = el.getAttribute('href');
    el.addEventListener('click', (e) => {
        e.preventDefault();
        const msg =
            (options.alert_title ?? 'TITLE') +
            (options.alert_content !== undefined && options.alert_content !== ''
                ? '\n\n' + options.alert_content
                : '');
        if (window.confirm(msg)) window.location.href = href!;
    });
}

// ----- expose globals --------------------------------------------------------

interface CommonGlobals {
    applyFontCheckbox: typeof applyFontCheckbox;
    pwg_jconfirm_follow_href_fn: typeof pwg_jconfirm_follow_href_fn;
    array_delete: typeof array_delete;
    str_repeat: typeof str_repeat;
    getRandomInt: typeof getRandomInt;
    sprintf: typeof sprintf;
    TemporaryState: typeof TemporaryState;
    jConfirm_alert_options: typeof jConfirm_alert_options;
    jConfirm_confirm_options: typeof jConfirm_confirm_options;
    jConfirm_warning_options: typeof jConfirm_warning_options;
    jConfirm_confirm_with_content_options: typeof jConfirm_confirm_with_content_options;
}

const w = window as unknown as Window & Partial<CommonGlobals>;
w.applyFontCheckbox = applyFontCheckbox;
w.pwg_jconfirm_follow_href_fn = pwg_jconfirm_follow_href_fn;
w.array_delete = array_delete;
w.str_repeat = str_repeat;
w.getRandomInt = getRandomInt;
w.sprintf = sprintf;
w.TemporaryState = TemporaryState;
w.jConfirm_alert_options = jConfirm_alert_options;
w.jConfirm_confirm_options = jConfirm_confirm_options;
w.jConfirm_warning_options = jConfirm_warning_options;
w.jConfirm_confirm_with_content_options = jConfirm_confirm_with_content_options;

export {};
