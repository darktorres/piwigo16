($.fn as any).pwgAddAlbum = function (this: JQuery, options: {
    filter?: (this: HTMLSelectElement, cats: any[]) => any[];
    afterSelect?: () => void;
} = {}) {
    const $popup = jQuery('#addAlbumForm');
    const $albumParent = $popup.find('[name="category_parent"]');
    const $button = jQuery(this);
    const $target = jQuery('[name="' + String($button.data('addAlbum')) + '"]');
    const cache = $target.data('cache') as any;

    if ($target[0] && !(($target[0] as any).selectize)) {
        jQuery.error('pwgAddAlbum: target must use selectize');
    }
    if (!cache) {
        jQuery.error('pwgAddAlbum: missing categories cache');
    }

    function init(): void {
        $popup.data('init', true);
        cache.selectize($albumParent, {
            default: 0,
            filter(this: HTMLSelectElement, categories: any[]) {
                categories.push({ id: 0, fullname: '------------', global_rank: 0 });
                if (options.filter) categories = options.filter.call(this, categories);
                return categories;
            },
        });

        $popup.find('form').on('submit', function (e) {
            e.preventDefault();
            const parent_id = $albumParent.val();
            const name = $popup.find('[name=category_name]').val();
            if (!name) { jQuery('#categoryNameError').css('visibility', 'visible'); return; }
            jQuery('#categoryNameError').css('visibility', 'hidden');

            jQuery.ajax({
                url: 'ws.php?format=json',
                type: 'POST',
                dataType: 'json',
                data: { method: 'pwg.categories.add', parent: parent_id, name },
                beforeSend() {
                    jQuery('#albumCreationLoading').css('display', 'inline-block');
                    jQuery('.albumCreationButton').hide();
                },
                success(data: any) {
                    jQuery('#albumCreationLoading').hide();
                    jQuery('.albumCreationButton').show();
                    ($button as any).colorbox.close();

                    const newAlbum: any = { id: data.result.id, name, fullname: name, global_rank: '0', dir: null, nb_images: 0, pos: 0 };
                    const parentSelectize = ($albumParent[0] as any).selectize;

                    if (parent_id !== 0 && parent_id !== '0') {
                        const parent = parentSelectize.options[String(parent_id)];
                        newAlbum.fullname = parent.fullname + ' / ' + newAlbum.fullname;
                        newAlbum.global_rank = parent.global_rank + '.1';
                        newAlbum.pos = parent.pos + 1;
                    }

                    const targetSelectize = ($target[0] as any).selectize;
                    targetSelectize.addOption(newAlbum);
                    targetSelectize.setValue(newAlbum.id);
                    parentSelectize.addOption(newAlbum);
                    if (options.afterSelect) options.afterSelect();
                },
                error(_xhr: unknown, _status: unknown, errorThrows: string) {
                    jQuery('#albumCreationLoading').hide();
                    alert(errorThrows);
                },
            });
        });
    }

    (this as any).colorbox({
        inline: true,
        href: '#addAlbumForm',
        width: 650,
        height: 'auto',
        onComplete() {
            if (!$popup.data('init')) init();
            jQuery('#categoryNameError').css('visibility', 'hidden');
            $popup.find('[name=category_name]').val('').trigger('focus');
            ($albumParent[0] as any).selectize.setValue($target.val() || 0);
        },
    });

    return this;
};

export {};
