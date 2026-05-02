import TomSelect from 'tom-select';
import tippy from 'tippy.js';
import { getPageData } from './page-data';
import { pwgDoubleSlider } from './doubleSlider';

declare let sprintf: (fmt: string, ...args: any[]) => string;

interface SliderConfig {
    values: number[];
    selected: { min: number | string; max: number | string };
    text: string;
}

interface McsPageData {
    global_params: any;
    search_id: string;
    fullname_of_cat: Record<string, string>;
    show_filter_ratings: boolean;
    sliders: {
        filesizes?: SliderConfig;
        heights?: SliderConfig;
        widths?: SliderConfig;
    };
    str_word_widget_label: string;
    str_tags_widget_label: string;
    str_album_widget_label: string;
    str_author_widget_label: string;
    str_added_by_widget_label: string;
    str_filetypes_widget_label: string;
    str_ratio_widget_label: string;
    str_rating_widget_label: string;
    str_no_rating: string;
    str_between_rating: string;
    str_filesize_widget_label: string;
    str_width_widget_label: string;
    str_height_widget_label: string;
    str_expert_widget_label: string;
    str_search_in_ab: string;
    str_empty_search_top_alt: string;
    str_empty_search_bot_alt: string;
    str_ratios_label: Record<string, string>;
}

const {
    global_params,
    search_id,
    fullname_of_cat,
    show_filter_ratings,
    sliders,
    str_word_widget_label,
    str_tags_widget_label,
    str_album_widget_label,
    str_author_widget_label,
    str_added_by_widget_label,
    str_filetypes_widget_label,
    str_ratio_widget_label,
    str_rating_widget_label,
    str_no_rating,
    str_between_rating,
    str_filesize_widget_label,
    str_width_widget_label,
    str_height_widget_label,
    str_expert_widget_label,
    str_search_in_ab,
    str_empty_search_top_alt,
    str_empty_search_bot_alt,
    str_ratios_label,
} = getPageData<McsPageData>();

const prefix_icon = 'gallery-icon-';

// Script-level mutable globals (populated inside DOMContentLoaded)
let PS_params: Record<string, any> = {};
let empty_filters_list: any[] = [];
let filters_to_remove: any[] = [];

// Per-filter string accumulators
let word_search_str: string;
let word_search_words: string[];
let word_search_fields: any;
let word_search_mode: string;
let tag_search_str: string;
let date_posted_str: string;
let date_created_str: string;
let album_widget_value: string;
let author_search_str: string;
let added_by_names: string[];
let filetypes_search_str: string;
let ratios_search_str: string;
let ratings_search_str: string;
let expert_search_str: string;
let filesize_min: number;
let filesize_max: number;
let height_min: string;
let height_max: string;
let width_min: string;
let width_max: string;

// Tom Select instances
let tagSelectTs: TomSelect | null = null;
let authorSelectTs: TomSelect | null = null;

