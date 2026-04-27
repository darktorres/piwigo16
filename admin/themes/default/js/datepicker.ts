(function ($: JQueryStatic) {
    (jQuery as any).timepicker.log = jQuery.noop;

    const origGenerateMonthYearHeader = ($.datepicker as any)._generateMonthYearHeader;
    const origSelectMonthYear = ($.datepicker as any)._selectMonthYear;

    ($.datepicker as any)._generateMonthYearHeader = function (
        this: unknown, inst: unknown, drawMonth: unknown, drawYear: unknown,
        minDate: unknown, maxDate: unknown, secondary: unknown, monthNames: unknown, monthNamesShort: unknown
    ) {
        const html: string = origGenerateMonthYearHeader.call(this, inst, drawMonth, drawYear, minDate, maxDate, secondary, monthNames, monthNamesShort);
        const yearshtml = `<input type='number' class='ui-datepicker-year' data-handler='selectYear' data-event='change keyup' value='${drawYear}' style='width:4em;margin-left:2px;'>`;
        return html.replace(new RegExp("<select class='ui-datepicker-year'.*</select>", 'gm'), yearshtml);
    };

    ($.datepicker as any)._selectMonthYear = debounce(function (this: unknown, ...args: unknown[]) {
        const id = args[0] as string;
        const select = args[1] as HTMLInputElement;
        const period = args[2] as string;
        if (period === 'M') {
            origSelectMonthYear.call(this, id, select, period);
        } else {
            const target = $(id);
            const inst = ($.datepicker as any)._getInst(target[0]);
            const val = parseInt(select.value, 10);
            if (isNaN(val)) {
                inst['drawYear'] = '';
            } else {
                inst['selectedYear'] = inst['drawYear'] = val;
                ($.datepicker as any)._notifyChange(inst);
                ($.datepicker as any)._adjustDate(target);
                $('.ui-datepicker-year').trigger('focus');
            }
        }
    }, 500);

    (jQuery.fn as any).pwgDatepicker = function (settings: Record<string, unknown>) {
        const options = jQuery.extend(true, { showTimepicker: false, cancelButton: false }, settings || {});

        return this.each(function (this: HTMLElement) {
            const $this = jQuery(this);
            let originalValue = String($this.val() ?? '');
            let originalDate: Date | null;
            const $target = jQuery('[name="' + $this.data('datepicker') + '"]');
            const linked = !!$target.length;
            let $start: JQuery, $end: JQuery;

            if (linked) originalValue = String($target.val() ?? '');

            function set(date: Date | null | string, init: boolean) {
                if (date === '') date = null;
                ($this as any).datetimepicker('setDate', date);

                if ($this.data('datepicker-start') && $start) {
                    ($start as any).datetimepicker('option', 'maxDate', date);
                } else if ($this.data('datepicker-end') && $end && !init) {
                    ($end as any).datetimepicker('option', 'minDate', date);
                }
                if (!date && linked) $target.val('');
            }

            if (options.cancelButton) {
                options.beforeShow = options.onChangeMonthYear = function () {
                    setTimeout(function () {
                        const buttonPane = ($this as any).datepicker('widget').find('.ui-datepicker-buttonpane');
                        if (buttonPane.find('.pwg-datepicker-cancel').length === 0) {
                            $('<button type="button">' + options.cancelButton + '</button>')
                                .on('click', function () { set(originalDate, false); ($this as any).datepicker('hide').blur(); })
                                .addClass('pwg-datepicker-cancel ui-state-error ui-corner-all')
                                .appendTo(buttonPane);
                        }
                    }, 1);
                };
            }

            ($this as any).datetimepicker(jQuery.extend({
                dateFormat: linked ? 'DD d MM yy' : 'yy-mm-dd',
                timeFormat: 'HH:mm',
                separator: options.showTimepicker ? ' ' : '',
                altField: linked ? $target : null,
                altFormat: 'yy-mm-dd',
                altTimeFormat: options.showTimepicker ? 'HH:mm:ss' : '',
                autoSize: true,
                changeMonth: true,
                changeYear: true,
                altFieldTimeOnly: false,
                showSecond: false,
                alwaysSetTime: false,
            }, options));

            if ($this.data('datepicker-start')) {
                $start = jQuery('[data-datepicker="' + $this.data('datepicker-start') + '"]');
                ($this as any).datetimepicker('option', 'onClose', (date: Date) => { ($start as any).datetimepicker('option', 'maxDate', date); });
                ($this as any).datetimepicker('option', 'minDate', ($start as any).datetimepicker('getDate'));
            } else if ($this.data('datepicker-end')) {
                $end = jQuery('[data-datepicker="' + $this.data('datepicker-end') + '"]');
                ($this as any).datetimepicker('option', 'onClose', (date: Date) => { ($end as any).datetimepicker('option', 'minDate', date); });
            }

            if ($this.data('datepicker-unset')) {
                jQuery('#' + $this.data('datepicker-unset')).on('click', (e) => { e.preventDefault(); set(null, false); });
            }

            if (linked) {
                const splitted = originalValue.split(' ');
                if (splitted.length === 2 && options.showTimepicker) {
                    set((jQuery.datepicker as any).parseDateTime('yy-mm-dd', 'HH:mm:ss', originalValue), true);
                } else if (splitted[0].length === 10) {
                    set(jQuery.datepicker.parseDate('yy-mm-dd', splitted[0]), true);
                } else {
                    set(null, true);
                }
            }

            originalDate = ($this as any).datetimepicker('getDate') as Date;
            if (options.showTimepicker) {
                $this.attr('size', String(parseInt(String($this.attr('size') ?? '0')) + 6));
            }
        });
    };

    function debounce(func: (...args: unknown[]) => unknown, wait: number, immediate?: boolean) {
        let timeout: ReturnType<typeof setTimeout> | null;
        return function (this: unknown, ...args: unknown[]) {
            const later = () => {
                timeout = null;
                if (!immediate) func.apply(this, args);
            };
            const callNow = immediate && !timeout;
            if (timeout) clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            if (callNow) func.apply(this, args);
        };
    }
}(jQuery));
