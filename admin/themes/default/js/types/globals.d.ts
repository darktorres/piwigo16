// Smarty-injected window globals used by gallery/plugin JS (search_filters.inc.tpl)
declare const global_params: { fields: Record<string, unknown>; [k: string]: unknown };
declare const search_id: string;
declare const fullname_of_cat: Record<string | number, string>;
declare const prefix_icon: string;

interface Window {
    SwitchBox: { push: (link: string | Element, box: string | Element) => void };
    _pwgRatingAutoQueue: { push: (opts: unknown) => void };
    var_loop?: boolean;
    SPThumbsOpts?: { hMargin: number; rowHeight: number };
    toggleAddFilterDropdown?: () => void;
    selectGenerateDerivAll?: () => void;
    selectGenerateDerivNone?: () => void;
    selectDelDerivAll?: () => void;
    selectDelDerivNone?: () => void;
}

interface Document {
    _switchBoxQueue?: Array<string | Element>;
    _pwgRatingQueue?: unknown[];
}
declare const str_word_widget_label: string;
declare const str_tags_widget_label: string;
declare const str_album_widget_label: string;
declare const str_author_widget_label: string;
declare const str_added_by_widget_label: string;
declare const str_filetypes_widget_label: string;
declare const str_empty_search_top_alt: string;
declare const str_empty_search_bot_alt: string;
