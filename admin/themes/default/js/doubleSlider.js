(function () {
    /**
     * pwgDoubleSlider — noUiSlider-backed range slider, jQuery-free.
     *
     * OPTIONS:
     *   values   {mixed[]}  — ordered array of allowed values
     *   selected {object}   — { min, max } initial selection
     *   text     {string}   — sprintf template shown in .slider-info
     */
    window.pwgDoubleSlider = function (containerEl, options) {
        var sliderEl = containerEl.querySelector(".slider-slider");

        function findClosest(array, value) {
            var closest = null;
            var index = -1;
            array.forEach(function (v, i) {
                if (closest === null || Math.abs(v - value) < Math.abs(closest - value)) {
                    closest = v;
                    index = i;
                }
            });
            return index;
        }

        var startLo = options.values.indexOf(options.selected.min);
        var startHi = options.values.indexOf(options.selected.max);
        if (startLo === -1) startLo = findClosest(options.values, options.selected.min);
        if (startHi === -1) startHi = findClosest(options.values, options.selected.max);

        noUiSlider.create(sliderEl, {
            start: [startLo, startHi],
            range: { min: 0, max: options.values.length - 1 },
            step: 1,
            connect: true,
        });

        function onChange(values) {
            var lo = Math.round(parseFloat(values[0]));
            var hi = Math.round(parseFloat(values[1]));
            var minEl = containerEl.querySelector("[data-input=min]");
            var maxEl = containerEl.querySelector("[data-input=max]");
            var infoEl = containerEl.querySelector(".slider-info");
            if (minEl) minEl.value = options.values[lo];
            if (maxEl) maxEl.value = options.values[hi];
            if (infoEl) infoEl.innerHTML = sprintf(options.text, options.values[lo], options.values[hi]);
        }

        sliderEl.noUiSlider.on("update", onChange);

        containerEl.querySelectorAll(".slider-choice").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var minIdx = options.values.indexOf(Number(btn.dataset.min));
                var maxIdx = options.values.indexOf(Number(btn.dataset.max));
                sliderEl.noUiSlider.set([minIdx, maxIdx]);
            });
        });

        return containerEl;
    };
})();
