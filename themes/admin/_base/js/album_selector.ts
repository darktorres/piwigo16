import { getPageData } from './page-data';
import { config } from './config';

interface AlbumSelectorPageData {
    str_album_modal_title: string;
    str_album_modal_placeholder: string;
    str_no_search_in_progress: string;
    str_root: string;
    str_root_album_select: string;
    str_album_selected: string;
    str_result_limit: string;
    str_album_found: string;
    str_albums_found: string;
    str_plus_albums_found: string;
    str_create_and_select: string;
    str_add_subcat_of: string;
    str_complete_name_field: string;
    str_an_error_has_occured: string;
}

declare function sprintf(fmt: string, ...args: any[]): string;

let activeAlbumSelector: AlbumSelector | null = null;

function setActiveAlbumSelector(selector: AlbumSelector): void {
    if (activeAlbumSelector && activeAlbumSelector !== selector) activeAlbumSelector.close();
    activeAlbumSelector = selector;
}

const sel = {
    addLinkedAlbum: () => document.getElementById('addLinkedAlbum') as HTMLElement,
    closeAlbumPopIn: () => document.getElementById('closeAlbumPopIn') as HTMLElement,
    searchInput: () => document.getElementById('search-input-ab') as HTMLInputElement,
    searchResult: () => document.getElementById('searchResult') as HTMLElement,
    limitReached: () => document.querySelector<HTMLElement>('.limitReached')!,
    iconCancelInput: () => document.querySelector<HTMLElement>('.search-cancel-linked-album'),
    iconSearchingSpin: () => document.querySelectorAll<HTMLElement>('.searching'),
    albumSelector: () => document.getElementById('linkedAlbumSelector') as HTMLElement,
    albumCreate: () => document.getElementById('linkedAlbumCreate') as HTMLElement,
    albumCheckBox: () => document.getElementById('album-create-check') as HTMLInputElement,
    linkedAddAlbum: () => document.getElementById('linkedAddAlbum') as HTMLElement,
    linkedModalTitle: () => document.getElementById('linkedModalTitle') as HTMLElement,
    linkedAlbumSwitch: () => document.getElementById('linkedAlbumSwitch') as HTMLElement,
    linkedAlbumSubTitle: () => document.getElementById('linkedAlbumSubtitle') as HTMLElement,
    linkedAddNewAlbum: () => document.getElementById('linkedAddNewAlbum') as HTMLElement,
    linkedAlbumInput: () => document.getElementById('linkedAlbumInput') as HTMLInputElement,
    putToRoot: () => document.querySelector<HTMLElement>('.put-to-root-container'),
    linkedAlbumCancel: () => document.getElementById('linkedAlbumCancel') as HTMLElement,
    linkedAddAlbumErrors: () => document.getElementById('linkedAddAlbumErrors') as HTMLElement,
    addAlbumErrors: () => document.querySelectorAll<HTMLElement>('.AddAlbumErrors'),
    putToRootBtn: () => document.getElementById('put-to-root') as HTMLElement,
    linkedAlbumPopInContainer: () =>
        document.querySelector<HTMLElement>('.linkedAlbumPopInContainer')!,
};

window.addEventListener('keypress', (e) => {
    if (document.getElementById('addLinkedAlbum')?.style.display !== 'none' && e.key === 'Enter') {
        e.preventDefault();
    }
});

class AlbumSelector {
    #in_admin_mode: boolean;
    #methodPwg: string;
    #limitParam: number;
    #isAlbumCreationChecked: boolean;
    #selectAlbum: (args: any) => void;
    #removeSelectedAlbum: (args: any) => void;
    #currentSelectedId: string;
    #searchCat: Record<string, any>;
    #cats: Record<string, any>;
    instanceId!: string;
    #selected_categories: any[];
    #show_root_btn: boolean;
    #put_to_root: boolean;
    #current_cat: any;
    #title: string;
    #searchPlaceholder: string;
    #loading_add: boolean;
    #abortController: AbortController | null = null;
    #str!: AlbumSelectorPageData;

