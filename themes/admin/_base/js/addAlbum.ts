import { config } from './config';

interface CategoryOption {
    id: number;
    name: string;
    fullname: string;
    global_rank: string | number;
    dir: string | null;
    nb_images: number;
    pos: number;
}

interface TomSelectInstance {
    options: Record<string, CategoryOption>;
    addOption(opt: CategoryOption): void;
    setValue(v: string): void;
    getValue(): string;
}

interface CategoriesCacheLike {
    attach(
        target: HTMLSelectElement,
        options: {
            default?: number;
            filter(this: HTMLSelectElement, cats: CategoryOption[]): CategoryOption[];
        }
    ): void;
}

type CachedSelect = HTMLSelectElement & {
    tomselect?: TomSelectInstance;
    _cache?: CategoriesCacheLike;
};

function pwgAddAlbum(
    buttonEl: HTMLElement,
    options: {
        filter?: (this: HTMLSelectElement, cats: CategoryOption[]) => CategoryOption[];
        afterSelect?: () => void;
    } = {}
): void {
    const popup = document.getElementById('addAlbumForm')!;
    const albumParentSel = popup.querySelector<CachedSelect>('[name="category_parent"]')!;
    const targetName = buttonEl.dataset['addAlbum'] ?? '';
    const targetSel = document.querySelector<CachedSelect>(`[name="${targetName}"]`)!;
    const cache = targetSel._cache;

    if (targetSel.tomselect === undefined) {
        throw new Error('pwgAddAlbum: target must use Tom Select');
    }
    if (cache === undefined) throw new Error('pwgAddAlbum: missing categories cache');

    // Build dialog wrapper
    let dialog: HTMLDialogElement | null = null;
    let initialized = false;

    function openDialog(): void {
        if (dialog === null) {
            dialog = document.createElement('dialog');
            dialog.style.cssText = 'width:650px;padding:0;border:none;background:transparent;';
            document.body.appendChild(dialog);
        }
        if (!dialog.contains(popup)) dialog.appendChild(popup);
        popup.style.display = '';
        dialog.showModal();
        onComplete();
    }

    function closeDialog(): void {
        dialog?.close();
        document.body.appendChild(popup);
        popup.style.display = '';
    }

    function init(): void {
        initialized = true;
        cache!.attach(albumParentSel, {
            default: 0,
            filter(this: HTMLSelectElement, categoriesIn: CategoryOption[]): CategoryOption[] {
                let categories = categoriesIn;
                categories.push({
                    id: 0,
                    name: '',
                    fullname: '------------',
                    global_rank: 0,
                    dir: null,
                    nb_images: 0,
                    pos: 0,
                });
                if (options.filter !== undefined)
                    categories = options.filter.call(this, categories);
                return categories;
            },
        });

        popup.querySelector<HTMLFormElement>('form')?.addEventListener('submit', (e) => {
            e.preventDefault();
            const albumParentTs = albumParentSel.tomselect;
            const parent_id = albumParentTs?.getValue() ?? '0';
            const nameInput = popup.querySelector<HTMLInputElement>('[name=category_name]');
            const name = nameInput?.value ?? '';
            const errEl = document.getElementById('categoryNameError');
            if (name === '') {
                if (errEl !== null) errEl.style.visibility = 'visible';
                return;
            }
            if (errEl !== null) errEl.style.visibility = 'hidden';

            const loadingEl = document.getElementById('albumCreationLoading');
            const creationBtns = document.querySelectorAll<HTMLElement>('.albumCreationButton');
            if (loadingEl !== null) loadingEl.style.display = 'inline-block';
            creationBtns.forEach((b) => {
                b.style.display = 'none';
            });

            void fetch(config.wsUrl + 'format=json', {
                method: 'POST',
                body: new URLSearchParams({
                    method: 'pwg.categories.add',
                    parent: String(parent_id),
                    name,
                }),
            })
                .then((r) => r.json() as Promise<{ result: { id: number } }>)
                .then((data) => {
                    if (loadingEl !== null) loadingEl.style.display = 'none';
                    creationBtns.forEach((b) => {
                        b.style.display = '';
                    });
                    closeDialog();

                    const newAlbum: CategoryOption = {
                        id: data.result.id,
                        name,
                        fullname: name,
                        global_rank: '0',
                        dir: null,
                        nb_images: 0,
                        pos: 0,
                    };
                    const parentTs = albumParentSel.tomselect!;
                    const targetTs = targetSel.tomselect!;

                    if (parent_id !== '0') {
                        const parent: CategoryOption | undefined =
                            parentTs.options[String(parent_id)];
                        if (parent !== undefined) {
                            newAlbum.fullname = parent.fullname + ' / ' + newAlbum.fullname;
                            newAlbum.global_rank = String(parent.global_rank) + '.1';
                            newAlbum.pos = parent.pos + 1;
                        }
                    }
                    targetTs.addOption(newAlbum);
                    targetTs.setValue(String(newAlbum.id));
                    parentTs.addOption(newAlbum);
                    if (options.afterSelect !== undefined) options.afterSelect();
                })
                .catch(() => {
                    if (loadingEl !== null) loadingEl.style.display = 'none';
                    creationBtns.forEach((b) => {
                        b.style.display = '';
                    });
                    alert('Error creating album');
                });
        });
    }

    function onComplete(): void {
        if (!initialized) init();
        const errEl = document.getElementById('categoryNameError');
        if (errEl !== null) errEl.style.visibility = 'hidden';
        const nameInput = popup.querySelector<HTMLInputElement>('[name=category_name]');
        if (nameInput !== null) {
            nameInput.value = '';
            nameInput.focus();
        }
        const parentTs = albumParentSel.tomselect;
        const targetTs = targetSel.tomselect;
        if (parentTs !== undefined && targetTs !== undefined) {
            const v = targetTs.getValue();
            parentTs.setValue(v !== '' ? v : '0');
        }
    }

    buttonEl.addEventListener('click', openDialog);
}

(window as unknown as Window & { pwgAddAlbum: typeof pwgAddAlbum }).pwgAddAlbum = pwgAddAlbum;
export {};
