(function () {
    function sbFunc(link: string, box: string): void {
        jQuery(link).on('click', function () {
            const elt = jQuery(box);
            elt.css('left', Math.min(jQuery(this).position().left, jQuery(window).width()! - elt.outerWidth(true)! - 5))
                .css('top', jQuery(this).position().top + jQuery(this).outerHeight(true)!)
                .toggle();
            return false;
        });
        jQuery(box).on('mouseleave click', function () {
            jQuery(this).hide();
        });
    }

    if (typeof SwitchBox !== 'undefined' && Array.isArray(SwitchBox)) {
        for (let i = 0; i < (SwitchBox as string[]).length; i += 2) {
            sbFunc((SwitchBox as string[])[i], (SwitchBox as string[])[i + 1]);
        }
    }

    SwitchBox = { push: sbFunc };
})();
