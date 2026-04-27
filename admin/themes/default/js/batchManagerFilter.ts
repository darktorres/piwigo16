declare var selected_filter_cat_ids: (string | number)[];
declare var errorFilters: string;
declare var sliders: Record<string, { values: unknown[]; selected: { min: unknown; max: unknown }; text: string }>;
declare var str_select_album: string;
declare var str_select_tag: string;

function filter_enable(filter: string): void {
    $(`#${filter}`).show();
    $(`input[type=checkbox][name=${filter}_use]`).prop('checked', true);
    $(`#addFilter`).find(`a[data-value=${filter}]`).addClass('disabled');
    $('.noFilter').hide();
    $('.addFilter-button').removeClass('highlight');
}

function filter_disable(filter: string): void {
    $(`#${filter}`).hide();
    $(`input[name=${filter}_use]`).prop('checked', false);
    $(`#addFilter`).find(`a[data-value=${filter}]`).removeClass('disabled');
    if ($('#filterList li:visible').length === 0) {
        $('.noFilter').show();
        $('.addFilter-button').addClass('highlight');
    }
}

function select_album_filter({ album, newSelectedAlbum, getSelectedAlbum }: { album: any; newSelectedAlbum: () => void; getSelectedAlbum: () => (string | number)[] }): void {
    $('#selectedAlbumNameFilter').html(album.name);
    newSelectedAlbum();
    hide_filters_error(str_select_album);
    $('#filterCategoryValue').val(+getSelectedAlbum()[0]);
    $('#selectAlbumFilter').hide();
    $('#selectedAlbumFilterArea').fadeIn();
}

function show_filters_error(message: string): void {
    errorFilters = message;
    $(`#errorFilter`).html(`<p>${message}</p>`).fadeIn();
}

function hide_filters_error(message: string): void {
    if (message === errorFilters) $('#errorFilter').hide();
}

$(document).ready(function () {
    const ab_filter = new AlbumSelector({
        selectedCategoriesIds: selected_filter_cat_ids,
        selectAlbum: select_album_filter,
        adminMode: true,
    });

    $('#selectAlbumFilter, #selectedAlbumEditFilter').on('click', function () { ab_filter.open(); });

    $('.removeFilter').addClass('icon-cancel-circled').on('click', function () {
        const filter = $(this).parent('li').attr('id') ?? '';
        filter_disable(filter);
        return false;
    });

    $('#addFilter a').on('click', function () { filter_enable(String($(this).attr('data-value'))); });

    $('#removeFilters').on('click', function () {
        $('#filterList li').each(function () { filter_disable($(this).attr('id') ?? ''); });
        return false;
    });

    $('[data-slider=widths]').pwgDoubleSlider(sliders.widths as any);
    $('[data-slider=heights]').pwgDoubleSlider(sliders.heights as any);
    $('[data-slider=ratios]').pwgDoubleSlider(sliders.ratios as any);
    $('[data-slider=filesizes]').pwgDoubleSlider(sliders.filesizes as any);

    $(document).on('mouseup', function (e) {
        e.stopPropagation();
        if (!$(e.target).hasClass('addFilter-button')) {
            $('.addFilter-dropdown').slideUp();
        }
    });

    $('.filterBlock select[data-selectize="tags"]').on('change', function () {
        if ($(this).val()) hide_filters_error(str_select_tag);
    });

    $('#applyFilter').on('click', function (e) {
        if ($('#filter_tags').is(':visible')) {
            const tags = $('.filterBlock select[data-selectize="tags"]');
            if (!tags.val()) {
                e.preventDefault();
                show_filters_error(str_select_tag);
                $('#filter_tags .removeFilter').off('click.apply').on('click.apply', function () { hide_filters_error(str_select_tag); });
            }
        }
        if ($('#filter_category').is(':visible')) {
            const albums = ab_filter.get_selected_albums();
            if (albums.length === 0) {
                e.preventDefault();
                show_filters_error(str_select_album);
                $('#filter_category .removeFilter').off('click.apply').on('click.apply', function () { hide_filters_error(str_select_album); });
            }
        }
    });

    $('.help-popin-search').on('click', function () { $('#modalQuickSearch').fadeIn(); });
    $('#closeModalQuickSearch').on('click', function () { $('#modalQuickSearch').fadeOut(); });
});

export {};