// Helper: toggle element display between '' and 'none'
function toggleDisplay(el: HTMLElement): void {
    el.style.display = el.style.display === 'none' ? '' : 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    document
        .querySelectorAll<HTMLElement>('.linkedAlbumPopInContainer .ClosePopIn')
        .forEach((el) => {
            el.classList.add(prefix_icon + 'cancel');
        });
    document
        .querySelectorAll<HTMLElement>('.linkedAlbumPopInContainer .searching')
        .forEach((el) => {
            el.classList.add(prefix_icon + 'spin6');
            el.style.display = 'none';
        });
    document.querySelectorAll<HTMLElement>('.AddIconContainer').forEach((el) => {
        el.style.display = 'none';
    });
    document.querySelectorAll<HTMLElement>('.filter-validate').forEach((el) => {
        el.addEventListener('click', () => {
            const loading = el.querySelector<HTMLElement>('.loading');
            if (loading) loading.style.display = 'block';
            const validateText = el.querySelector<HTMLElement>('.validate-text');
            if (validateText) validateText.style.display = 'none';
        });
    });

    // If we open another filter, hide all other dropdowns except the one just opened
    document.querySelectorAll<HTMLElement>('div.filter').forEach((filterEl) => {
        filterEl.addEventListener('click', () => {
            // siblings: other div.filter elements
            document.querySelectorAll<HTMLElement>('div.filter').forEach((sibling) => {
                if (sibling !== filterEl) {
                    sibling.classList.remove('show-filter-dropdown');
                    sibling.querySelectorAll<HTMLElement>('div.filter-form').forEach((ff) => {
                        ff.style.display = 'none';
                    });
                }
            });
        });
    });

    // If we open the choose filters modal hide all filter forms if any open
    document.querySelectorAll<HTMLElement>('div.filter-manager').forEach((el) => {
        el.addEventListener('click', () => {
            document.querySelectorAll<HTMLElement>('div.filter div.filter-form').forEach((ff) => {
                ff.style.display = 'none';
            });
        });
    });

    global_params.search_id = search_id;

    if (!global_params.fields) {
        global_params.fields = {};
    }

    // Declare params sent to pwg.images.filteredSearch.update
    // PS for performSearch()
    PS_params = {};
    PS_params.search_id = search_id;
    empty_filters_list = [];

    filters_to_remove = [];

    // Setup word filter
    if (global_params.fields.allwords) {
        document.querySelectorAll<HTMLElement>('.filter-word').forEach((el) => {
            el.style.display = 'flex';
        });
        document
            .querySelectorAll<HTMLInputElement>('.filter-manager-controller.word')
            .forEach((el) => {
                el.checked = true;
            });

        word_search_str = '';
        word_search_words =
            global_params.fields.allwords.words != null ? global_params.fields.allwords.words : [];
        word_search_words.forEach((word: string) => {
            word_search_str += word + ' ';
        });
        const wordSearchInput = document.querySelector<HTMLInputElement>('#word-search');
        if (wordSearchInput) wordSearchInput.value = word_search_str.slice(0, -1);

        if (global_params.fields.allwords.words && global_params.fields.allwords.words.length > 0) {
            document
                .querySelectorAll<HTMLElement>('.filter-word')
                .forEach((el) => el.classList.add('filter-filled'));
            document.querySelectorAll<HTMLElement>('.filter-word .search-words').forEach((el) => {
                el.innerHTML = word_search_str.slice(0, -1);
            });
        } else {
            document.querySelectorAll<HTMLElement>('.filter-word .search-words').forEach((el) => {
                el.innerHTML = str_word_widget_label;
            });
        }

        word_search_fields = global_params.fields.allwords.fields;
        Object.keys(word_search_fields).forEach((field_key: string) => {
            const inp = document.querySelector<HTMLInputElement>(
                '#' + word_search_fields[field_key]
            );
            if (inp) inp.checked = true;
        });

        word_search_mode = global_params.fields.allwords.mode;
        document
            .querySelectorAll<HTMLInputElement>(
                '.word-search-options input[value="' + word_search_mode + '"]'
            )
            .forEach((el) => {
                el.checked = true;
            });

        if (global_params.fields.search_in_tags) {
            const tagsInp = document.querySelector<HTMLInputElement>('#tags');
            if (tagsInp) tagsInp.checked = true;
        }

        document
            .querySelectorAll<HTMLElement>('.filter-word .filter-actions .clear')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    const ws = document.querySelector<HTMLInputElement>(
                        '.filter-word #word-search'
                    );
                    if (ws) ws.value = '';
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-word .search-params input')
                        .forEach((inp) => {
                            inp.checked = true;
                        });
                    document
                        .querySelectorAll<HTMLInputElement>(
                            ".filter-word .word-search-options input[value='AND']"
                        )
                        .forEach((inp) => {
                            inp.checked = true;
                        });
                });
            });

        PS_params.allwords = word_search_str.slice(0, -1);
        PS_params.allwords_fields = word_search_fields;
        PS_params.allwords_mode = word_search_mode;

        empty_filters_list.push(PS_params.allwords);
    }

    // Hide filter spinner
    document.querySelectorAll<HTMLElement>('.filter-spinner').forEach((el) => {
        el.style.display = 'none';
    });

    // Setup tag filter
    const tagSearchEl = document.querySelector<HTMLSelectElement>('#tag-search');
    if (tagSearchEl) {
        tagSelectTs = new TomSelect(tagSearchEl, {
            plugins: ['remove_button'],
            maxOptions: tagSearchEl.querySelectorAll('option').length,
            items: global_params.fields.tags ? global_params.fields.tags.words : null,
        });
        (tagSearchEl as any)._ts = tagSelectTs;
    }

    if (global_params.fields.tags) {
        document.querySelectorAll<HTMLElement>('.filter-tag').forEach((el) => {
            el.style.display = 'flex';
        });
        document
            .querySelectorAll<HTMLInputElement>('.filter-manager-controller.tags')
            .forEach((el) => {
                el.checked = true;
            });
        document
            .querySelectorAll<HTMLInputElement>(
                '.filter-tag-form .search-params input[value="' +
                    global_params.fields.tags.mode +
                    '"]'
            )
            .forEach((el) => {
                el.checked = true;
            });

        tag_search_str = '';
        if (tagSelectTs) {
            (tagSelectTs.getValue() as string[]).forEach((id: string) => {
                const itemText =
                    tagSelectTs!
                        .getItem(id)
                        ?.textContent?.replace(/\(\d+ \w+\)×/, '')
                        .trim() ?? '';
                tag_search_str += itemText + ', ';
            });
        }
        if (global_params.fields.tags.words && global_params.fields.tags.words.length > 0) {
            document
                .querySelectorAll<HTMLElement>('.filter-tag')
                .forEach((el) => el.classList.add('filter-filled'));
            document
                .querySelectorAll<HTMLElement>('.filter.filter-tag .search-words')
                .forEach((el) => {
                    el.textContent = tag_search_str.slice(0, -2);
                });
        } else {
            document
                .querySelectorAll<HTMLElement>('.filter.filter-tag .search-words')
                .forEach((el) => {
                    el.textContent = str_tags_widget_label;
                });
        }

        document
            .querySelectorAll<HTMLElement>('.filter-tag .filter-actions .clear')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    if (tagSelectTs) tagSelectTs.clear();
                    document
                        .querySelectorAll<HTMLInputElement>(
                            ".filter-tag .search-params input[value='AND']"
                        )
                        .forEach((inp) => {
                            inp.checked = true;
                        });
                });
            });

        PS_params.tags =
            global_params.fields.tags.words.length > 0 ? global_params.fields.tags.words : '';
        PS_params.tags_mode = global_params.fields.tags.mode;

        empty_filters_list.push(PS_params.tags);
    }

    // Setup Date post filter
    if (global_params.fields.date_posted) {
        document.querySelectorAll<HTMLElement>('.filter-date_posted').forEach((el) => {
            el.style.display = 'flex';
        });
        document
            .querySelectorAll<HTMLInputElement>('.filter-manager-controller.date_posted')
            .forEach((el) => {
                el.checked = true;
            });

        if (
            global_params.fields.date_posted.preset != null &&
            global_params.fields.date_posted.preset != ''
        ) {
            // If filter is used and not empty check preset date option
            const presetInp = document.querySelector<HTMLInputElement>(
                '#date_posted-' + global_params.fields.date_posted.preset
            );
            if (presetInp) presetInp.checked = true;
            const datePeriodEl = document.querySelector<HTMLElement>(
                '.date_posted-option label#' +
                    global_params.fields.date_posted.preset +
                    ' .date-period'
            );
            date_posted_str = datePeriodEl ? (datePeriodEl.textContent ?? '') : '';

            // if option is custom check custom dates
            if (
                'custom' == global_params.fields.date_posted.preset &&
                global_params.fields.date_posted.custom != null
            ) {
                date_posted_str = '';
                const customArray: string[] = global_params.fields.date_posted.custom;

                customArray.forEach((item: string, index: number) => {
                    const customValue = item.substring(1, item.length);

                    const customInp = document.querySelector<HTMLInputElement>(
                        '#date_posted_' + customValue
                    );
                    if (customInp) {
                        customInp.checked = true;
                        customInp.classList.add('selected');
                        // show the checked-icon in siblings label
                        customInp.parentElement
                            ?.querySelectorAll<HTMLElement>('label .checked-icon')
                            .forEach((icon) => {
                                icon.style.display = '';
                            });
                    }

                    const periodEl = document.querySelector<HTMLElement>(
                        '.date_posted-option label#' + customValue + ' .date-period'
                    );
                    date_posted_str += periodEl ? (periodEl.textContent ?? '') : '';

                    if (customArray.length > 1 && index != customArray.length - 1) {
                        date_posted_str += ', ';
                    }
                });

                document.querySelectorAll<HTMLElement>('.date_posted-option.year').forEach((el) => {
                    updateDateFilters(`.custom_posted_date #${el.id}`);
                });
            }

            // change badge label if filter not empty
            document
                .querySelectorAll<HTMLElement>('.filter-date_posted')
                .forEach((el) => el.classList.add('filter-filled'));
            document
                .querySelectorAll<HTMLElement>('.filter.filter-date_posted .search-words')
                .forEach((el) => {
                    el.textContent = date_posted_str;
                });
        }

        document
            .querySelectorAll<HTMLElement>('.filter-date_posted .filter-actions .clear')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    updateFilters('date_posted', 'add');
                    document
                        .querySelectorAll<HTMLInputElement>('.date_posted-option input')
                        .forEach((inp) => {
                            inp.checked = false;
                            inp.dispatchEvent(new Event('change'));
                        });
                });
            });

        // Disable possibility for user to select custom option, it gets selected programmatically later on
        const datePostedCustom = document.querySelector<HTMLInputElement>('#date_posted_custom');
        if (datePostedCustom) datePostedCustom.setAttribute('disabled', 'disabled');

        // Handle toggle between preset and custom options
        document.querySelectorAll<HTMLElement>('.custom_posted_date_toggle').forEach((el) => {
            el.addEventListener('click', () => {
                document
                    .querySelectorAll<HTMLElement>('.custom_posted_date')
                    .forEach((d) => toggleDisplay(d));
                document
                    .querySelectorAll<HTMLElement>('.preset_posted_date')
                    .forEach((d) => toggleDisplay(d));
            });
        });

        // Handle accordion features in custom options
        document
            .querySelectorAll<HTMLElement>('.custom_posted_date .accordion-toggle')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    const clickedOption = el.parentElement;
                    if (clickedOption) {
                        clickedOption.classList.toggle('show-child');
                        if ('year' == el.dataset['type']) {
                            clickedOption.parentElement
                                ?.querySelectorAll<HTMLElement>('.date_posted-option.month')
                                .forEach((m) => toggleDisplay(m));
                        } else if ('month' == el.dataset['type']) {
                            clickedOption.parentElement
                                ?.querySelectorAll<HTMLElement>('.date_posted-option.day')
                                .forEach((d) => toggleDisplay(d));
                        }
                    }
                });
            });

        // On custom date input select
        document
            .querySelectorAll<HTMLInputElement>('.custom_posted_date .date_posted-option input')
            .forEach((inp) => {
                inp.addEventListener('change', () => {
                    const currentYear = inp.dataset['year'];
                    const selector = `.custom_posted_date #container_${currentYear}`;
                    updateDateFilters(selector);

                    // Used to select custom in preset list if dates are selected
                    if (document.querySelectorAll('.custom_posted_date input:checked').length > 0) {
                        const customChk =
                            document.querySelector<HTMLInputElement>('#date_posted-custom');
                        if (customChk) customChk.checked = true;
                        document
                            .querySelectorAll<HTMLInputElement>('.preset_posted_date input')
                            .forEach((pi) => {
                                pi.setAttribute('disabled', 'disabled');
                            });
                    } else {
                        const customChk =
                            document.querySelector<HTMLInputElement>('#date_posted-custom');
                        if (customChk) customChk.checked = false;
                        document
                            .querySelectorAll<HTMLInputElement>('.preset_posted_date input')
                            .forEach((pi) => {
                                pi.removeAttribute('disabled');
                            });
                    }
                });
            });

        // Used to select custom in preset list if dates are selected
        if (document.querySelectorAll('.custom_posted_date input:checked').length > 0) {
            const customChk = document.querySelector<HTMLInputElement>('#date_posted-custom');
            if (customChk) customChk.checked = true;
            document
                .querySelectorAll<HTMLInputElement>('.preset_posted_date input')
                .forEach((pi) => {
                    pi.setAttribute('disabled', 'disabled');
                });
        } else {
            const customChk = document.querySelector<HTMLInputElement>('#date_posted-custom');
            if (customChk) customChk.checked = false;
            document
                .querySelectorAll<HTMLInputElement>('.preset_posted_date input')
                .forEach((pi) => {
                    pi.removeAttribute('disabled');
                });
        }

        PS_params.date_posted_preset =
            global_params.fields.date_posted.preset != ''
                ? global_params.fields.date_posted.preset
                : '';
        PS_params.date_posted_custom =
            global_params.fields.date_posted.custom != ''
                ? global_params.fields.date_posted.custom
                : '';

        empty_filters_list.push(PS_params.date_posted_preset);
        empty_filters_list.push(PS_params.date_posted_custom);
    }

    // Setup Date creation filter

    if (global_params.fields.date_created) {
        document.querySelectorAll<HTMLElement>('.filter-date_created').forEach((el) => {
            el.style.display = 'flex';
        });
        document
            .querySelectorAll<HTMLInputElement>('.filter-manager-controller.date_created')
            .forEach((el) => {
                el.checked = true;
            });

        if (
            global_params.fields.date_created.preset != null &&
            global_params.fields.date_created.preset != ''
        ) {
            // If filter is used and not empty check preset date option
            const presetInp = document.querySelector<HTMLInputElement>(
                '#date_created-' + global_params.fields.date_created.preset
            );
            if (presetInp) presetInp.checked = true;
            const datePeriodEl = document.querySelector<HTMLElement>(
                '.date_created-option label#' +
                    global_params.fields.date_created.preset +
                    ' .date-period'
            );
            date_created_str = datePeriodEl ? (datePeriodEl.textContent ?? '') : '';

            // if option is custom check custom dates
            if (
                'custom' == global_params.fields.date_created.preset &&
                global_params.fields.date_created.custom != null
            ) {
                date_created_str = '';
                const customArray: string[] = global_params.fields.date_created.custom;

                customArray.forEach((item: string, index: number) => {
                    const customValue = item.substring(1, item.length);

                    const customInp = document.querySelector<HTMLInputElement>(
                        '#date_created_' + customValue
                    );
                    if (customInp) {
                        customInp.checked = true;
                        customInp.classList.add('selected');
                        customInp.parentElement
                            ?.querySelectorAll<HTMLElement>('label .checked-icon')
                            .forEach((icon) => {
                                icon.style.display = '';
                            });
                    }

                    const periodEl = document.querySelector<HTMLElement>(
                        '.date_created-option label#' + customValue + ' .date-period'
                    );
                    date_created_str += periodEl ? (periodEl.textContent ?? '') : '';

                    if (customArray.length > 1 && index != customArray.length - 1) {
                        date_created_str += ', ';
                    }
                });

                document
                    .querySelectorAll<HTMLElement>('.date_created-option.year')
                    .forEach((el) => {
                        updateDateFilters(`.custom_created_date #${el.id}`);
                    });
            }

            // change badge label if filter not empty
            document
                .querySelectorAll<HTMLElement>('.filter-date_created')
                .forEach((el) => el.classList.add('filter-filled'));
            document
                .querySelectorAll<HTMLElement>('.filter.filter-date_created .search-words')
                .forEach((el) => {
                    el.textContent = date_created_str;
                });
        }

        document
            .querySelectorAll<HTMLElement>('.filter-date_created .filter-actions .clear')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    updateFilters('date_created', 'add');
                    document
                        .querySelectorAll<HTMLInputElement>('.date_created-option input')
                        .forEach((inp) => {
                            inp.checked = false;
                            inp.dispatchEvent(new Event('change'));
                        });
                });
            });

        // Disable possibility for user to select custom option, it gets selected programmatically later on
        const dateCreatedCustom = document.querySelector<HTMLInputElement>('#date_created_custom');
        if (dateCreatedCustom) dateCreatedCustom.setAttribute('disabled', 'disabled');

        // Handle toggle between preset and custom options
        document.querySelectorAll<HTMLElement>('.custom_created_date_toggle').forEach((el) => {
            el.addEventListener('click', () => {
                document
                    .querySelectorAll<HTMLElement>('.custom_created_date')
                    .forEach((d) => toggleDisplay(d));
                document
                    .querySelectorAll<HTMLElement>('.preset_created_date')
                    .forEach((d) => toggleDisplay(d));
            });
        });

        // Handle accordion features in custom options
        document
            .querySelectorAll<HTMLElement>('.custom_created_date .accordion-toggle')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    const clickedOption = el.parentElement;
                    if (clickedOption) {
                        clickedOption.classList.toggle('show-child');
                        if ('year' == el.dataset['type']) {
                            clickedOption.parentElement
                                ?.querySelectorAll<HTMLElement>('.date_created-option.month')
                                .forEach((m) => toggleDisplay(m));
                        } else if ('month' == el.dataset['type']) {
                            clickedOption.parentElement
                                ?.querySelectorAll<HTMLElement>('.date_created-option.day')
                                .forEach((d) => toggleDisplay(d));
                        }
                    }
                });
            });

        // On custom date input select
        document
            .querySelectorAll<HTMLInputElement>('.custom_created_date .date_created-option input')
            .forEach((inp) => {
                inp.addEventListener('change', () => {
                    const currentYear = inp.dataset['year'];
                    const selector = `.custom_created_date #container_${currentYear}`;
                    updateDateFilters(selector);

                    // Used to select custom in preset list if dates are selected
                    if (
                        document.querySelectorAll('.custom_created_date input:checked').length > 0
                    ) {
                        const customChk =
                            document.querySelector<HTMLInputElement>('#date_created-custom');
                        if (customChk) customChk.checked = true;
                        document
                            .querySelectorAll<HTMLInputElement>('.preset_created_date input')
                            .forEach((pi) => {
                                pi.setAttribute('disabled', 'disabled');
                            });
                    } else {
                        const customChk =
                            document.querySelector<HTMLInputElement>('#date_created-custom');
                        if (customChk) customChk.checked = false;
                        document
                            .querySelectorAll<HTMLInputElement>('.preset_created_date input')
                            .forEach((pi) => {
                                pi.removeAttribute('disabled');
                            });
                    }
                });
            });

        // Used to select custom in preset list if dates are selected
        if (document.querySelectorAll('.custom_created_date input:checked').length > 0) {
            const customChk = document.querySelector<HTMLInputElement>('#date_created-custom');
            if (customChk) customChk.checked = true;
            document
                .querySelectorAll<HTMLInputElement>('.preset_created_date input')
                .forEach((pi) => {
                    pi.setAttribute('disabled', 'disabled');
                });
        } else {
            const customChk = document.querySelector<HTMLInputElement>('#date_created-custom');
            if (customChk) customChk.checked = false;
            document
                .querySelectorAll<HTMLInputElement>('.preset_created_date input')
                .forEach((pi) => {
                    pi.removeAttribute('disabled');
                });
        }

        PS_params.date_created_preset =
            global_params.fields.date_created.preset != ''
                ? global_params.fields.date_created.preset
                : '';
        PS_params.date_created_custom =
            global_params.fields.date_created.custom != ''
                ? global_params.fields.date_created.custom
                : '';

        empty_filters_list.push(PS_params.date_created_preset);
        empty_filters_list.push(PS_params.date_created_custom);
    }

    // Setup album filter
    if (global_params.fields.cat) {
        document.querySelectorAll<HTMLElement>('.filter-album').forEach((el) => {
            el.style.display = 'flex';
        });
        document
            .querySelectorAll<HTMLInputElement>('.filter-manager-controller.album')
            .forEach((el) => {
                el.checked = true;
            });

        album_widget_value = '';
        global_params.fields.cat.words.forEach((cat_id: string) => {
            display_related_category(cat_id, fullname_of_cat[cat_id]);
            album_widget_value += fullname_of_cat[cat_id] + ', ';
        });

        if (global_params.fields.cat.words && global_params.fields.cat.words.length > 0) {
            document
                .querySelectorAll<HTMLElement>('.filter-album')
                .forEach((el) => el.classList.add('filter-filled'));
            document.querySelectorAll<HTMLElement>('.filter-album .search-words').forEach((el) => {
                el.innerHTML = album_widget_value.slice(0, -2);
            });
        } else {
            document.querySelectorAll<HTMLElement>('.filter-album .search-words').forEach((el) => {
                el.innerHTML = str_album_widget_label;
            });
        }

        if (global_params.fields.cat.sub_inc) {
            const searchSubCats = document.querySelector<HTMLInputElement>('#search-sub-cats');
            if (searchSubCats) searchSubCats.checked = true;
        }

        document
            .querySelectorAll<HTMLElement>('.filter-album .filter-actions .clear')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    const selCatsCont = document.querySelector<HTMLElement>(
                        '.selected-categories-container'
                    );
                    if (selCatsCont) selCatsCont.innerHTML = '';
                    const searchSubCatsInp =
                        document.querySelector<HTMLInputElement>('#search-sub-cats');
                    if (searchSubCatsInp) searchSubCatsInp.checked = false;
                });
            });

        PS_params.categories =
            global_params.fields.cat.words.length > 0 ? global_params.fields.cat.words : '';
        PS_params.categories_withsubs = global_params.fields.cat.sub_inc;

        empty_filters_list.push(PS_params.categories);
    }

    // Setup author filter
    const authorsEl = document.querySelector<HTMLSelectElement>('#authors');
    if (authorsEl) {
        authorSelectTs = new TomSelect(authorsEl, {
            plugins: ['remove_button'],
            maxOptions: authorsEl.querySelectorAll('option').length,
            items: global_params.fields.author ? global_params.fields.author.words : null,
        });
        (authorsEl as any)._ts = authorSelectTs;

        if (global_params.fields.author) {
            document.querySelectorAll<HTMLElement>('.filter-authors').forEach((el) => {
                el.style.display = 'flex';
            });
            document
                .querySelectorAll<HTMLInputElement>('.filter-manager-controller.author')
                .forEach((el) => {
                    el.checked = true;
                });

            author_search_str = '';
            (authorSelectTs.getValue() as string[]).forEach((id: string) => {
                const itemText =
                    authorSelectTs!
                        .getItem(id)
                        ?.textContent?.replace(/\(\d+ \w+\)×/, '')
                        .trim() ?? '';
                author_search_str += itemText + ', ';
            });

            if (global_params.fields.author.words && global_params.fields.author.words.length > 0) {
                document
                    .querySelectorAll<HTMLElement>('.filter-authors')
                    .forEach((el) => el.classList.add('filter-filled'));
                document
                    .querySelectorAll<HTMLElement>('.filter.filter-authors .search-words')
                    .forEach((el) => {
                        el.textContent = author_search_str.slice(0, -2);
                    });
            } else {
                document
                    .querySelectorAll<HTMLElement>('.filter.filter-authors .search-words')
                    .forEach((el) => {
                        el.textContent = str_author_widget_label;
                    });
            }

            document
                .querySelectorAll<HTMLElement>('.filter-authors .filter-actions .clear')
                .forEach((el) => {
                    el.addEventListener('click', () => {
                        if (authorSelectTs) authorSelectTs.clear();
                    });
                });

            PS_params.authors =
                global_params.fields.author.words.length > 0
                    ? global_params.fields.author.words
                    : '';

            empty_filters_list.push(PS_params.authors);
        }
    }

    // Setup added_by filter
    if (global_params.fields.added_by) {
        document.querySelectorAll<HTMLElement>('.filter-added_by').forEach((el) => {
            el.style.display = 'flex';
        });
        document
            .querySelectorAll<HTMLInputElement>('.filter-manager-controller.added_by')
            .forEach((el) => {
                el.checked = true;
            });

        if (global_params.fields.added_by && global_params.fields.added_by.length > 0) {
            document
                .querySelectorAll<HTMLElement>('.filter-added_by')
                .forEach((el) => el.classList.add('filter-filled'));

            added_by_names = [];

            document.querySelectorAll<HTMLElement>('.added_by-option').forEach((optionEl) => {
                const input = optionEl.querySelector<HTMLInputElement>('input');
                if (!input) return;
                const added_by_id = parseInt(input.getAttribute('name') ?? '');

                if (global_params.fields.added_by.indexOf(added_by_id) >= 0) {
                    input.checked = true;
                    const nameEl = optionEl.querySelector<HTMLElement>('.added_by-name');
                    if (nameEl) added_by_names.push(nameEl.textContent ?? '');
                }
            });

            document
                .querySelectorAll<HTMLElement>('.filter.filter-added_by .search-words')
                .forEach((el) => {
                    el.textContent = added_by_names.join(', ');
                });
        } else {
            document
                .querySelectorAll<HTMLElement>('.filter.filter-added_by .search-words')
                .forEach((el) => {
                    el.textContent = str_added_by_widget_label;
                });
        }

        document
            .querySelectorAll<HTMLElement>('.filter-added_by .filter-actions .clear')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    document
                        .querySelectorAll<HTMLInputElement>(
                            '.filter-added_by .added_by-option input'
                        )
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                });
            });

        PS_params.added_by =
            global_params.fields.added_by.length > 0 ? global_params.fields.added_by : '';

        empty_filters_list.push(PS_params.added_by);
    }

    // Setup filetypes filter
    if (global_params.fields.filetypes) {
        document.querySelectorAll<HTMLElement>('.filter-filetypes').forEach((el) => {
            el.style.display = 'flex';
        });
        document
            .querySelectorAll<HTMLInputElement>('.filter-manager-controller.filetypes')
            .forEach((el) => {
                el.checked = true;
            });

        filetypes_search_str = '';
        global_params.fields.filetypes.forEach((ft: string) => {
            filetypes_search_str += ft + ', ';
        });

        if (global_params.fields.filetypes && global_params.fields.filetypes.length > 0) {
            document
                .querySelectorAll<HTMLElement>('.filter-filetypes')
                .forEach((el) => el.classList.add('filter-filled'));
            document
                .querySelectorAll<HTMLElement>('.filter.filter-filetypes .search-words')
                .forEach((el) => {
                    el.textContent = filetypes_search_str.toUpperCase().slice(0, -2);
                });

            document
                .querySelectorAll<HTMLInputElement>('.filetypes-option input')
                .forEach((inp) => {
                    if (global_params.fields.filetypes.includes(inp.getAttribute('name'))) {
                        inp.checked = true;
                    }
                });
        } else {
            document
                .querySelectorAll<HTMLElement>('.filter.filter-filetypes .search-words')
                .forEach((el) => {
                    el.textContent = str_filetypes_widget_label;
                });
        }

        document
            .querySelectorAll<HTMLElement>('.filter-filetypes .filter-actions .clear')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    document
                        .querySelectorAll<HTMLInputElement>(
                            '.filter-filetypes .filetypes-option input'
                        )
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                });
            });

        PS_params.filetypes =
            global_params.fields.filetypes.length > 0 ? global_params.fields.filetypes : '';

        empty_filters_list.push(PS_params.filetypes);
    }

    // Setup Ratio filter
    if (global_params.fields.ratios) {
        document.querySelectorAll<HTMLElement>('.filter-ratios').forEach((el) => {
            el.style.display = 'flex';
        });
        document
            .querySelectorAll<HTMLInputElement>('.filter-manager-controller.ratios')
            .forEach((el) => {
                el.checked = true;
            });

        ratios_search_str = '';
        global_params.fields.ratios.forEach((ft: string) => {
            ratios_search_str += str_ratios_label[ft] + ', ';
        });

        if (global_params.fields.ratios && global_params.fields.ratios.length > 0) {
            document
                .querySelectorAll<HTMLElement>('.filter-ratios')
                .forEach((el) => el.classList.add('filter-filled'));
            document
                .querySelectorAll<HTMLElement>('.filter.filter-ratios .search-words')
                .forEach((el) => {
                    el.textContent = ratios_search_str.slice(0, -2);
                });

            document.querySelectorAll<HTMLInputElement>('.ratios-option input').forEach((inp) => {
                if (global_params.fields.ratios.includes(inp.getAttribute('name'))) {
                    inp.checked = true;
                }
            });
        } else {
            document
                .querySelectorAll<HTMLElement>('.filter.filter-ratios .search-words')
                .forEach((el) => {
                    el.textContent = str_ratio_widget_label;
                });
        }

        document
            .querySelectorAll<HTMLElement>('.filter-ratios .filter-actions .clear')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-ratios .ratios-option input')
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                });
            });

        PS_params.ratios =
            global_params.fields.ratios.length > 0 ? global_params.fields.ratios : '';

        empty_filters_list.push(PS_params.ratios);
    }

    // Setup rating filter
    if (global_params.fields.ratings && show_filter_ratings) {
        document.querySelectorAll<HTMLElement>('.filter-ratings').forEach((el) => {
            el.style.display = 'flex';
        });
        document
            .querySelectorAll<HTMLInputElement>('.filter-manager-controller.ratings')
            .forEach((el) => {
                el.checked = true;
            });

        ratings_search_str = '';
        global_params.fields.ratings.forEach((ft: number, i: number) => {
            if (0 == ft) {
                ratings_search_str += str_no_rating;
                if (global_params.fields.ratings.length > 1) {
                    ratings_search_str += ', ';
                }
            } else {
                const str_between = str_between_rating.split('%d');
                ratings_search_str +=
                    str_between[0] + (ft - 1) + str_between[1] + ft + str_between[2];
                if (global_params.fields.ratings.length - 1 != i) {
                    ratings_search_str += ', ';
                }
            }
        });

        if (global_params.fields.ratings && global_params.fields.ratings.length > 0) {
            document
                .querySelectorAll<HTMLElement>('.filter-ratings')
                .forEach((el) => el.classList.add('filter-filled'));
            document
                .querySelectorAll<HTMLElement>('.filter.filter-ratings .search-words')
                .forEach((el) => {
                    el.textContent = ratings_search_str;
                });

            document.querySelectorAll<HTMLInputElement>('.ratings-option input').forEach((inp) => {
                if (global_params.fields.ratings.includes(inp.getAttribute('name'))) {
                    inp.checked = true;
                }
            });
        } else {
            document
                .querySelectorAll<HTMLElement>('.filter.filter-ratings .search-words')
                .forEach((el) => {
                    el.textContent = str_rating_widget_label;
                });
        }

        document
            .querySelectorAll<HTMLElement>('.filter-ratings .filter-actions .clear')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-ratings .ratings-option input')
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                });
            });

        PS_params.ratings =
            global_params.fields.ratings.length > 0 ? global_params.fields.ratings : '';

        empty_filters_list.push(PS_params.ratings);
    }

    // Setup filesize filter
    if (
        global_params.fields.filesize_min != null &&
        global_params.fields.filesize_max != null &&
        sliders.filesizes
    ) {
        const filesizesSlider = sliders.filesizes;

        document.querySelectorAll<HTMLElement>('.filter-filesize').forEach((el) => {
            el.style.display = 'flex';
        });
        document
            .querySelectorAll<HTMLInputElement>('.filter-manager-controller.filesize')
            .forEach((el) => {
                el.checked = true;
            });
        document
            .querySelectorAll<HTMLElement>('.filter.filter-filesize .slider-info')
            .forEach((el) => {
                el.innerHTML = sprintf(
                    filesizesSlider.text,
                    filesizesSlider.selected.min,
                    filesizesSlider.selected.max
                );
            });

        document.querySelectorAll<HTMLElement>('[data-slider=filesizes]').forEach((el) => {
            pwgDoubleSlider(el, filesizesSlider);
        });

        document.querySelectorAll<HTMLElement>('[data-slider=filesizes]').forEach((sliderEl) => {
            sliderEl.addEventListener('slidestop', () => {
                const minInp = sliderEl.querySelector<HTMLInputElement>('[data-input=min]');
                const maxInp = sliderEl.querySelector<HTMLInputElement>('[data-input=max]');
                const min = minInp ? minInp.value : '';
                const max = maxInp ? maxInp.value : '';

                const fsMinText = document.querySelector<HTMLInputElement>(
                    'input[name=filter_filesize_min_text]'
                );
                if (fsMinText) {
                    fsMinText.value = min;
                    fsMinText.dispatchEvent(new Event('change'));
                }
                const fsMaxText = document.querySelector<HTMLInputElement>(
                    'input[name=filter_filesize_max_text]'
                );
                if (fsMaxText) {
                    fsMaxText.value = max;
                    fsMaxText.dispatchEvent(new Event('change'));
                }
            });
        });

        if (global_params.fields.filesize_min != null && global_params.fields.filesize_max > 0) {
            document
                .querySelectorAll<HTMLElement>('.filter-filesize')
                .forEach((el) => el.classList.add('filter-filled'));
            document
                .querySelectorAll<HTMLElement>('.filter.filter-filesize .search-words')
                .forEach((el) => {
                    el.innerHTML = sprintf(
                        filesizesSlider.text,
                        filesizesSlider.selected.min,
                        filesizesSlider.selected.max
                    );
                });
        } else {
            document
                .querySelectorAll<HTMLElement>('.filter.filter-filesize .search-words')
                .forEach((el) => {
                    el.textContent = str_filesize_widget_label;
                });
        }

        document
            .querySelectorAll<HTMLElement>('.filter-filesize .filter-actions .clear')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    updateFilters('filesize', 'add');
                    const filterFilesizeEl =
                        document.querySelector<HTMLElement>('.filter-filesize');
                    if (filterFilesizeEl) filterFilesizeEl.dispatchEvent(new Event('click'));
                    document
                        .querySelectorAll<HTMLElement>('[data-slider=filesizes]')
                        .forEach((sliderEl) => {
                            pwgDoubleSlider(sliderEl, filesizesSlider);
                        });
                    if (filterFilesizeEl && filterFilesizeEl.classList.contains('filter-filled')) {
                        filterFilesizeEl.classList.remove('filter-filled');
                        document
                            .querySelectorAll<HTMLElement>('.filter.filter-filesize .search-words')
                            .forEach((sw) => {
                                sw.textContent = str_filesize_widget_label;
                            });
                    }
                });
            });

        PS_params.filesize_min =
            global_params.fields.filesize_min != null ? global_params.fields.filesize_min : '';
        PS_params.filesize_max =
            global_params.fields.filesize_max != null ? global_params.fields.filesize_max : '';

        empty_filters_list.push(PS_params.filesize_min);
        empty_filters_list.push(PS_params.filesize_max);
    }

    // Setup Height filter
    if (
        global_params.fields.height_min != null &&
        global_params.fields.height_max != null &&
        sliders.heights
    ) {
        const heightsSlider = sliders.heights;
        document.querySelectorAll<HTMLElement>('.filter-height').forEach((el) => {
            el.style.display = 'flex';
        });
        document
            .querySelectorAll<HTMLInputElement>('.filter-manager-controller.height')
            .forEach((el) => {
                el.checked = true;
            });
        document
            .querySelectorAll<HTMLElement>('.filter.filter-height .slider-info')
            .forEach((el) => {
                el.innerHTML = sprintf(
                    heightsSlider.text,
                    heightsSlider.selected.min,
                    heightsSlider.selected.max
                );
            });

        document.querySelectorAll<HTMLElement>('[data-slider=heights]').forEach((el) => {
            pwgDoubleSlider(el, heightsSlider);
        });

        if (global_params.fields.height_min > 0 && global_params.fields.height_max > 0) {
            document
                .querySelectorAll<HTMLElement>('.filter-height')
                .forEach((el) => el.classList.add('filter-filled'));
            document
                .querySelectorAll<HTMLElement>('.filter.filter-height .search-words')
                .forEach((el) => {
                    el.innerHTML = sprintf(
                        heightsSlider.text,
                        heightsSlider.selected.min,
                        heightsSlider.selected.max
                    );
                });
        } else {
            document
                .querySelectorAll<HTMLElement>('.filter.filter-height .search-words')
                .forEach((el) => {
                    el.textContent = str_height_widget_label;
                });
        }

        document
            .querySelectorAll<HTMLElement>('.filter-height .filter-actions .clear')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    updateFilters('height', 'add');
                    const filterHeightEl = document.querySelector<HTMLElement>('.filter-height');
                    if (filterHeightEl) filterHeightEl.dispatchEvent(new Event('click'));
                    document
                        .querySelectorAll<HTMLElement>('[data-slider=heights]')
                        .forEach((sliderEl) => {
                            pwgDoubleSlider(sliderEl, heightsSlider);
                        });
                    if (filterHeightEl && filterHeightEl.classList.contains('filter-filled')) {
                        filterHeightEl.classList.remove('filter-filled');
                        document
                            .querySelectorAll<HTMLElement>('.filter.filter-height .search-words')
                            .forEach((sw) => {
                                sw.textContent = str_height_widget_label;
                            });
                    }
                });
            });

        PS_params.height_min =
            global_params.fields.height_min != null ? global_params.fields.height_min : '';
        PS_params.height_max =
            global_params.fields.height_max != null ? global_params.fields.height_max : '';

        empty_filters_list.push(PS_params.height_min);
        empty_filters_list.push(PS_params.height_max);
    }

    // Setup Width filter
    if (
        global_params.fields.width_min != null &&
        global_params.fields.width_max != null &&
        sliders.widths
    ) {
        const widthsSlider = sliders.widths;
        document.querySelectorAll<HTMLElement>('.filter-width').forEach((el) => {
            el.style.display = 'flex';
        });
        document
            .querySelectorAll<HTMLInputElement>('.filter-manager-controller.width')
            .forEach((el) => {
                el.checked = true;
            });
        document
            .querySelectorAll<HTMLElement>('.filter.filter-width .slider-info')
            .forEach((el) => {
                el.innerHTML = sprintf(
                    widthsSlider.text,
                    widthsSlider.selected.min,
                    widthsSlider.selected.max
                );
            });

        document.querySelectorAll<HTMLElement>('[data-slider=widths]').forEach((el) => {
            pwgDoubleSlider(el, widthsSlider);
        });

        if (global_params.fields.width_min > 0 && global_params.fields.width_max > 0) {
            document
                .querySelectorAll<HTMLElement>('.filter-width')
                .forEach((el) => el.classList.add('filter-filled'));
            document
                .querySelectorAll<HTMLElement>('.filter.filter-width .search-words')
                .forEach((el) => {
                    el.innerHTML = sprintf(
                        widthsSlider.text,
                        widthsSlider.selected.min,
                        widthsSlider.selected.max
                    );
                });
        } else {
            document
                .querySelectorAll<HTMLElement>('.filter.filter-width .search-words')
                .forEach((el) => {
                    el.textContent = str_width_widget_label;
                });
        }

        document
            .querySelectorAll<HTMLElement>('.filter-width .filter-actions .clear')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    updateFilters('width', 'add');
                    const filterWidthEl = document.querySelector<HTMLElement>('.filter-width');
                    if (filterWidthEl) filterWidthEl.dispatchEvent(new Event('click'));
                    document
                        .querySelectorAll<HTMLElement>('[data-slider=widths]')
                        .forEach((sliderEl) => {
                            pwgDoubleSlider(sliderEl, widthsSlider);
                        });
                    if (filterWidthEl && filterWidthEl.classList.contains('filter-filled')) {
                        filterWidthEl.classList.remove('filter-filled');
                        document
                            .querySelectorAll<HTMLElement>('.filter.filter-width .search-words')
                            .forEach((sw) => {
                                sw.textContent = str_width_widget_label;
                            });
                    }
                });
            });

        PS_params.width_min =
            global_params.fields.width_min != null ? global_params.fields.width_min : '';
        PS_params.width_max =
            global_params.fields.width_max != null ? global_params.fields.width_max : '';

        empty_filters_list.push(PS_params.width_min);
        empty_filters_list.push(PS_params.width_max);
    }

    // Setup Expert filter
    if (global_params.fields.expert) {
        document.querySelectorAll<HTMLElement>('.filter-expert').forEach((el) => {
            el.style.display = 'flex';
        });
        document
            .querySelectorAll<HTMLInputElement>('.filter-manager-controller.expert')
            .forEach((el) => {
                el.checked = true;
            });

        expert_search_str = global_params.fields.expert.string;
        const expertSearchInp = document.querySelector<HTMLInputElement>('#expert-search');
        if (expertSearchInp) expertSearchInp.value = expert_search_str;

        if (global_params.fields.expert.string && global_params.fields.expert.string.length > 0) {
            document
                .querySelectorAll<HTMLElement>('.filter-expert')
                .forEach((el) => el.classList.add('filter-filled'));
            document
                .querySelectorAll<HTMLElement>('.filter.filter-expert .search-words')
                .forEach((el) => {
                    el.textContent = expert_search_str;
                });
        } else {
            document
                .querySelectorAll<HTMLElement>('.filter.filter-expert .search-words')
                .forEach((el) => {
                    el.textContent = str_expert_widget_label;
                });
        }

        document
            .querySelectorAll<HTMLElement>('.filter-expert .filter-actions .clear')
            .forEach((el) => {
                el.addEventListener('click', () => {
                    const expInp = document.querySelector<HTMLInputElement>(
                        '.filter-expert #expert-search'
                    );
                    if (expInp) expInp.value = '';
                });
            });

        PS_params.expert =
            global_params.fields.expert.string.length > 0 ? global_params.fields.expert.string : '';

        empty_filters_list.push(PS_params.expert);
    }

    if (filters_to_remove.length > 0) {
        filters_to_remove = [];
        performSearch(PS_params, true);
    }

    // Adapt no result message
    if (document.querySelectorAll('.filter-filled').length === 0) {
        document.querySelectorAll<HTMLElement>('.mcs-no-result .text .top').forEach((el) => {
            el.innerHTML = str_empty_search_top_alt;
        });
        document.querySelectorAll<HTMLElement>('.mcs-no-result .text .bot').forEach((el) => {
            el.innerHTML = str_empty_search_bot_alt;
        });
    }

    if (
        !empty_filters_list.every(
            (param: any) => param === '' || param === null || typeof param == 'undefined'
        )
    ) {
        document
            .querySelectorAll<HTMLElement>('.clear-all')
            .forEach((el) => el.classList.add('clickable'));
        document.querySelectorAll<HTMLElement>('.clear-all.clickable').forEach((el) => {
            el.addEventListener('click', () => {
                const exclude_params = [
                    'search_id',
                    'allwords_mode',
                    'allwords_fields',
                    'tags_mode',
                    'categories_withsubs',
                ];
                for (const key in PS_params) {
                    if (!exclude_params.includes(key)) {
                        if ('date_posted_custom' == key || 'date_created_custom' == key) {
                            PS_params[key] = [];
                        } else {
                            PS_params[key] = '';
                        }
                    }
                }
                performSearch(PS_params, true);
            });
        });
    }

    /**
     * Filter Manager
     */
    document.querySelectorAll<HTMLElement>('.filter-manager').forEach((el) => {
        el.addEventListener('click', () => {
            const popin = document.querySelector<HTMLElement>('.filter-manager-popin');
            if (popin) popin.style.display = '';
        });
    });

    document.addEventListener('keyup', (e: KeyboardEvent) => {
        // 27 is 'Escape'
        if (e.keyCode === 27) {
            document
                .querySelector<HTMLElement>('.filter-manager-popin .filter-manager-close')
                ?.dispatchEvent(new Event('click'));
            document
                .querySelector<HTMLElement>('#closeModalQuickSearch')
                ?.dispatchEvent(new Event('click'));
        }
        // 13 is 'Enter'
        if (e.keyCode === 13) {
            document
                .querySelectorAll<HTMLElement>('.filter-form .filter-validate')
                .forEach((el) => {
                    if (el.style.display !== 'none' && el.offsetParent !== null) {
                        el.dispatchEvent(new Event('click'));
                    }
                });
        }
    });

    document.querySelectorAll<HTMLElement>('.filter-manager-popin').forEach((popinEl) => {
        popinEl.addEventListener('click', (e: Event) => {
            if (e.target === popinEl) {
                popinEl
                    .querySelector<HTMLElement>('.filter-manager-close')
                    ?.dispatchEvent(new Event('click'));
            }
        });
    });

    document
        .querySelectorAll<HTMLElement>(
            '.filter-manager-popin .filter-cancel, .filter-manager-popin .filter-manager-close'
        )
        .forEach((el) => {
            el.addEventListener('click', () => {
                const popin = document.querySelector<HTMLElement>('.filter-manager-popin');
                if (popin) popin.style.display = 'none';
                document
                    .querySelectorAll<HTMLInputElement>(
                        '.filter-manager-controller-container input'
                    )
                    .forEach((inp) => {
                        if (inp.checked) {
                            const widFilter = document.querySelector<HTMLElement>(
                                '.filter.filter-' + inp.dataset['wid']
                            );
                            if (widFilter && widFilter.style.display === 'none') {
                                inp.checked = false;
                            }
                        } else {
                            const widFilter = document.querySelector<HTMLElement>(
                                '.filter.filter-' + inp.dataset['wid']
                            );
                            if (
                                widFilter &&
                                widFilter.style.display !== 'none' &&
                                widFilter.offsetParent !== null
                            ) {
                                inp.checked = true;
                            }
                        }
                    });
            });
        });

    document
        .querySelectorAll<HTMLElement>('.filter-manager-popin .filter-validate')
        .forEach((el) => {
            el.addEventListener('click', () => {
                document
                    .querySelectorAll<HTMLInputElement>(
                        '.filter-manager-controller-container input'
                    )
                    .forEach((inp) => {
                        if (inp.checked) {
                            const widFilter = document.querySelector<HTMLElement>(
                                '.filter.filter-' + inp.dataset['wid']
                            );
                            if (widFilter && widFilter.style.display === 'none') {
                                updateFilters(inp.dataset['wid']!, 'add');
                            }
                        } else {
                            const widFilter = document.querySelector<HTMLElement>(
                                '.filter.filter-' + inp.dataset['wid']
                            );
                            if (
                                widFilter &&
                                widFilter.style.display !== 'none' &&
                                widFilter.offsetParent !== null
                            ) {
                                updateFilters(inp.dataset['wid']!, 'del');
                            }
                        }
                    });
                // Set second param to true to trigger reload
                performSearch(PS_params, true);
            });
        });

    /**
     * Tags & Albums found
     */
    document.querySelectorAll<HTMLElement>('.mcs-tags-found').forEach((el) => {
        el.addEventListener('click', () => {
            const popin = document.querySelector<HTMLElement>('.tags-found-popin');
            if (popin) popin.style.display = '';
        });
    });
    document.querySelectorAll<HTMLElement>('.mcs-albums-found').forEach((el) => {
        el.addEventListener('click', () => {
            const popin = document.querySelector<HTMLElement>('.albums-found-popin');
            if (popin) popin.style.display = '';
        });
    });

    document.addEventListener('keyup', (e: KeyboardEvent) => {
        // 27 is 'Escape'
        if (e.keyCode === 27) {
            document
                .querySelector<HTMLElement>('.tags-found-popin .tags-found-close')
                ?.dispatchEvent(new Event('click'));
            document
                .querySelector<HTMLElement>('.albums-found-popin .albums-found-close')
                ?.dispatchEvent(new Event('click'));
        }
    });

    document.querySelectorAll<HTMLElement>('.tags-found-popin').forEach((popinEl) => {
        popinEl.addEventListener('click', (e: Event) => {
            if (e.target === popinEl) {
                popinEl
                    .querySelector<HTMLElement>('.tags-found-close')
                    ?.dispatchEvent(new Event('click'));
            }
        });
    });
    document.querySelectorAll<HTMLElement>('.tags-found-close').forEach((el) => {
        el.addEventListener('click', () => {
            const popin = document.querySelector<HTMLElement>('.tags-found-popin');
            if (popin) popin.style.display = 'none';
        });
    });

    document.querySelectorAll<HTMLElement>('.albums-found-popin').forEach((popinEl) => {
        popinEl.addEventListener('click', (e: Event) => {
            if (e.target === popinEl) {
                popinEl
                    .querySelector<HTMLElement>('.albums-found-close')
                    ?.dispatchEvent(new Event('click'));
            }
        });
    });
    document.querySelectorAll<HTMLElement>('.albums-found-close').forEach((el) => {
        el.addEventListener('click', () => {
            const popin = document.querySelector<HTMLElement>('.albums-found-popin');
            if (popin) popin.style.display = 'none';
        });
    });

    /**
     * Filter Word
     */
    const filterWordEl = document.querySelector<HTMLElement>('.filter-word');
    if (filterWordEl) {
        filterWordEl.addEventListener('click', (e: Event) => {
            const filterForm = filterWordEl.querySelector('.filter-form');
            const target = e.target as HTMLElement;
            if (
                (filterForm && filterForm.contains(target)) ||
                target.classList.contains('filter-form')
            ) {
                return;
            }
            const filterWordForm = document.querySelector<HTMLElement>('.filter-word-form');
            if (!filterWordForm) return;

            // toggle visibility
            const isVisible =
                filterWordForm.style.display !== 'none' && filterWordForm.offsetParent !== null;
            filterWordForm.style.display = isVisible ? 'none' : '';

            if (!isVisible) {
                // just became visible
                filterWordEl.classList.add('show-filter-dropdown');
                document.querySelector<HTMLInputElement>('#word-search')?.focus();
            } else {
                // just became hidden
                filterWordEl.classList.remove('show-filter-dropdown');

                global_params.fields.allwords = {};
                global_params.fields.allwords.words =
                    document.querySelector<HTMLInputElement>('#word-search')?.value ?? '';
                global_params.fields.allwords.mode =
                    document
                        .querySelector<HTMLInputElement>('.word-search-options input:checked')
                        ?.getAttribute('value') ?? '';

                PS_params.allwords =
                    document.querySelector<HTMLInputElement>('#word-search')?.value ?? '';
                PS_params.allwords_mode =
                    document
                        .querySelector<HTMLInputElement>('.word-search-options input:checked')
                        ?.getAttribute('value') ?? '';

                const new_fields: string[] = [];
                document
                    .querySelectorAll<HTMLInputElement>(
                        '.filter-word-form .search-params input:checked'
                    )
                    .forEach((inp) => {
                        if (inp.getAttribute('name') == 'tags') {
                            global_params.fields.search_in_tags = true;
                        }
                        new_fields.push(inp.getAttribute('name') ?? '');
                    });
                if (
                    document.querySelectorAll(
                        ".filter-word-form .search-params input[name='tags']:checked"
                    ).length == 0
                ) {
                    delete global_params.fields.search_in_tags;
                }
                global_params.fields.allwords.fields = new_fields;
                PS_params.allwords_fields = new_fields.length > 0 ? new_fields : '';
            }
        });
    }
    document.querySelectorAll<HTMLElement>('.filter-word .filter-validate').forEach((el) => {
        el.addEventListener('click', () => {
            filterWordEl?.dispatchEvent(new Event('click'));
            performSearch(PS_params, true);
        });
    });
    document.querySelectorAll<HTMLElement>('.filter-word .filter-actions .delete').forEach((el) => {
        el.addEventListener('click', () => {
            updateFilters('word', 'del');
            performSearch(PS_params, true);
            if (filterWordEl && !filterWordEl.classList.contains('filter-filled')) {
                filterWordEl.style.display = 'none';
                document
                    .querySelectorAll<HTMLInputElement>('.filter-manager-controller.word')
                    .forEach((inp) => {
                        inp.checked = false;
                    });
            }
        });
    });

    /**
     * Filter Tag
     */
    const filterTagEl = document.querySelector<HTMLElement>('.filter-tag');
    if (filterTagEl) {
        filterTagEl.addEventListener('click', (e: Event) => {
            const filterForm = filterTagEl.querySelector('.filter-form');
            const target = e.target as HTMLElement;
            if (
                (filterForm && filterForm.contains(target)) ||
                target.classList.contains('filter-form') ||
                target.classList.contains('remove')
            ) {
                return;
            }
            const filterTagForm = document.querySelector<HTMLElement>('.filter-tag-form');
            if (!filterTagForm) return;

            const isVisible =
                filterTagForm.style.display !== 'none' && filterTagForm.offsetParent !== null;
            filterTagForm.style.display = isVisible ? 'none' : '';

            if (!isVisible) {
                filterTagEl.classList.add('show-filter-dropdown');
            } else {
                filterTagEl.classList.remove('show-filter-dropdown');
                global_params.fields.tags = {};
                global_params.fields.tags.mode =
                    document.querySelector<HTMLInputElement>(
                        '.filter-tag-form .search-params input:checked'
                    )?.value ?? '';
                global_params.fields.tags.words = tagSelectTs
                    ? (tagSelectTs.getValue() as string[])
                    : [];

                const tsVal = tagSelectTs ? (tagSelectTs.getValue() as string[]) : [];
                PS_params.tags = tsVal.length > 0 ? tsVal : '';
                PS_params.tags_mode =
                    document.querySelector<HTMLInputElement>(
                        '.filter-tag-form .search-params input:checked'
                    )?.value ?? '';
            }
        });
    }
    document.querySelectorAll<HTMLElement>('.filter-tag .filter-validate').forEach((el) => {
        el.addEventListener('click', () => {
            filterTagEl?.dispatchEvent(new Event('click'));
            performSearch(PS_params, true);
        });
    });
    document.querySelectorAll<HTMLElement>('.filter-tag .filter-actions .delete').forEach((el) => {
        el.addEventListener('click', () => {
            updateFilters('tag', 'del');
            performSearch(PS_params, true);
            if (filterTagEl && !filterTagEl.classList.contains('filter-filled')) {
                filterTagEl.style.display = 'none';
                document
                    .querySelectorAll<HTMLInputElement>('.filter-manager-controller.tags')
                    .forEach((inp) => {
                        inp.checked = false;
                    });
            }
        });
    });

    /**
     * Filter Date posted
     */
    const filterDatePostedEl = document.querySelector<HTMLElement>('.filter-date_posted');
    if (filterDatePostedEl) {
        filterDatePostedEl.addEventListener('click', (e: Event) => {
            const filterForm = filterDatePostedEl.querySelector('.filter-form');
            const target = e.target as HTMLElement;
            if (
                (filterForm && filterForm.contains(target)) ||
                target.classList.contains('filter-form')
            ) {
                return;
            }
            const filterDatePostedForm = document.querySelector<HTMLElement>(
                '.filter-date_posted-form'
            );
            if (!filterDatePostedForm) return;

            const isVisible =
                filterDatePostedForm.style.display !== 'none' &&
                filterDatePostedForm.offsetParent !== null;
            filterDatePostedForm.style.display = isVisible ? 'none' : '';

            if (!isVisible) {
                filterDatePostedEl.classList.add('show-filter-dropdown');
            } else {
                filterDatePostedEl.classList.remove('show-filter-dropdown');

                const presetValue = document.querySelector<HTMLInputElement>(
                    '.preset_posted_date .date_posted-option input:checked'
                )?.value;

                global_params.fields.date_posted.preset = presetValue;
                PS_params.date_posted_preset = presetValue != null ? presetValue : '';

                if ('custom' == presetValue) {
                    const customDates: string[] = [];

                    document
                        .querySelectorAll<HTMLInputElement>(
                            '.custom_posted_date .date_posted-option input:checked'
                        )
                        .forEach((inp) => {
                            customDates.push(inp.value);
                        });

                    global_params.fields.date_posted.custom = customDates;
                    PS_params.date_posted_custom = customDates.length > 0 ? customDates : '';
                }
            }
        });
    }

    document.querySelectorAll<HTMLElement>('.filter-date_posted .filter-validate').forEach((el) => {
        el.addEventListener('click', () => {
            filterDatePostedEl?.dispatchEvent(new Event('click'));
            performSearch(PS_params, true);
        });
    });

    document
        .querySelectorAll<HTMLElement>('.filter-date_posted .filter-actions .delete')
        .forEach((el) => {
            el.addEventListener('click', () => {
                updateFilters('date_posted', 'del');
                performSearch(PS_params, true);
                if (filterDatePostedEl && !filterDatePostedEl.classList.contains('filter-filled')) {
                    filterDatePostedEl.style.display = 'none';
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-manager-controller.date')
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                }
            });
        });

    /**
     * Filter Date created
     */
    const filterDateCreatedEl = document.querySelector<HTMLElement>('.filter-date_created');
    if (filterDateCreatedEl) {
        filterDateCreatedEl.addEventListener('click', (e: Event) => {
            const filterForm = filterDateCreatedEl.querySelector('.filter-form');
            const target = e.target as HTMLElement;
            if (
                (filterForm && filterForm.contains(target)) ||
                target.classList.contains('filter-form')
            ) {
                return;
            }
            const filterDateCreatedForm = document.querySelector<HTMLElement>(
                '.filter-date_created-form'
            );
            if (!filterDateCreatedForm) return;

            const isVisible =
                filterDateCreatedForm.style.display !== 'none' &&
                filterDateCreatedForm.offsetParent !== null;
            filterDateCreatedForm.style.display = isVisible ? 'none' : '';

            if (!isVisible) {
                filterDateCreatedEl.classList.add('show-filter-dropdown');
            } else {
                filterDateCreatedEl.classList.remove('show-filter-dropdown');

                const presetValue = document.querySelector<HTMLInputElement>(
                    '.preset_created_date .date_created-option input:checked'
                )?.value;

                global_params.fields.date_created.preset = presetValue;
                PS_params.date_created_preset = presetValue != null ? presetValue : '';

                if ('custom' == presetValue) {
                    const customDates: string[] = [];

                    document
                        .querySelectorAll<HTMLInputElement>(
                            '.custom_created_date .date_created-option input:checked'
                        )
                        .forEach((inp) => {
                            customDates.push(inp.value);
                        });

                    global_params.fields.date_created.custom = customDates;
                    PS_params.date_created_custom = customDates.length > 0 ? customDates : '';
                }
            }
        });
    }

    document
        .querySelectorAll<HTMLElement>('.filter-date_created .filter-validate')
        .forEach((el) => {
            el.addEventListener('click', () => {
                filterDateCreatedEl?.dispatchEvent(new Event('click'));
                performSearch(PS_params, true);
            });
        });

    document
        .querySelectorAll<HTMLElement>('.filter-date_created .filter-actions .delete')
        .forEach((el) => {
            el.addEventListener('click', () => {
                updateFilters('date_created', 'del');
                performSearch(PS_params, true);
                if (
                    filterDateCreatedEl &&
                    !filterDateCreatedEl.classList.contains('filter-filled')
                ) {
                    filterDateCreatedEl.style.display = 'none';
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-manager-controller.date')
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                }
            });
        });

    /**
     * Filter Album
     */
    const filterAlbumEl = document.querySelector<HTMLElement>('.filter-album');
    if (filterAlbumEl) {
        filterAlbumEl.addEventListener('click', (e: Event) => {
            const filterForm = filterAlbumEl.querySelector('.filter-form');
            const target = e.target as HTMLElement;
            if (
                (filterForm && filterForm.contains(target)) ||
                target.classList.contains('filter-form') ||
                target.classList.contains('remove-item')
            ) {
                return;
            }
            const filterAlbumForm = document.querySelector<HTMLElement>('.filter-album-form');
            if (!filterAlbumForm) return;

            const isVisible =
                filterAlbumForm.style.display !== 'none' && filterAlbumForm.offsetParent !== null;
            filterAlbumForm.style.display = isVisible ? 'none' : '';

            if (!isVisible) {
                filterAlbumEl.classList.add('show-filter-dropdown');
            } else {
                filterAlbumEl.classList.remove('show-filter-dropdown');
                global_params.fields.cat = {};
                global_params.fields.cat.words = [];
                global_params.fields.cat.sub_inc =
                    document.querySelectorAll("input[name='search-sub-cats']:checked").length != 0;

                PS_params.categories = '';
                PS_params.categories_withsubs =
                    document.querySelectorAll("input[name='search-sub-cats']:checked").length != 0;
            }
        });
    }
    document.querySelectorAll<HTMLElement>('.filter-album .filter-validate').forEach((el) => {
        el.addEventListener('click', () => {
            filterAlbumEl?.dispatchEvent(new Event('click'));
            performSearch(PS_params, true);
        });
    });
    document
        .querySelectorAll<HTMLElement>('.filter-album .filter-actions .delete')
        .forEach((el) => {
            el.addEventListener('click', () => {
                updateFilters('album', 'del');
                performSearch(PS_params, true);
                if (filterAlbumEl && !filterAlbumEl.classList.contains('filter-filled')) {
                    filterAlbumEl.style.display = 'none';
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-manager-controller.album')
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                }
            });
        });

    /**
     * Author Widget
     */
    const filterAuthorsEl = document.querySelector<HTMLElement>('.filter-authors');
    if (filterAuthorsEl) {
        filterAuthorsEl.addEventListener('click', (e: Event) => {
            const filterForm = filterAuthorsEl.querySelector('.filter-form');
            const target = e.target as HTMLElement;
            if (
                (filterForm && filterForm.contains(target)) ||
                target.classList.contains('filter-form') ||
                target.classList.contains('remove')
            ) {
                return;
            }
            const filterAuthorForm = document.querySelector<HTMLElement>('.filter-author-form');
            if (!filterAuthorForm) return;

            const isVisible =
                filterAuthorForm.style.display !== 'none' && filterAuthorForm.offsetParent !== null;
            filterAuthorForm.style.display = isVisible ? 'none' : '';

            if (!isVisible) {
                filterAuthorsEl.classList.add('show-filter-dropdown');
            } else {
                filterAuthorsEl.classList.remove('show-filter-dropdown');
                global_params.fields.author = {};
                global_params.fields.author.mode = 'OR';
                global_params.fields.author.words = authorSelectTs
                    ? (authorSelectTs.getValue() as string[])
                    : [];

                const authVal = authorSelectTs ? (authorSelectTs.getValue() as string[]) : [];
                PS_params.authors = authVal.length > 0 ? authVal : '';
            }
        });
    }
    document.querySelectorAll<HTMLElement>('.filter-authors .filter-validate').forEach((el) => {
        el.addEventListener('click', () => {
            filterAuthorsEl?.dispatchEvent(new Event('click'));
            performSearch(PS_params, true);
        });
    });
    document
        .querySelectorAll<HTMLElement>('.filter-authors .filter-actions .delete')
        .forEach((el) => {
            el.addEventListener('click', () => {
                updateFilters('authors', 'del');
                performSearch(PS_params, true);
                if (filterAuthorsEl && !filterAuthorsEl.classList.contains('filter-filled')) {
                    filterAuthorsEl.style.display = 'none';
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-manager-controller.author')
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                }
            });
        });

    /**
     * Added by Widget
     */
    const filterAddedByEl = document.querySelector<HTMLElement>('.filter-added_by');
    if (filterAddedByEl) {
        filterAddedByEl.addEventListener('click', (e: Event) => {
            const filterForm = filterAddedByEl.querySelector('.filter-form');
            const target = e.target as HTMLElement;
            if (
                (filterForm && filterForm.contains(target)) ||
                target.classList.contains('filter-form') ||
                target.classList.contains('remove')
            ) {
                return;
            }
            const filterAddedByForm = document.querySelector<HTMLElement>('.filter-added_by-form');
            if (!filterAddedByForm) return;

            const isVisible =
                filterAddedByForm.style.display !== 'none' &&
                filterAddedByForm.offsetParent !== null;
            filterAddedByForm.style.display = isVisible ? 'none' : '';

            if (!isVisible) {
                filterAddedByEl.classList.add('show-filter-dropdown');
            } else {
                filterAddedByEl.classList.remove('show-filter-dropdown');
                global_params.fields.added_by = {};
                global_params.fields.added_by.mode = 'OR';

                const added_by_array: string[] = [];
                document
                    .querySelectorAll<HTMLInputElement>('.added_by-option input:checked')
                    .forEach((inp) => {
                        added_by_array.push(inp.getAttribute('name') ?? '');
                    });

                global_params.fields.added_by.words = added_by_array;

                PS_params.added_by = added_by_array.length > 0 ? added_by_array : '';
            }
        });
    }
    document.querySelectorAll<HTMLElement>('.filter-added_by .filter-validate').forEach((el) => {
        el.addEventListener('click', () => {
            filterAddedByEl?.dispatchEvent(new Event('click'));
            performSearch(PS_params, true);
        });
    });
    document
        .querySelectorAll<HTMLElement>('.filter-added_by .filter-actions .delete')
        .forEach((el) => {
            el.addEventListener('click', () => {
                updateFilters('added_by', 'del');
                performSearch(PS_params, true);
                if (filterAddedByEl && !filterAddedByEl.classList.contains('filter-filled')) {
                    filterAddedByEl.style.display = 'none';
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-manager-controller.added_by')
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                }
            });
        });

    /**
     * File type Widget
     */
    const filterFiletypesEl = document.querySelector<HTMLElement>('.filter-filetypes');
    if (filterFiletypesEl) {
        filterFiletypesEl.addEventListener('click', (e: Event) => {
            const filterForm = filterFiletypesEl.querySelector('.filter-form');
            const target = e.target as HTMLElement;
            if (
                (filterForm && filterForm.contains(target)) ||
                target.classList.contains('filter-form') ||
                target.classList.contains('remove')
            ) {
                return;
            }
            const filterFiletypesForm =
                document.querySelector<HTMLElement>('.filter-filetypes-form');
            if (!filterFiletypesForm) return;

            const isVisible =
                filterFiletypesForm.style.display !== 'none' &&
                filterFiletypesForm.offsetParent !== null;
            filterFiletypesForm.style.display = isVisible ? 'none' : '';

            if (!isVisible) {
                filterFiletypesEl.classList.add('show-filter-dropdown');
            } else {
                filterFiletypesEl.classList.remove('show-filter-dropdown');

                const filetypes_array: string[] = [];
                document
                    .querySelectorAll<HTMLInputElement>('.filetypes-option input:checked')
                    .forEach((inp) => {
                        filetypes_array.push(inp.getAttribute('name') ?? '');
                    });

                global_params.fields.filetypes = filetypes_array;

                PS_params.filetypes = filetypes_array.length > 0 ? filetypes_array : '';
            }
        });
    }

    document.querySelectorAll<HTMLElement>('.filter-filetypes .filter-validate').forEach((el) => {
        el.addEventListener('click', () => {
            filterFiletypesEl?.dispatchEvent(new Event('click'));
            performSearch(PS_params, true);
        });
    });
    document
        .querySelectorAll<HTMLElement>('.filter-filetypes .filter-actions .delete')
        .forEach((el) => {
            el.addEventListener('click', () => {
                updateFilters('filetypes', 'del');
                performSearch(PS_params, true);
                if (filterFiletypesEl && !filterFiletypesEl.classList.contains('filter-filled')) {
                    filterFiletypesEl.style.display = 'none';
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-manager-controller.filetypes')
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                }
            });
        });

    /**
     * Ratios widget
     */
    const filterRatiosEl = document.querySelector<HTMLElement>('.filter-ratios');
    if (filterRatiosEl) {
        filterRatiosEl.addEventListener('click', (e: Event) => {
            const filterForm = filterRatiosEl.querySelector('.filter-form');
            const target = e.target as HTMLElement;
            if (
                (filterForm && filterForm.contains(target)) ||
                target.classList.contains('filter-form') ||
                target.classList.contains('remove')
            ) {
                return;
            }
            const filterRatiosForm = document.querySelector<HTMLElement>('.filter-ratios-form');
            if (!filterRatiosForm) return;

            const isVisible =
                filterRatiosForm.style.display !== 'none' && filterRatiosForm.offsetParent !== null;
            filterRatiosForm.style.display = isVisible ? 'none' : '';

            if (!isVisible) {
                filterRatiosEl.classList.add('show-filter-dropdown');
            } else {
                filterRatiosEl.classList.remove('show-filter-dropdown');

                const ratios_array: string[] = [];
                document
                    .querySelectorAll<HTMLInputElement>('.ratios-option input:checked')
                    .forEach((inp) => {
                        ratios_array.push(inp.getAttribute('name') ?? '');
                    });

                global_params.fields.ratios = ratios_array;

                PS_params.ratios = ratios_array.length > 0 ? ratios_array : '';
            }
        });
    }

    document.querySelectorAll<HTMLElement>('.filter-ratios .filter-validate').forEach((el) => {
        el.addEventListener('click', () => {
            filterRatiosEl?.dispatchEvent(new Event('click'));
            performSearch(PS_params, true);
        });
    });
    document
        .querySelectorAll<HTMLElement>('.filter-ratios .filter-actions .delete')
        .forEach((el) => {
            el.addEventListener('click', () => {
                updateFilters('ratios', 'del');
                performSearch(PS_params, true);
                if (filterRatiosEl && !filterRatiosEl.classList.contains('filter-filled')) {
                    filterRatiosEl.style.display = 'none';
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-manager-controller.ratios')
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                }
            });
        });

    /**
     * Rating widget
     */
    const filterRatingsEl = document.querySelector<HTMLElement>('.filter-ratings');
    if (filterRatingsEl) {
        filterRatingsEl.addEventListener('click', (e: Event) => {
            const filterForm = filterRatingsEl.querySelector('.filter-form');
            const target = e.target as HTMLElement;
            if (
                (filterForm && filterForm.contains(target)) ||
                target.classList.contains('filter-form') ||
                target.classList.contains('remove')
            ) {
                return;
            }
            const filterRatingsForm = document.querySelector<HTMLElement>('.filter-ratings-form');
            if (!filterRatingsForm) return;

            const isVisible =
                filterRatingsForm.style.display !== 'none' &&
                filterRatingsForm.offsetParent !== null;
            filterRatingsForm.style.display = isVisible ? 'none' : '';

            if (!isVisible) {
                filterRatingsEl.classList.add('show-filter-dropdown');
            } else {
                filterRatingsEl.classList.remove('show-filter-dropdown');
                const ratings_array: string[] = [];

                document
                    .querySelectorAll<HTMLInputElement>('.ratings-option input:checked')
                    .forEach((inp) => {
                        ratings_array.push(inp.getAttribute('name') ?? '');
                    });

                global_params.fields.ratings = ratings_array;
                PS_params.ratings = ratings_array.length > 0 ? ratings_array : '';
            }
        });
    }

    document.querySelectorAll<HTMLElement>('.filter-ratings .filter-validate').forEach((el) => {
        el.addEventListener('click', () => {
            filterRatingsEl?.dispatchEvent(new Event('click'));
            performSearch(PS_params, true);
        });
    });
    document
        .querySelectorAll<HTMLElement>('.filter-ratings .filter-actions .delete')
        .forEach((el) => {
            el.addEventListener('click', () => {
                updateFilters('ratings', 'del');
                performSearch(PS_params, true);
                if (filterRatingsEl && !filterRatingsEl.classList.contains('filter-filled')) {
                    filterRatingsEl.style.display = 'none';
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-manager-controller.ratings')
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                }
            });
        });

    /**
     * Filesize widget
     */
    const filterFilesizeEl = document.querySelector<HTMLElement>('.filter-filesize');
    if (filterFilesizeEl) {
        filterFilesizeEl.addEventListener('click', (e: Event) => {
            const filterForm = filterFilesizeEl.querySelector('.filter-form');
            const target = e.target as HTMLElement;
            if (
                (filterForm && filterForm.contains(target)) ||
                target.classList.contains('filter-form') ||
                target.classList.contains('remove')
            ) {
                return;
            }
            const filterFilesizeForm = document.querySelector<HTMLElement>('.filter-filesize-form');
            if (!filterFilesizeForm) return;

            const isVisible =
                filterFilesizeForm.style.display !== 'none' &&
                filterFilesizeForm.offsetParent !== null;
            filterFilesizeForm.style.display = isVisible ? 'none' : '';

            if (!isVisible) {
                filterFilesizeEl.classList.add('show-filter-dropdown');
            } else {
                filterFilesizeEl.classList.remove('show-filter-dropdown');
            }
        });
    }
    document.querySelectorAll<HTMLElement>('.filter-filesize .filter-validate').forEach((el) => {
        el.addEventListener('click', () => {
            filesize_min = Math.floor(
                parseFloat(
                    document.querySelector<HTMLInputElement>('input[name=filter_filesize_min]')
                        ?.value ?? '0'
                ) * 1024
            );
            filesize_max = Math.ceil(
                parseFloat(
                    document.querySelector<HTMLInputElement>('input[name=filter_filesize_max]')
                        ?.value ?? '0'
                ) * 1024
            );

            global_params.fields.filesize_min = filesize_min;
            global_params.fields.filesize_max = filesize_max;

            PS_params.filesize_min = filesize_min;
            PS_params.filesize_max = filesize_max;

            filterFilesizeEl?.dispatchEvent(new Event('click'));
            performSearch(PS_params, true);
        });
    });

    document
        .querySelectorAll<HTMLElement>('.filter-filesize .filter-actions .delete')
        .forEach((el) => {
            el.addEventListener('click', () => {
                updateFilters('filesize', 'del');
                performSearch(PS_params, true);
                if (filterFilesizeEl && !filterFilesizeEl.classList.contains('filter-filled')) {
                    filterFilesizeEl.style.display = 'none';
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-manager-controller.filesize')
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                }
            });
        });

    /**
     * Height widget
     */
    const filterHeightEl = document.querySelector<HTMLElement>('.filter-height');
    if (filterHeightEl) {
        filterHeightEl.addEventListener('click', (e: Event) => {
            const filterForm = filterHeightEl.querySelector('.filter-form');
            const target = e.target as HTMLElement;
            if (
                (filterForm && filterForm.contains(target)) ||
                target.classList.contains('filter-form') ||
                target.classList.contains('remove')
            ) {
                return;
            }
            const filterHeightForm = document.querySelector<HTMLElement>('.filter-height-form');
            if (!filterHeightForm) return;

            const isVisible =
                filterHeightForm.style.display !== 'none' && filterHeightForm.offsetParent !== null;
            filterHeightForm.style.display = isVisible ? 'none' : '';

            if (!isVisible) {
                filterHeightEl.classList.add('show-filter-dropdown');
            } else {
                filterHeightEl.classList.remove('show-filter-dropdown');
            }
        });
    }
    document.querySelectorAll<HTMLElement>('.filter-height .filter-validate').forEach((el) => {
        el.addEventListener('click', () => {
            height_min =
                document.querySelector<HTMLInputElement>('input[name=filter_height_min]')?.value ??
                '';
            height_max =
                document.querySelector<HTMLInputElement>('input[name=filter_height_max]')?.value ??
                '';

            global_params.fields.height_min = height_min;
            global_params.fields.height_max = height_max;

            PS_params.height_min = height_min;
            PS_params.height_max = height_max;

            filterHeightEl?.dispatchEvent(new Event('click'));
            performSearch(PS_params, true);
        });
    });

    document
        .querySelectorAll<HTMLElement>('.filter-height .filter-actions .delete')
        .forEach((el) => {
            el.addEventListener('click', () => {
                updateFilters('height', 'del');
                performSearch(PS_params, true);
                if (filterHeightEl && !filterHeightEl.classList.contains('filter-filled')) {
                    filterHeightEl.style.display = 'none';
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-manager-controller.height')
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                }
            });
        });

    /**
     * Width widget
     */
    const filterWidthEl = document.querySelector<HTMLElement>('.filter-width');
    if (filterWidthEl) {
        filterWidthEl.addEventListener('click', (e: Event) => {
            const filterForm = filterWidthEl.querySelector('.filter-form');
            const target = e.target as HTMLElement;
            if (
                (filterForm && filterForm.contains(target)) ||
                target.classList.contains('filter-form') ||
                target.classList.contains('remove')
            ) {
                return;
            }
            const filterWidthForm = document.querySelector<HTMLElement>('.filter-width-form');
            if (!filterWidthForm) return;

            const isVisible =
                filterWidthForm.style.display !== 'none' && filterWidthForm.offsetParent !== null;
            filterWidthForm.style.display = isVisible ? 'none' : '';

            if (!isVisible) {
                filterWidthEl.classList.add('show-filter-dropdown');
            } else {
                filterWidthEl.classList.remove('show-filter-dropdown');
            }
        });
    }
    document.querySelectorAll<HTMLElement>('.filter-width .filter-validate').forEach((el) => {
        el.addEventListener('click', () => {
            width_min =
                document.querySelector<HTMLInputElement>('input[name=filter_width_min]')?.value ??
                '';
            width_max =
                document.querySelector<HTMLInputElement>('input[name=filter_width_max]')?.value ??
                '';

            global_params.fields.width_min = width_min;
            global_params.fields.width_max = width_max;

            PS_params.width_min = width_min;
            PS_params.width_max = width_max;

            filterWidthEl?.dispatchEvent(new Event('click'));
            performSearch(PS_params, true);
        });
    });

    document
        .querySelectorAll<HTMLElement>('.filter-width .filter-actions .delete')
        .forEach((el) => {
            el.addEventListener('click', () => {
                updateFilters('width', 'del');
                performSearch(PS_params, true);
                if (filterWidthEl && !filterWidthEl.classList.contains('filter-filled')) {
                    filterWidthEl.style.display = 'none';
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-manager-controller.width')
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                }
            });
        });

    /**
     * Expert widget
     */
    const filterExpertEl = document.querySelector<HTMLElement>('.filter-expert');
    if (filterExpertEl) {
        filterExpertEl.addEventListener('click', (e: Event) => {
            const filterForm = filterExpertEl.querySelector('.filter-form');
            const target = e.target as HTMLElement;
            if (
                (filterForm && filterForm.contains(target)) ||
                target.classList.contains('filter-form') ||
                target.classList.contains('remove')
            ) {
                return;
            }
            const filterExpertForm = document.querySelector<HTMLElement>('.filter-expert-form');
            if (!filterExpertForm) return;

            const isVisible =
                filterExpertForm.style.display !== 'none' && filterExpertForm.offsetParent !== null;
            filterExpertForm.style.display = isVisible ? 'none' : '';

            if (!isVisible) {
                filterExpertEl.classList.add('show-filter-dropdown');
            } else {
                filterExpertEl.classList.remove('show-filter-dropdown');

                global_params.fields.expert =
                    document.querySelector<HTMLInputElement>('#expert-search')?.value ?? '';
                PS_params.expert =
                    document.querySelector<HTMLInputElement>('#expert-search')?.value ?? '';
            }
        });
    }

    document.querySelectorAll<HTMLElement>('.filter-expert .filter-validate').forEach((el) => {
        el.addEventListener('click', () => {
            filterExpertEl?.dispatchEvent(new Event('click'));
            performSearch(PS_params, true);
        });
    });
    document
        .querySelectorAll<HTMLElement>('.filter-expert .filter-actions .delete')
        .forEach((el) => {
            el.addEventListener('click', () => {
                updateFilters('expert', 'del');
                performSearch(PS_params, true);
                if (filterExpertEl && !filterExpertEl.classList.contains('filter-filled')) {
                    filterExpertEl.style.display = 'none';
                    document
                        .querySelectorAll<HTMLInputElement>('.filter-manager-controller.expert')
                        .forEach((inp) => {
                            inp.checked = false;
                        });
                }
            });
        });

    // Initialize Tippy tooltips
    tippy('.tiptip');
});

