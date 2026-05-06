import { config } from './config';

function pwgAddAlbum(
    buttonEl: HTMLElement,
    options: {
        filter?: (this: HTMLSelectElement, cats: any[]) => any[];
        afterSelect?: () => void;
    } = {}
): void {
    const popup = document.getElementById('addAlbumForm')!;
    const albumParentSel = popup.querySelector<HTMLSelectElement>('[name="category_parent"]')!;
    const targetName = buttonEl.dataset['addAlbum'] ?? '';
    const targetSel = document.querySelector<HTMLSelectElement>(`[name="${targetName}"]`)!;
    const cache = (targetSel as any)['_cache'];

    if (targetSel && !(targetSel as any).tomselect) {
        throw new Error('pwgAddAlbum: target must use Tom Select');
    }
    if (!cache) throw new Error('pwgAddAlbum: missing categories cache');

    // Build dialog wrapper
    let dialog: HTMLDialogElement | null = null;
    let initialized = false;

    function openDialog() {
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.style.cssText = 'width:650px;padding:0;border:none;background:transparent;';
            document.body.appendChild(dialog);
        }
        if (!dialog.contains(popup)) dialog.appendChild(popup);
        popup.style.display = '';
        dialog.showModal();
        onComplete();
    }

    function closeDialog() {
        dialog?.close();
        document.body.appendChild(popup);
        popup.style.display = '';
    }

    function init() {
        initialized = true;
        cache.selectize(albumParentSel, {
            default: 0,
            filter(this: HTMLSelectElement, categories: any[]) {
                categories.push({ id: 0, fullname: '------------', global_rank: 0 });
                if (options.filter) categories = options.filter.call(this, categories);
                return categories;
            },
        });

        popup.querySelector<HTMLFormElement>('form')?.addEventListener('submit', (e) => {
            e.preventDefault();
            const albumParentTs = (albumParentSel as any).tomselect;
            const parent_id = albumParentTs?.getValue() ?? '0';
            const nameInput = popup.querySelector<HTMLInputElement>('[name=category_name]');
            const name = nameInput?.value ?? '';
            const errEl = document.getElementById('categoryNameError');
            if (!name) {
                if (errEl) errEl.style.visibility = 'visible';
                return;
            }
            if (errEl) errEl.style.visibility = 'hidden';

            const loadingEl = document.getElementById('albumCreationLoading');
            const creationBtns = document.querySelectorAll<HTMLElement>('.albumCreationButton');
            if (loadingEl) loadingEl.style.display = 'inline-block';
            creationBtns.forEach((b) => {
                b.style.display = 'none';
            });

            fetch(config.wsUrl + '?format=json', {
                method: 'POST',
                body: new URLSearchParams({
                    method: 'pwg.categories.add',
                    parent: String(parent_id),
                    name,
                }),
            })
                .then((r) => r.json())
                .then((data: any) => {
                    if (loadingEl) loadingEl.style.display = 'none';
                    creationBtns.forEach((b) => {
                        b.style.display = '';
                    });
                    closeDialog();

                    const newAlbum: any = {
                        id: data.result.id,
                        name,
                        fullname: name,
                        global_rank: '0',
                        dir: null,
                        nb_images: 0,
                        pos: 0,
                    };
                    const parentTs = (albumParentSel as any).tomselect;
                    const targetTs = (targetSel as any).tomselect;

                    if (parent_id !== 0 && parent_id !== '0') {
                        const parent = parentTs.options[String(parent_id)];
                        if (parent) {
                            newAlbum.fullname = parent.fullname + ' / ' + newAlbum.fullname;
                            newAlbum.global_rank = parent.global_rank + '.1';
                            newAlbum.pos = parent.pos + 1;
                        }
                    }
                    targetTs.addOption(newAlbum);
                    targetTs.setValue(String(newAlbum.id));
                    parentTs.addOption(newAlbum);
                    if (options.afterSelect) options.afterSelect();
                })
                .catch((_err) => {
                    if (loadingEl) loadingEl.style.display = 'none';
                    creationBtns.forEach((b) => {
                        b.style.display = '';
                    });
                    alert('Error creating album');
                });
        });
    }

    function onComplete() {
        if (!initialized) init();
        const errEl = document.getElementById('categoryNameError');
        if (errEl) errEl.style.visibility = 'hidden';
        const nameInput = popup.querySelector<HTMLInputElement>('[name=category_name]');
        if (nameInput) {
            nameInput.value = '';
            nameInput.focus();
        }
        const parentTs = (albumParentSel as any).tomselect;
        const targetTs = (targetSel as any).tomselect;
        if (parentTs && targetTs) parentTs.setValue(targetTs.getValue() || '0');
    }

    buttonEl.addEventListener('click', openDialog);
}

(window as any).pwgAddAlbum = pwgAddAlbum;
export {};
