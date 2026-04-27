(function ($: JQueryStatic) {

    interface DoubleSliderOptions {
        values: Array<number | string>;
        selected: { min: number | string; max: number | string };
        text: string;
    }

    ($.fn as any).pwgDoubleSlider = function (options: DoubleSliderOptions) {
        const that: JQuery = this;

        function onChange(_e: unknown, ui: { values: number[] }) {
            that.find('[data-input=min]').val(options.values[ui.values[0]] as string | number);
            that.find('[data-input=max]').val(options.values[ui.values[1]] as string | number);
            that.find('.slider-info').html(sprintf(
                options.text,
                options.values[ui.values[0]],
                options.values[ui.values[1]]
            ));
        }

        function findClosest(array: Array<number | string>, value: number | string): number {
            let closest: number | null = null, index = -1;
            array.forEach((v, i) => {
                const diff = Math.abs(Number(v) - Number(value));
                if (closest === null || diff < Math.abs(Number(closest) - Number(value))) {
                    closest = Number(v);
                    index = i;
                }
            });
            return index;
        }

        const values = [
            options.values.indexOf(options.selected.min),
            options.values.indexOf(options.selected.max),
        ];
        if (values[0] === -1) values[0] = findClosest(options.values, options.selected.min);
        if (values[1] === -1) values[1] = findClosest(options.values, options.selected.max);

        const slider = this.find('.slider-slider').slider({
            range: true,
            min: 0,
            max: options.values.length - 1,
            values: values,
            slide: onChange,
            change: onChange,
        });

        this.find('.slider-choice').on('click', (e) => {
            slider.slider('values', 0, options.values.indexOf($(e.currentTarget).data('min') as number | string));
            slider.slider('values', 1, options.values.indexOf($(e.currentTarget).data('max') as number | string));
        });

        return this;
    };

}(jQuery));