function performSearch(params: Record<string, any>, reload: boolean = false): void {
    fetch('ws.php?format=json&method=pwg.images.filteredSearch.create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(
            Object.fromEntries(
                Object.entries(params).map(([k, v]) => [
                    k,
                    Array.isArray(v) ? v.join(',') : String(v ?? ''),
                ])
            )
        ),
    })
        .then((res) => res.json())
        .then((data: any) => {
            if (reload && typeof data.result.search_url !== 'undefined') {
                reloadPage(data.result.search_url);
            }
        })
        .catch((e: any) => {
            console.log(e);
            document.querySelectorAll<HTMLElement>('.filter-form').forEach((el) => {
                el.insertAdjacentHTML('beforeend', '<p class="error">Error</p>');
            });
            document
                .querySelectorAll<HTMLElement>('.filter-validate .validate-text')
                .forEach((el) => {
                    el.style.display = 'block';
                });
            document.querySelectorAll<HTMLElement>('.filter-validate .loading').forEach((el) => {
                el.style.display = 'none';
            });
            document.querySelectorAll<HTMLElement>('.remove-filter').forEach((el) => {
                el.classList.remove(prefix_icon + 'spin6', 'animate-spin');
                el.classList.add(prefix_icon + 'cancel');
            });
        });
}