    constructor({
        selectedCategoriesIds = [],
        selectAlbum = (() => {}) as any,
        removeSelectedAlbum = (() => {}) as any,
        showRootButton = false,
        adminMode = false,
        limitParam = 50,
        currentAlbumId = 0,
        modalTitle = '',
        modalSearchPlaceholder = '',
    }: {
        selectedCategoriesIds?: any[];
        selectAlbum?: any;
        removeSelectedAlbum?: any;
        showRootButton?: boolean;
        adminMode?: boolean;
        limitParam?: number;
        currentAlbumId?: any;
        modalTitle?: string;
        modalSearchPlaceholder?: string;
    } = {}) {
        this.#str = getPageData<AlbumSelectorPageData>('pwg-album-selector-data');
        this.instanceId = `AlbumSelector-${Math.random().toString(36).substring(2, 9)}`;
        this.#in_admin_mode = adminMode;
        this.#methodPwg = adminMode ? 'pwg.categories.getAdminList' : 'pwg.categories.getList';
        this.#limitParam = limitParam;
        this.#selected_categories = adminMode
            ? [...selectedCategoriesIds]
            : selectedCategoriesIds.map(String);
        this.#isAlbumCreationChecked = false;
        this.#cats = {};
        this.#searchCat = {};
        this.#selectAlbum = (args: any) =>
            (selectAlbum as any).call(null, {
                ...args,
                newSelectedAlbum: this.#newSelectedAlbum.bind(this),
                addSelectedAlbum: this.#addSelectedAlbum.bind(this),
                getSelectedAlbum: this.get_selected_albums.bind(this),
            });
        this.#removeSelectedAlbum = (args: any) =>
            (removeSelectedAlbum as any).call(null, {
                ...args,
                getSelectedAlbum: this.get_selected_albums.bind(this),
            });
        this.#currentSelectedId = '';
        this.#show_root_btn = showRootButton;
        this.#put_to_root = false;
        this.#current_cat = currentAlbumId;
        this.#title = modalTitle === '' ? this.#str.str_album_modal_title : modalTitle;
        this.#searchPlaceholder =
            modalSearchPlaceholder === ''
                ? this.#str.str_album_modal_placeholder
                : modalSearchPlaceholder;
        this.#loading_add = false;
        this.#init();
    }

    #init() {
        if (this.#in_admin_mode && this.#show_root_btn) {
            sel.linkedAlbumPopInContainer()?.classList.add('big');
        }
        if (!this.#show_root_btn) sel.putToRoot()?.remove();
        if (!this.#in_admin_mode) {
            sel.albumCreate()?.remove();
            sel.linkedAlbumSwitch()?.remove();
        }
    }

    open() {
        this.#open_album_selector();
    }

    close() {
        if (activeAlbumSelector === this) activeAlbumSelector = null;
        this.#close_album_selector();
    }

    remove_selected_album(id: any) {
        const idx = this.#selected_categories.indexOf(id);
        if (idx > -1) this.#selected_categories.splice(idx, 1);
        this.#removeSelectedAlbum({ id_album: id });
    }

    get_selected_albums() {
        return [...this.#selected_categories];
    }
    select_album(id: any) {
        this.#selected_categories.push(id.toString());
    }

    resetAll() {
        this.#selected_categories = [];
        if (this.#in_admin_mode) this.#hard_reset_album_selector();
        else this.#reset_album_selector();
    }

    hardUpdate(cats: any) {
        this.#selected_categories = cats;
    }

    #newSelectedAlbum() {
        this.#selected_categories =
            this.#show_root_btn && this.#put_to_root ? ['0'] : [this.#currentSelectedId];
    }

    #addSelectedAlbum() {
        this.#selected_categories.push(this.#currentSelectedId);
    }

    #on(el: EventTarget | null, event: string, handler: EventListenerOrEventListenerObject) {
        if (!el || !this.#abortController) return;
        el.addEventListener(event, handler, { signal: this.#abortController.signal });
    }

    #loadGeneralEvent() {
        this.#on(sel.closeAlbumPopIn(), 'click', () => this.#close_album_selector());

        this.#on(document, 'keyup', (e: Event) => {
            const ke = e as KeyboardEvent;
            const modal = sel.addLinkedAlbum();
            if (!modal || modal.style.display === 'none') return;
            if (ke.key === 'Escape') this.#close_album_selector();
            if (ke.key === 'Enter' && sel.linkedAddNewAlbum()?.style.display !== 'none') {
                sel.linkedAddNewAlbum()?.dispatchEvent(new MouseEvent('click'));
            }
        });

        const cancelIcon = sel.iconCancelInput();
        if (cancelIcon) {
            this.#on(cancelIcon, 'click', () => this.#reset_search_input(true));
        }

