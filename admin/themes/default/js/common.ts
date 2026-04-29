// ----- font-checkbox ---------------------------------------------------------

function applyFontCheckbox(container: HTMLElement): void {
    container.querySelectorAll<HTMLInputElement>('input[type=checkbox]').forEach(input => {
        if (!input.checked) input.previousElementSibling?.classList.toggle('icon-check', false) || input.previousElementSibling?.classList.toggle('icon-check-empty', true);
        input.addEventListener('change', () => {
            const prev = input.previousElementSibling as HTMLElement | null;
            if (!prev) return;
            prev.className = input.checked ? 'icon-check' : 'icon-check-empty';
        });
    });
    container.querySelectorAll<HTMLInputElement>('input[type=radio]').forEach(input => {
        const prev = input.previousElementSibling as HTMLElement | null;
        if (!input.checked) {
            prev?.classList.toggle('icon-dot-circled', false);
            prev?.classList.toggle('icon-circle-empty', true);
        } else {
            input.closest('label')?.classList.add('selected');
        }
        input.addEventListener('change', () => {
            const name = input.name;
            container.querySelectorAll<HTMLInputElement>(`.font-checkbox input[type=radio][name="${name}"]`).forEach(r => {
                const rPrev = r.previousElementSibling as HTMLElement | null;
                if (rPrev) rPrev.className = r.checked ? 'icon-dot-circled' : 'icon-circle-empty';
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

function getRandomInt(min: number, max: number): number {
    min = Math.ceil(min);
    max = Math.floor(max);
    return Math.floor(Math.random() * (max - min)) + min;
}

function sprintf(format: string, ...args: unknown[]): string {
    let i = 0;
    let f = format;
    const o: string[] = [];
    let m: RegExpExecArray | null;
    let s = '';
    while (f) {
        if ((m = /^[^\x25]+/.exec(f))) {
            o.push(m[0]);
        } else if ((m = /^\x25{2}/.exec(f))) {
            o.push('%');
        } else if ((m = /^\x25(?:(\d+)\$)?(\+)?(0|'[^$])?(-)?(\d+)?(?:\.(\d+))?([b-fosuxX])/.exec(f))) {
            let a: any = args[m[1] ? parseInt(m[1]) - 1 : i++];
            if (a == null) throw new Error('Too few arguments.');
            if (/[^s]/.test(m[7]) && typeof a !== 'number') throw new Error('Expecting number but found ' + typeof a);
            switch (m[7]) {
                case 'b': a = Number(a).toString(2); break;
                case 'c': a = String.fromCharCode(Number(a)); break;
                case 'd': a = parseInt(a); break;
                case 'e': a = m[6] ? Number(a).toExponential(parseInt(m[6])) : Number(a).toExponential(); break;
                case 'f': a = m[6] ? parseFloat(a).toFixed(parseInt(m[6])) : parseFloat(a); break;
                case 'o': a = Number(a).toString(8); break;
                case 's': a = m[6] ? String(a).substring(0, parseInt(m[6])) : String(a); break;
                case 'u': a = Math.abs(Number(a)); break;
                case 'x': a = Number(a).toString(16); break;
                case 'X': a = Number(a).toString(16).toUpperCase(); break;
            }
            a = (/[def]/.test(m[7]) && m[2] && Number(a) >= 0 ? '+' + a : a);
            const c = m[3] ? (m[3] === '0' ? '0' : m[3].charAt(1)) : ' ';
            const x = (m[5] ? parseInt(m[5]) : 0) - String(a).length - s.length;
            const p = m[5] ? str_repeat(c, x) : '';
            o.push(s + (m[4] ? a + p : p + a));
        } else {
            throw new Error('Huh ?!');
        }
        f = f.substring(m![0].length);
    }
    return o.join('');
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll<HTMLElement>('.search-cancel').forEach(el => {
        el.addEventListener('click', () => {
            const input = document.querySelector<HTMLInputElement>('.search-input');
            if (input) { input.value = ''; input.dispatchEvent(new Event('input')); }
        });
    });
    document.querySelectorAll<HTMLInputElement>('.search-input').forEach(el => {
        el.addEventListener('input', function(this: HTMLInputElement) {
            document.querySelectorAll<HTMLElement>('.search-cancel').forEach(c => {
                c.style.display = this.value === '' ? 'none' : '';
            });
        });
    });
});

// ----- TemporaryState --------------------------------------------------------
// NOTE: still uses JQuery types; will be updated when tags.ts/group_list.ts are converted

interface AttrChange { object: JQuery; attribute: string; value: string | undefined }
interface ClassChange { object: JQuery; state: boolean; class: string }
interface HtmlChange { object: JQuery; html: string }

class TemporaryState {
    attrChanges: AttrChange[] = [];
    classChanges: ClassChange[] = [];
    htmlChanges: HtmlChange[] = [];

    changeAttribute(obj: JQuery, attr: string, tempVal: string): void {
        for (let i = 0; i < obj.length; i++) {
            this.attrChanges.push({ object: $(obj[i]), attribute: attr, value: $(obj[i]).attr(attr) });
        }
        obj.attr(attr, tempVal);
    }

    changeClass(obj: JQuery, st: boolean, tempclass: string): void {
        for (let i = 0; i < obj.length; i++) {
            if (!($(obj[i]).hasClass(tempclass) && st)) {
                this.classChanges.push({ object: $(obj[i]), state: !st, class: tempclass });
                if (st) $(obj[i]).addClass(tempclass);
                else $(obj[i]).removeClass(tempclass);
            }
        }
    }

    addClass(obj: JQuery, tempclass: string): void { this.changeClass(obj, true, tempclass); }
    removeClass(obj: JQuery, tempclass: string): void { this.changeClass(obj, false, tempclass); }

    changeHTML(obj: JQuery, temphtml: string): void {
        for (let i = 0; i < obj.length; i++) {
            this.htmlChanges.push({ object: $(obj[i]), html: $(obj[i]).html() });
        }
        obj.html(temphtml);
    }

    reverse(): void {
        this.attrChanges.forEach((change) => {
            if (change.value === undefined) change.object.removeAttr(change.attribute);
            else change.object.attr(change.attribute, change.value);
        });
        this.classChanges.forEach((change) => {
            if (change.state) change.object.addClass(change.class);
            else change.object.removeClass(change.class);
        });
        this.htmlChanges.forEach((change) => { change.object.html(change.html); });
        this.attrChanges = [];
        this.classChanges = [];
        this.htmlChanges = [];
    }
}

// ----- jConfirm option sets (kept until all callers are migrated) ------------

const jConfirm_alert_options = {
    icon: 'icon-ok', titleClass: 'jconfirmAlert', theme: 'modern',
    closeIcon: true, draggable: false, animation: 'zoom', boxWidth: '20%',
    useBootstrap: false, backgroundDismiss: true, animateFromElement: false, typeAnimated: false,
};

const jConfirm_confirm_options = {
    draggable: false, titleClass: 'jconfirmDeleteConfirm', theme: 'modern',
    animation: 'zoom', boxWidth: '40%', useBootstrap: false, type: 'red',
    animateFromElement: false, backgroundDismiss: true, typeAnimated: false,
};

const jConfirm_warning_options = {
    icon: 'icon-attention', draggable: false,
    titleClass: 'jconfirmWarning jconfirmAlert', theme: 'modern', type: 'orange',
    closeIcon: true, animation: 'zoom', boxWidth: '20%', useBootstrap: false,
    backgroundDismiss: true, animateFromElement: false, typeAnimated: false,
};

const jConfirm_confirm_with_content_options = {
    draggable: false, theme: 'modern', animation: 'zoom', boxWidth: '40%',
    useBootstrap: false, type: 'red', animateFromElement: false,
    backgroundDismiss: true, typeAnimated: false,
};

// ----- jconfirm follow-href plugin (kept for plugins_installated.ts) ---------

$.fn.pwg_jconfirm_follow_href = function ({
    alert_title = 'TITLE',
    alert_confirm = 'CONFIRM',
    alert_cancel = 'CANCEL',
    alert_content = '',
}: {
    alert_title?: string;
    alert_confirm?: string;
    alert_cancel?: string;
    alert_content?: string;
} = {}) {
    const button_href = $(this).attr('href');
    $(this).on('click', function () {
        const msg = alert_title + (alert_content ? '\n\n' + alert_content : '');
        if (window.confirm(msg)) {
            window.location.href = button_href!;
        }
        return false;
    });
};

// ----- expose globals --------------------------------------------------------

(window as any).applyFontCheckbox = applyFontCheckbox;
(window as any).array_delete = array_delete;
(window as any).str_repeat = str_repeat;
(window as any).getRandomInt = getRandomInt;
(window as any).sprintf = sprintf;
(window as any).TemporaryState = TemporaryState;
(window as any).jConfirm_alert_options = jConfirm_alert_options;
(window as any).jConfirm_confirm_options = jConfirm_confirm_options;
(window as any).jConfirm_warning_options = jConfirm_warning_options;
(window as any).jConfirm_confirm_with_content_options = jConfirm_confirm_with_content_options;

export {};