function add_related_category({
    album,
    addSelectedAlbum,
}: {
    album: { id: string; name: string };
    addSelectedAlbum: () => void;
}): void {
    display_related_category(album.id, album.name);
    document
        .querySelector<HTMLElement>('.invisible-related-categories-select')
        ?.insertAdjacentHTML('beforeend', `<option selected value="${album.id}"></option>`);
    addSelectedAlbum();
}

function remove_related_category({ id_album }: { id_album: string }): void {
    const el = document.getElementById(id_album);
    if (el) el.parentElement?.remove();
}

function display_related_category(cat_id: string, cat_link_path: string): void {
    document.querySelector<HTMLElement>('.selected-categories-container')?.insertAdjacentHTML(
        'beforeend',
        `<div class="breadcrumb-item">
      <span class="link-path">${cat_link_path}</span><span id="${cat_id}" class="mcs-icon ${prefix_icon}cancel remove-item"></span>
    </div>`
    );
}

function updateFilters(filterName: string, mode: string): void {
    switch (filterName) {
        case 'word':
            if (mode == 'add') {
                global_params.fields.allwords = {};

                PS_params.allwords = '';
                PS_params.allwords_mode = 'AND';
                PS_params.allwords_fields = [];
            } else if (mode == 'del') {
                delete global_params.fields.allwords;

                delete PS_params.allwords;
                delete PS_params.allwords_mode;
                delete PS_params.allwords_fields;
            }
            break;

        case 'tag':
            if (mode == 'add') {
                global_params.fields.tags = {};

                PS_params.tags = '';
                PS_params.tags_mode = 'AND';
            } else if (mode == 'del') {
                delete global_params.fields.tags;

                delete PS_params.tags;
                delete PS_params.tags_mode;
            }
            break;

        case 'album':
            if (mode == 'add') {
                global_params.fields.cat = {};

                PS_params.categories = '';
                PS_params.categories_withsubs = false;
            } else if (mode == 'del') {
                delete global_params.fields.cat;

                delete PS_params.categories;
                delete PS_params.categories_withsubs;
            }
            break;

        case 'date_posted':
            if (mode == 'add') {
                global_params.fields['date_posted'] = {};
                global_params.fields.date_posted.preset = '';
                global_params.fields.date_posted.custom = [];

                PS_params.date_posted_preset = '';
                PS_params.date_posted_custom = [];
            } else if (mode == 'del') {
                delete global_params.fields.date_posted.preset;
                delete global_params.fields.date_posted.custom;

                delete PS_params.date_posted_preset;
                delete PS_params.date_posted_custom;
            }
            break;

        case 'date_created':
            if (mode == 'add') {
                global_params.fields['date_created'] = {};
                global_params.fields.date_created.preset = '';
                global_params.fields.date_created.custom = [];

                PS_params.date_created_preset = '';
                PS_params.date_created_custom = [];
            } else if (mode == 'del') {
                delete global_params.fields.date_created.preset;
                delete global_params.fields.date_created.custom;

                delete PS_params.date_created_preset;
                delete PS_params.date_created_custom;
            }
            break;

        case 'filesize':
            if (mode == 'add') {
                global_params.fields.filesize_min = '';
                global_params.fields.filesize_max = '';

                PS_params.filesize_min = '';
                PS_params.filesize_max = '';
            } else if (mode == 'del') {
                delete global_params.fields.filesize_min;
                delete global_params.fields.filesize_max;

                delete PS_params.filesize_min;
                delete PS_params.filesize_max;
            }
            break;

        case 'height':
            if (mode == 'add') {
                global_params.fields.height_min = '';
                global_params.fields.height_max = '';

                PS_params.height_min = '';
                PS_params.height_max = '';
            } else if (mode == 'del') {
                delete global_params.fields.height_min;
                delete global_params.fields.height_max;

                delete PS_params.height_min;
                delete PS_params.height_max;
            }
            break;

        case 'width':
            if (mode == 'add') {
                global_params.fields.width_min = '';
                global_params.fields.width_max = '';

                PS_params.width_min = '';
                PS_params.width_max = '';
            } else if (mode == 'del') {
                delete global_params.fields.width_min;
                delete global_params.fields.width_max;

                delete PS_params.width_min;
                delete PS_params.width_max;
            }
            break;

        default:
            if (mode == 'add') {
                global_params.fields[filterName] = {};

                PS_params[filterName] = '';
            } else if (mode == 'del') {
                delete global_params.fields[filterName];

                delete PS_params[filterName];
            }
            break;
    }
}

