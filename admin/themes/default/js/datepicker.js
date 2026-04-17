(function () {
    /**
     * pwgDatepicker — thin Flatpickr wrapper, jQuery-free.
     *
     * Usage: pwgDatepicker(nodeOrNodeList, options)
     *   options.showTimepicker — boolean, default false
     *   options.cancelButton  — string label for a "cancel" button inside the picker
     *
     * HTML attributes on the <input type="text"> element:
     *   data-datepicker="<name>"          — name of sibling <input type="hidden"> that stores
     *                                       the machine-readable date (YYYY-MM-DD[ HH:mm:ss])
     *   data-datepicker-end="<name>"      — this is the START picker; name of the END picker
     *                                       (will have its minDate constrained)
     *   data-datepicker-start="<name>"    — this is the END picker; name of the START picker
     *                                       (will have its maxDate constrained)
     *   data-datepicker-unset="<id>"      — id of an element that clears the date when clicked
     */
    window.pwgDatepicker = function (target, options) {
        var opts = Object.assign({ showTimepicker: false, cancelButton: false }, options || {});

        var els;
        if (target instanceof NodeList) {
            els = Array.from(target);
        } else if (Array.isArray(target)) {
            els = target;
        } else {
            els = [target];
        }

        var instances = {};

        els.forEach(function (el) {
            var name = el.dataset.datepicker;
            var hiddenEl = name ? document.querySelector('[name="' + name + '"]') : null;
            var initialValue = hiddenEl ? hiddenEl.value.trim() : '';
            var originalDate = null;

            var fpConfig = {
                enableTime: !!opts.showTimepicker,
                dateFormat: opts.showTimepicker ? 'D, j M Y H:i' : 'D, j M Y',
                time_24hr: true,
                allowInput: false,
                onOpen: function (selectedDates) {
                    originalDate = selectedDates[0] ? new Date(selectedDates[0].getTime()) : null;
                },
                onChange: function (selectedDates) {
                    var d = selectedDates[0] || null;

                    if (hiddenEl) {
                        hiddenEl.value = d ? formatMachine(d, opts.showTimepicker) : '';
                    }

                    var endName = el.dataset.datepickerEnd;
                    if (endName && instances[endName]) {
                        instances[endName].set('minDate', d || null);
                    }

                    var startName = el.dataset.datepickerStart;
                    if (startName && instances[startName]) {
                        instances[startName].set('maxDate', d || null);
                    }
                },
            };

            if (opts.cancelButton) {
                fpConfig.onReady = function (selectedDates, dateStr, fp) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = opts.cancelButton;
                    btn.className = 'pwg-flatpickr-cancel';
                    btn.style.cssText = 'display:block;width:100%;margin-top:4px;padding:2px 8px;cursor:pointer;';
                    btn.addEventListener('click', function () {
                        fp.setDate(originalDate, true);
                        fp.close();
                    });
                    fp.calendarContainer.appendChild(btn);
                };
            }

            var fp = flatpickr(el, fpConfig);

            if (initialValue) {
                fp.setDate(initialValue, false, opts.showTimepicker ? 'Y-m-d H:i:S' : 'Y-m-d');
            }

            if (name) instances[name] = fp;

            var unsetId = el.dataset.datepickerUnset;
            if (unsetId) {
                var unsetBtn = document.getElementById(unsetId);
                if (unsetBtn) {
                    unsetBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        fp.clear();
                        if (hiddenEl) hiddenEl.value = '';
                    });
                }
            }
        });

        // Second pass: apply initial minDate constraints to end pickers.
        // (Start pickers are processed before end pickers in the DOM, so
        // the start instance is already in `instances` when we reach the end.)
        els.forEach(function (el) {
            var name = el.dataset.datepicker;
            var fp = name ? instances[name] : null;
            if (!fp) return;

            var startName = el.dataset.datepickerStart;
            if (startName && instances[startName]) {
                var startDate = instances[startName].selectedDates[0];
                if (startDate) fp.set('minDate', startDate);
            }
        });
    };

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }

    function formatMachine(d, withTime) {
        var date = d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
        if (!withTime) return date;
        return date + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    }
})();
