interface AddAlbumOptions {
    filter?: (this: unknown, categories: CategoryItem[]) => CategoryItem[];
    afterSelect?: () => void;
}

interface CategoryItem {
    id: number | string;
    fullname: string;
    global_rank: string | number;
    dir?: string | null;
    nb_images?: number;
    pos?: number;
}

interface TomSelectInstance {
    getValue(): string;
    setValue(v: string | number | null): void;
    addOption(o: CategoryItem | CategoryItem[]): void;
    options: Record<string | number, CategoryItem>;
}

interface HTMLSelectWithTomSelect extends HTMLSelectElement {
    tomselect?: TomSelectInstance;
    _pwgCache?: { selectize: (el: HTMLElement | null, opts?: unknown) => void };
}

export function pwgAddAlbum(btn: HTMLElement, options: AddAlbumOptions = {}): HTMLElement {
    const popup = document.getElementById('addAlbumForm') as HTMLDialogElement | null;
    const albumParentEl = popup ? popup.querySelector<HTMLSelectWithTomSelect>('[name="category_parent"]') : null;
    const targetEl = btn ? document.querySelector<HTMLSelectWithTomSelect>('[name="' + btn.dataset.addAlbum + '"]') : null;
    const cache = targetEl ? (targetEl)._pwgCache : null;

    if (targetEl && !targetEl.tomselect) {
        throw new Error('pwgAddAlbum: target must use TomSelect');
    }
    if (!cache) {
        throw new Error('pwgAddAlbum: missing categories cache');
    }

    function init(): void {
        if (!popup || !albumParentEl) return;
        (popup as unknown as Record<string, unknown>)._init = true;

        cache!.selectize(albumParentEl, {
            default: 0,
            filter: function (this: unknown, categories: CategoryItem[]) {
                categories.push({ id: 0, fullname: '------------', global_rank: 0 });
                if (options.filter) {
                    categories = options.filter.call(this, categories);
                }
                return categories;
            },
        });

        popup.querySelector('form')?.addEventListener('submit', function (e) {
            e.preventDefault();

            const parent_id = albumParentEl.tomselect ? albumParentEl.tomselect.getValue() : albumParentEl.value;
            const nameEl = popup.querySelector<HTMLInputElement>('[name=category_name]');
            const name = nameEl?.value ?? '';

            const errEl = document.getElementById('categoryNameError');
            if (!name) {
                if (errEl) errEl.style.visibility = 'visible';
                return;
            }
            if (errEl) errEl.style.visibility = 'hidden';

            const formData = new FormData();
            formData.append('method', 'pwg.categories.add');
            formData.append('parent', parent_id);
            formData.append('name', name);

            const loadingEl = document.getElementById('albumCreationLoading');
            if (loadingEl) loadingEl.style.display = 'inline-block';
            document.querySelectorAll<HTMLElement>('.albumCreationButton').forEach(el => { el.style.display = 'none'; });

            fetch('ws.php?format=json', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(function (data: { result: { id: number } }) {
                    if (loadingEl) loadingEl.style.display = 'none';
                    document.querySelectorAll<HTMLElement>('.albumCreationButton').forEach(el => { el.style.display = ''; });
                    popup.close();

                    const parentSelectize = albumParentEl.tomselect!;
                    const newAlbum: CategoryItem = {
                        id: data.result.id,
                        name,
                        fullname: name,
                        global_rank: '0',
                        dir: null,
                        nb_images: 0,
                        pos: 0,
                    } as unknown as CategoryItem;

                    if (parent_id !== '0') {
                        const parent = parentSelectize.options[parent_id];
                        if (parent) {
                            (newAlbum as unknown as Record<string, unknown>).fullname = parent.fullname + ' / ' + name;
                            (newAlbum as unknown as Record<string, unknown>).global_rank = parent.global_rank + '.1';
                            (newAlbum as unknown as Record<string, unknown>).pos = (parent.pos ?? 0) + 1;
                        }
                    }

                    targetEl!.tomselect!.addOption(newAlbum);
                    targetEl!.tomselect!.setValue(newAlbum.id);
                    parentSelectize.addOption(newAlbum);

                    if (options.afterSelect) options.afterSelect();
                })
                .catch(function (err: unknown) {
                    if (loadingEl) loadingEl.style.display = 'none';
                    alert((err as Error).message ?? String(err));
                });
        });
    }

    btn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!(popup as unknown as Record<string, unknown>)._init) init();

        const errEl = document.getElementById('categoryNameError');
        if (errEl) errEl.style.visibility = 'hidden';
        const nameEl = popup?.querySelector<HTMLInputElement>('[name=category_name]');
        if (nameEl) { nameEl.value = ''; nameEl.focus(); }
        if (albumParentEl?.tomselect) {
            albumParentEl.tomselect.setValue(targetEl?.value ?? 0);
        }
        popup?.showModal();
    });

    return btn;
}