        this.#on(sel.searchInput(), 'keyup', () => {
            const searchValue = sel.searchInput().value;
            const cancelEl = sel.iconCancelInput();
            if (cancelEl) cancelEl.style.display = searchValue.length > 0 ? '' : 'none';
            this.#perform_albums_search(searchValue);
        });

        if (this.#in_admin_mode) {
            this.#on(sel.albumCheckBox(), 'change', (e: Event) => {
                this.#isAlbumCreationChecked = (e.currentTarget as HTMLInputElement).checked;
                this.#switch_album_creation();
            });
        }

        if (this.#show_root_btn) {
            this.#on(sel.putToRootBtn(), 'click', () => {
                if (!this.#selected_categories.includes('0')) {
                    sel.putToRootBtn().classList.add('notClickable');
                    this.#put_to_root = true;
                    this.#selectAlbum({ album: { id: 0, root: this.#str.str_root } });
                    this.#close_album_selector();
                }
            });
        }
    }

    #loadPickAlbumEvent() {
        const selector = this.#isAlbumCreationChecked
            ? '.prefill-results-item'
            : '.prefill-results-item.available';

        document.querySelectorAll<HTMLElement>(selector).forEach((el) => {
            this.#on(el, 'click', (e: Event) => {
                const cat_id = (e.currentTarget as HTMLElement).id;
                const cat = this.#cats[cat_id];
                if (this.#isAlbumCreationChecked) {
                    this.#switch_album_view(cat);
                } else {
                    this.#currentSelectedId = cat.id;
                    this.#selectAlbum({ album: cat });
                    this.#close_album_selector();
                }
            });
        });
    }

    #loadSubCatEvent() {
        document.querySelectorAll<HTMLElement>('.display-subcat').forEach((el) => {
            this.#on(el, 'click', (e: Event) => {
                const curr = e.currentTarget as HTMLElement;
                const cat_id = curr.id;
                const cat = this.#cats[cat_id];
                const subcatEl = document.getElementById('subcat-' + cat.id);
                if (curr.classList.contains('open')) {
                    curr.classList.remove('open');
                    if (subcatEl) subcatEl.style.display = 'none';
                } else if (subcatEl) {
                    curr.classList.add('open');
                    subcatEl.style.display = '';
                } else {
                    const spinner = document.querySelector<HTMLElement>(
                        `#${cat_id}.display-subcat`
                    );
                    spinner?.classList.replace('gallery-icon-up-open', 'gallery-icon-spin6');
                    spinner?.classList.add('animate-spin');
                    const container = document.querySelector(`#${cat_id}.search-result-item`);
                    if (container) {
                        container.insertAdjacentHTML(
                            'afterend',
                            `<div id="subcat-${cat_id}" class="search-result-subcat-item"></div>`
                        );
                    }
                    this.#prefill_search_subcats(cat_id).then(() => {
                        spinner?.classList.remove('gallery-icon-spin6', 'animate-spin');
                        spinner?.classList.add('gallery-icon-up-open');
                        curr.classList.add('open');
                        document.getElementById('subcat-' + cat.id)!.style.display = '';
                    });
                }
            });
        });
    }

    #loadFillResultEvent(tempSelect: any[]) {
        sel.searchResult()
            .querySelectorAll<HTMLElement>('.search-result-item')
            .forEach((el) => {
                this.#on(el, 'click', (e: Event) => {
                    const cat_id = (e.currentTarget as HTMLElement).id;
                    const cat = this.#searchCat[cat_id];
                    const formated_cat_id = this.#in_admin_mode ? cat.id : String(cat.id);
                    if (!tempSelect.includes(formated_cat_id)) {
                        this.#currentSelectedId = cat.id;
                        this.#selectAlbum({ album: cat });
                        this.#close_album_selector();
                    }
                });
            });
    }

    #setActive() {
        setActiveAlbumSelector(this);
    }

    #open_album_selector() {
        this.#abortController = new AbortController();
        this.#setActive();
        this.#loadGeneralEvent();

        if (this.#in_admin_mode) this.#hard_reset_album_selector();
        else this.#reset_album_selector();

        const rootBtn = sel.putToRootBtn();
        if (rootBtn) {
            if (this.#show_root_btn && !this.#selected_categories.includes('0')) {
                rootBtn.classList.remove('notClickable');
            } else {
                rootBtn.classList.add('notClickable');
            }
        }

        sel.linkedModalTitle().innerHTML = this.#title;
        sel.searchInput().setAttribute('placeholder', this.#searchPlaceholder);
        sel.addLinkedAlbum().style.display = '';
    }

    #close_album_selector() {
        this.#cats = {};
        this.#searchCat = {};
        this.#currentSelectedId = '';
        this.#put_to_root = false;
        this.#loading_add = false;
        this.#abortController?.abort();
        this.#abortController = null;
        sel.addLinkedAlbum().style.display = 'none';
    }

    #reset_album_selector() {
        this.#prefill_search();
        this.#reset_search_input(false);
        sel.limitReached().innerHTML = this.#str.str_no_search_in_progress;
        sel.albumSelector().style.display = '';
    }

    #hard_reset_album_selector() {
        sel.albumCreate().style.display = 'none';
        this.#hide_new_album_error();
        this.#reset_album_selector();
        sel.linkedAlbumInput().value = '';
        if (sel.albumCheckBox().checked) sel.albumCheckBox().click();
        sel.searchResult().style.display = '';
        sel.linkedAlbumSwitch().style.display = '';
    }

    #reset_search_input(prefill: boolean) {
        sel.searchInput().value = '';
        const lr = sel.limitReached();
        lr.style.display = '';
        lr.innerHTML = this.#str.str_no_search_in_progress;
        sel.searchResult().innerHTML = '';
        if (prefill) this.#prefill_search();
    }

    #switch_album_creation() {
        this.#reset_album_selector();
        if (this.#isAlbumCreationChecked) {
            sel.putToRoot()?.style.setProperty('display', 'none');
            sel.linkedModalTitle().style.display = 'none';
            sel.linkedModalTitle().innerHTML = this.#str.str_create_and_select;
            sel.linkedAddAlbum().style.display = '';
            sel.linkedModalTitle().style.display = '';
            this.#on(sel.linkedAddAlbum(), 'click', () => this.#switch_album_view('root'));
        } else {
            sel.putToRoot()?.style.setProperty('display', '');
            sel.linkedModalTitle().style.display = 'none';
            sel.linkedModalTitle().innerHTML = this.#title;
            sel.linkedModalTitle().style.display = '';
            sel.linkedAddAlbum().style.display = 'none';
        }
    }

    #switch_album_view(cat: any) {
        sel.albumSelector().style.display = 'none';
        sel.searchResult().style.display = 'none';
        sel.linkedAlbumSwitch().style.display = 'none';
        sel.albumCreate().style.display = '';

        sel.linkedAlbumSubTitle().innerHTML = sprintf(
            this.#str.str_add_subcat_of,
            cat === 'root' ? this.#str.str_root_album_select : cat.name
        );
        this.#on(sel.linkedAddNewAlbum(), 'click', () =>
            this.#add_new_album(cat === 'root' ? cat : cat.id)
        );
        this.#on(sel.linkedAlbumCancel(), 'click', () => this.#close_album_selector());
        this.#on(sel.linkedAlbumInput(), 'input', () => this.#hide_new_album_error());
    }

    #hide_new_album_error() {
        sel.addAlbumErrors().forEach((el) => {
            el.style.visibility = 'hidden';
        });
    }

    #show_new_album_error(text: any) {
        sel.linkedAddAlbumErrors().innerHTML = text;
        sel.addAlbumErrors().forEach((el) => {
            el.style.visibility = 'visible';
        });
    }

    #select_new_album_and_close(cat: any) {
        this.#currentSelectedId = cat.id;
        this.#selectAlbum({ album: cat });
        this.#close_album_selector();
    }

    #prefill_results(rank: any, cats: any[], limit: any) {
        const isCreationMode = this.#isAlbumCreationChecked;
        const iconAlbum = isCreationMode ? 'icon-add-album' : 'gallery-icon-plus-circled';
        const tempSelectedCat = this.#current_cat
            ? [...this.#selected_categories, this.#current_cat.toString()]
            : [...this.#selected_categories];

        this.#cats = { ...this.#cats, ...Object.fromEntries(cats.map((c: any) => [c.id, c])) };

        let display_div: HTMLElement;
        if (rank === 'root') {
            const sr = sel.searchResult();
            sr.innerHTML = '';
            display_div = sr;
        } else {
            display_div = document.getElementById('subcat-' + rank)!;
        }

        cats.forEach((cat: any) => {
            let subcat = '';
            if (cat.nb_categories > 0) {
                subcat = `<span id="${cat.id}" class="display-subcat gallery-icon-up-open"></span>`;
            }
            const isNotInSelectedCat = !tempSelectedCat.includes(cat.id);
            if (isCreationMode || isNotInSelectedCat) {
                display_div.insertAdjacentHTML(
                    'beforeend',
                    `<div class="search-result-item" id="${cat.id}">
                    ${subcat}
                    <div class="prefill-results-item available" id="${cat.id}">
                        <span class="search-result-path"><span class="search-result-path-name">${cat.name}</span></span>
                        <span id="${cat.id}" class="${iconAlbum} item-add"></span>
                    </div>
                </div>`
                );
            } else {
                display_div.insertAdjacentHTML(
                    'beforeend',
                    `<div class="search-result-item already-in" id="${cat.id}" title="${this.#str.str_album_selected}">
                    ${subcat}
                    <div class="prefill-results-item" id="${cat.id}">
                        <span class="search-result-path"><span class="search-result-path-name">${cat.name}</span></span>
                        <span id="${cat.id}" class="gallery-icon-plus-circled item-add notClickable" title="${this.#str.str_album_selected}"></span>
                    </div>
                </div>`
                );
            }

            if (rank !== 'root') {
                const item = document.querySelector<HTMLElement>(`#${rank}.search-result-item`);
                if (item) {
                    const ml = parseInt(getComputedStyle(item).marginLeft || '0') + 25;
                    const catItem = document.querySelector<HTMLElement>(
                        `#${cat.id}.search-result-item`
                    );
                    if (catItem) {
                        catItem.style.marginLeft = ml + 'px';
                        const pathEl = catItem.querySelector<HTMLElement>('.search-result-path');
                        if (pathEl) pathEl.style.maxWidth = 400 - ml - 80 + 'px';
                    }
                }
            }
        });

        this.#loadPickAlbumEvent();
        this.#loadSubCatEvent();

        if (limit.remaining_cats > 0) {
            display_div.insertAdjacentHTML(
                'beforeend',
                `<p class="and-more">${sprintf(this.#str.str_plus_albums_found, limit.limited_to, limit.total_cats)}</p>`
            );
        }
    }

    #fill_results(cats: any[]) {
        const iconAlbum = this.#isAlbumCreationChecked
            ? 'icon-add-album'
            : 'gallery-icon-plus-circled';
        const tempSelectedCat = this.#current_cat
            ? [...this.#selected_categories, this.#current_cat.toString()]
            : [...this.#selected_categories];

        this.#searchCat = Object.fromEntries(cats.map((c: any) => [c.id, c]));
        const sr = sel.searchResult();
        sr.innerHTML = '';

        cats.forEach((cat: any) => {
            const cat_name = this.#in_admin_mode ? cat.fullname : cat.name;
            sr.insertAdjacentHTML(
                'beforeend',
                `<div class='search-result-item' id="${cat.id}">
                <span class="search-result-path not-rtl">${this.#getEllipsisName(cat_name)}</span>
                <span id="${cat.id}" class="${iconAlbum} item-add"></span>
            </div>`
            );

            if (this.#isAlbumCreationChecked) {
                const catEl = sr.querySelector<HTMLElement>(`.search-result-item#${cat.id}`);
                if (catEl) this.#on(catEl, 'click', () => this.#switch_album_view(cat));
                return;
            }

            if (tempSelectedCat.includes(cat.id)) {
                sr.querySelector<HTMLElement>(
                    `.search-result-item #${cat.id}.item-add`
                )?.classList.add('notClickable');
                sr.querySelector<HTMLElement>(
                    `.search-result-item #${cat.id}.item-add`
                )?.setAttribute('title', this.#str.str_album_selected);
                sr.querySelector<HTMLElement>(`#${cat.id}.search-result-item`)?.classList.add(
                    'notClickable'
                );
                sr.querySelector<HTMLElement>(`#${cat.id}.search-result-item`)?.setAttribute(
                    'title',
                    this.#str.str_album_selected
                );
            }
        });

        if (!this.#isAlbumCreationChecked) this.#loadFillResultEvent(tempSelectedCat);
    }

    #getEllipsisName(str: string, length = 50): string {
        if (str.length <= length) return str;
        return '...' + str.slice(-length).trim();
    }

    #apiPost(params: Record<string, any>): Promise<any> {
        return fetch(config.wsUrl + 'format=json&method=' + this.#methodPwg, {
            method: 'POST',
            body: new URLSearchParams(
                Object.fromEntries(Object.entries(params).map(([k, v]) => [k, String(v)]))
            ),
        }).then((r) => r.json());
    }

    #prefill_search() {
        sel.iconSearchingSpin().forEach((el) => {
            el.style.display = '';
        });
        const params: any = {
            cat_id: 0,
            recursive: false,
            fullname: true,
            limit: this.#limitParam,
        };
        if (this.#in_admin_mode) params.additional_output = 'full_name_with_admin_links';

        this.#apiPost(params)
            .then((data) => {
                sel.iconSearchingSpin().forEach((el) => {
                    el.style.display = 'none';
                });
                this.#prefill_results('root', data.result.categories, data.result.limit);
            })
            .catch((e) => {
                sel.iconSearchingSpin().forEach((el) => {
                    el.style.display = 'none';
                });
                console.log('error:', e?.message);
            });
    }

    async #prefill_search_subcats(cat_id: any): Promise<void> {
        const params: any = { cat_id, recursive: false, limit: this.#limitParam };
        if (this.#in_admin_mode) params.additional_output = 'full_name_with_admin_links';

        return this.#apiPost(params)
            .then((data) => {
                const cats = data.result.categories.filter((c: any) => c.id != cat_id);
                this.#prefill_results(cat_id, cats, data.result.limit);
            })
            .catch((e) => console.log('prefill search error:', e));
    }

    #perform_albums_search(searchText: string) {
        if (searchText === '') {
            this.#reset_search_input(true);
            return;
        }
        const params: any = { cat_id: 0, recursive: true, fullname: true, search: searchText };
        if (this.#in_admin_mode) params.additional_output = 'full_name_with_admin_links';

        sel.iconSearchingSpin().forEach((el) => {
            el.style.display = '';
        });
        this.#apiPost(params)
            .then((raw) => {
                if (raw.stat !== 'ok') return;
                sel.iconSearchingSpin().forEach((el) => {
                    el.style.display = 'none';
                });
                const categories = raw.result.categories;
                this.#fill_results(categories);
                const lr = sel.limitReached();
                if (raw.result.limit_reached) {
                    lr.innerHTML = this.#str.str_result_limit.replace(
                        '%d',
                        String(categories.length)
                    );
                } else {
                    lr.innerHTML =
                        categories.length === 1
                            ? this.#str.str_album_found
                            : this.#str.str_albums_found.replace('%d', String(categories.length));
                }
            })
            .catch((e) => {
                sel.iconSearchingSpin().forEach((el) => {
                    el.style.display = 'none';
                });
                console.log(e?.message);
            });
    }

    #add_new_album(cat_id: any) {
        if (this.#loading_add) return;
        this.#loading_add = true;
        const cat_name = sel.linkedAlbumInput().value;
        const posInput = document.querySelector<HTMLInputElement>('input[name=position]:checked');
        const cat_position = posInput?.value;

        if (!cat_name || cat_name === '') {
            this.#show_new_album_error(this.#str.str_complete_name_field);
            this.#loading_add = false;
            return;
        }

        fetch(config.wsUrl + 'format=json&method=pwg.categories.add', {
            method: 'POST',
            body: new URLSearchParams({
                name: cat_name,
                parent: String(cat_id === 'root' ? 0 : +cat_id),
                ...(cat_position ? { position: cat_position } : {}),
            }),
        })
            .then((r) => r.json())
            .then((data) => {
                if (data.stat === 'ok') this.#get_album_by_id(data.result.id);
                else this.#show_new_album_error(this.#str.str_an_error_has_occured);
            })
            .catch(() => this.#show_new_album_error(this.#str.str_an_error_has_occured));
    }

    #get_album_by_id(cat_id: any) {
        fetch(
            `${config.wsUrl}format=json&method=pwg.categories.getAdminList&` +
                new URLSearchParams({
                    cat_id: String(cat_id),
                    additional_output: 'full_name_with_admin_links',
                })
        )
            .then((r) => r.json())
            .then((data) => {
                if (data.stat === 'ok') this.#select_new_album_and_close(data.result.categories[0]);
                else this.#show_new_album_error(this.#str.str_an_error_has_occured);
            })
            .catch(() => this.#show_new_album_error(this.#str.str_an_error_has_occured));
    }
}

export { AlbumSelector };
