// ----- font-checkbox plugin --------------------------------------------------

$.fn.fontCheckbox = function (this: JQuery): JQuery {
    this.find('input[type=checkbox]').each(function () {
        if (!$(this).is(':checked')) {
            $(this).prev().toggleClass('icon-check icon-check-empty');
        }
    });
    this.find('input[type=checkbox]').on('change', function () {
        $(this).prev().removeClass();
        if (!$(this).is(':checked')) $(this).prev().addClass('icon-check-empty');
        else $(this).prev().addClass('icon-check');
    });

    this.find('input[type=radio]').each(function () {
        if (!$(this).is(':checked')) {
            $(this).prev().toggleClass('icon-dot-circled icon-circle-empty');
        } else {
            $(this).closest('label').addClass('selected');
        }
    });
    this.find('input[type=radio]').on('change', function () {
        const name = $(this).attr('name');
        $(`.font-checkbox input[type=radio][name="${name}"]`).each(function () {
            $(this).prev().removeClass();
            $(this).closest('label').removeClass('selected');
            if (!$(this).is(':checked')) {
                $(this).prev().addClass('icon-circle-empty');
            } else {
                $(this).prev().addClass('icon-dot-circled');
                $(this).closest('label').addClass('selected');
            }
        });
    });
    return this;
};

$(() => { $('.font-checkbox').fontCheckbox(); });

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

$('.search-cancel').on('click', function () {
    $('.search-input').val('').trigger('input');
});

$('.search-input').on('input', function () {
    if ($('.search-input').val() === '') $('.search-cancel').hide();
    else $('.search-cancel').show();
});

// ----- TemporaryState --------------------------------------------------------

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

// ----- jConfirm option sets --------------------------------------------------

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

// ----- jconfirm follow-href plugin ------------------------------------------

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
    const options = alert_content === '' ? jConfirm_confirm_options : jConfirm_confirm_with_content_options;
    $(this).on('click', function () {
        $.confirm({
            content: alert_content,
            title: alert_title,
            buttons: {
                confirm: {
                    text: alert_confirm,
                    btnClass: 'btn-red',
                    action() { window.location.href = button_href!; },
                },
                cancel: { text: alert_cancel },
            },
            ...options,
        });
        return false;
    });
};

// ----- expose globals --------------------------------------------------------

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