function reloadPage(url: string): void {
    window.location.href = url;
}

function updateDateFilters(selector: string): void {
    const ctx = document.querySelector<HTMLElement>(selector);
    if (!ctx) return;

    const inputYear = ctx.querySelector<HTMLInputElement>('.year_input input');
    const iconYear = ctx.querySelector<HTMLElement>('.year_input .mcs-icon');
    const allMonth = ctx.querySelectorAll<HTMLElement>('.months_container > *');
    let yearIsCheck = false;

    // check year
    // year state
    // check => check mark
    // uncheck with children checked => outline check mark
    // uncheck without children checked => hide
    if (inputYear && inputYear.checked) {
        console.log('state : Year is check');
        yearIsCheck = true;
        ctx.querySelectorAll<HTMLInputElement>(':not(:checked)').forEach((inp) =>
            inp.setAttribute('disabled', 'true')
        );
        if (iconYear) {
            iconYear.classList.remove('gallery-icon-check-outline', 'grey-icon');
            iconYear.classList.add('gallery-icon-checkmark');
            iconYear.style.display = '';
        }
    } else if (ctx.querySelectorAll(':checked').length) {
        console.log('state :  Year is uncheck but have children', ctx.querySelectorAll(':checked'));
        ctx.querySelectorAll<HTMLInputElement>(':is(input)').forEach((inp) =>
            inp.setAttribute('disabled', 'false')
        );
        if (iconYear) {
            iconYear.classList.remove('gallery-icon-checkmark');
            iconYear.classList.add('gallery-icon-check-outline', 'grey-icon');
            iconYear.style.display = '';
        }
    } else {
        console.log('state: Year is uncheck and doesnt have children');
        ctx.querySelectorAll<HTMLInputElement>(':is(input)').forEach((inp) =>
            inp.setAttribute('disabled', 'false')
        );
        if (iconYear) {
            iconYear.classList.remove('grey-icon');
            iconYear.style.display = 'none';
        }
    }

    // check month and his days
    // month state
    // check => check mark
    // uncheck with children check => outline check mark
    // uncheck with no children and year is checked => grey check mark
    // uncheck with no children => hide
    allMonth.forEach((monthEl) => {
        const monthInput = monthEl.querySelector<HTMLInputElement>('.month_input input');
        const iconMonth = monthEl.querySelector<HTMLElement>('.month_input .mcs-icon');
        const allDays = monthEl.querySelectorAll<HTMLElement>('.days_container > *');
        let monthIsChecked = false;

        if (monthInput && monthInput.checked) {
            monthIsChecked = true;
            allDays.forEach((dayEl) => {
                dayEl
                    .querySelectorAll<HTMLInputElement>(':not(:checked)')
                    .forEach((inp) => inp.setAttribute('disabled', 'true'));
            });
            if (iconMonth) {
                iconMonth.classList.remove('gallery-icon-check-outline', 'grey-icon');
                iconMonth.classList.add('gallery-icon-checkmark');
                iconMonth.style.display = '';
            }
        } else if (
            Array.from(allDays).some((dayEl) => dayEl.querySelectorAll(':checked').length > 0)
        ) {
            if (iconMonth) {
                iconMonth.classList.remove('gallery-icon-checkmark');
                iconMonth.classList.add('gallery-icon-check-outline', 'grey-icon');
                iconMonth.style.display = '';
            }
        } else if (yearIsCheck) {
            if (iconMonth) {
                iconMonth.classList.remove('gallery-icon-check-outline');
                iconMonth.classList.add('gallery-icon-checkmark', 'grey-icon');
                iconMonth.style.display = '';
            }
        } else {
            if (iconMonth) {
                iconMonth.classList.remove('grey-icon');
                iconMonth.style.display = 'none';
            }
        }

        // day state
        // check => check mark
        // uncheck with year or month checked => grey check mark
        // uncheck without year or month checked => hide
        allDays.forEach((dayEl) => {
            const inputDay = dayEl.querySelector<HTMLInputElement>('input');
            const iconDay = dayEl.querySelector<HTMLElement>('.mcs-icon');

            if (inputDay && inputDay.checked) {
                if (iconDay) {
                    iconDay.classList.remove('grey-icon');
                    iconDay.style.display = '';
                }
            } else if (monthIsChecked || yearIsCheck) {
                if (iconDay) {
                    iconDay.classList.add('grey-icon');
                    iconDay.style.display = '';
                }
            } else {
                if (iconDay) {
                    iconDay.classList.remove('grey-icon');
                    iconDay.style.display = 'none';
                }
            }
        });
    });
}

/**
 * Replace the filter_form elements if they exceed the window
 */
function resize_filter_form(): void {
    document.querySelectorAll<HTMLElement>('.form_mobile_arrow').forEach((el) => el.remove());
    document.querySelectorAll<HTMLElement>('.filter').forEach((filterEl) => {
        const window_width = window.innerWidth;
        const rect = filterEl.getBoundingClientRect();
        const left_distance = rect.left + window.scrollX;
        const filterForm = filterEl.querySelector<HTMLElement>('.filter-form');
        if (!filterForm) return;
        const filter_form_width = filterForm.offsetWidth;
        const too_left = left_distance + filterEl.offsetWidth - filter_form_width;
        const is_desktop = window.matchMedia('(min-width: 600px)').matches;
        filterForm.style.left = '0px';
        const margin_left = is_desktop ? 15 : 0;

        if (left_distance + filter_form_width > window_width) {
            const check_left = too_left < 0 ? Math.abs(too_left - margin_left) : 0;
            const mobile_marg = is_desktop ? 0 : 2;
            const replace_form_width =
                -filter_form_width + filterEl.offsetWidth + check_left - mobile_marg;
            filterForm.style.left = replace_form_width + 'px';
        }
        if (!is_desktop) {
            const left_arrow = left_distance + filterEl.offsetWidth / 2;
            filterForm.insertAdjacentHTML(
                'afterbegin',
                '<svg width="10" height="10" viewBox="0 0 14 14" class="form_mobile_arrow" style="left:' +
                    left_arrow +
                    'px"><polygon class="arrow-border" points="7,0 14,14 0,14"/><polygon class="arrow-fill" points="7,1 13.5,14 0.5,14"/></svg>'
            );
        }
    });
}

window.addEventListener('load', () => {
    resize_filter_form();
});
window.addEventListener('resize', () => {
    resize_filter_form();
});

document.querySelectorAll<HTMLElement>('.help-popin-search').forEach((el) => {
    el.addEventListener('click', () => {
        const modal = document.querySelector<HTMLElement>('#modalQuickSearch');
        if (modal) modal.style.display = '';
    });
});

document.querySelector<HTMLElement>('#closeModalQuickSearch')?.addEventListener('click', () => {
    const modal = document.querySelector<HTMLElement>('#modalQuickSearch');
    if (modal) modal.style.display = 'none';
});

export {};
