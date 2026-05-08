import { mount, type AlbumTree, type TreeNode, type MoveInfo, type NodeData } from './album-tree';
import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import { getPageData } from './page-data';
import { config } from './config';

// ---------------------------------------------------------------------------
// Page-data interface — values injected by PHP via #pwg-page-data JSON block
// ---------------------------------------------------------------------------
interface AlbumsPageData {
    data: any[];
    pwg_token: string;
    openCat: number;
    nb_albums: number;
    light_album_manager: boolean;
    delay_autoOpen: number;
    x_nb_subcats: string;
    x_nb_images: string;
    x_nb_sub_photos: string;
    str_are_you_sure: string;
    str_yes_change_parent: string;
    str_no_change_parent: string;
    str_albs_drag_drop: string;
    delete_album_with_name: string;
    delete_album_with_subs: string;
    has_images_associated_outside: string;
    has_images_becomming_orphans: string;
    rename_item: string;
    str_add_album: string;
    str_edit_album: string;
    str_add_photo: string;
    str_visit_gallery: string;
    str_sort_order: string;
    str_delete_album: string;
    str_root_order: string;
    str_sub_album_order: string;
    str_album_name_empty: string;
    add_album_root_title: string;
    add_sub_album_of: string;
    tiptip_locked_album: string;
}

const pageData = getPageData<AlbumsPageData>();

// Template-provided constants
const {
    data,
    pwg_token,
    openCat,
    light_album_manager,
    delay_autoOpen,
    x_nb_subcats,
    x_nb_images,
    x_nb_sub_photos,
    str_are_you_sure,
    str_yes_change_parent,
    str_no_change_parent,
    str_albs_drag_drop,
    delete_album_with_name,
    delete_album_with_subs,
    has_images_associated_outside,
    has_images_becomming_orphans,
    rename_item,
    str_add_album,
    str_edit_album,
    str_add_photo,
    str_visit_gallery,
    str_sort_order,
    str_delete_album,
    str_root_order,
    str_sub_album_order,
    str_album_name_empty,
    add_album_root_title,
    add_sub_album_of,
    tiptip_locked_album,
} = pageData;

// nb_albums is template-provided but also mutated by updateTitleBadge()
let nb_albums = pageData.nb_albums;

// ---------------------------------------------------------------------------
// Module-level mutable state (was global via declare var)
// ---------------------------------------------------------------------------
let actions: any;
let catToEdit: any;
let cont: any;
let icon: any;
let id: any;
let moveParent: any;
let moveRank: any;
let nb_sub_cats: any;
let newAlbumName: any;
let newAlbumParent: any;
let newAlbumPosition: any;
let node: any;
let nodeToGo: any;
let oldParent: any;
let open_nodes: any;
let parentIsPrivate: any;
let parentOfDeletedNode: any;
let previous_parent: any;
let target: any;
let title: any;
let tmp: any;
let toggler: any;
let toggler_close: any;
let toggler_cont: any;
let toggler_open: any;
let waitingTimeout: any;

declare function sprintf(fmt: string, ...args: unknown[]): string;

const qs = <T extends HTMLElement = HTMLElement>(sel: string) => document.querySelector<T>(sel);
const qsa = <T extends HTMLElement = HTMLElement>(sel: string) =>
    Array.from(document.querySelectorAll<T>(sel));

// Set during DOMContentLoaded after the album-tree module mounts.
let tree: AlbumTree | null = null;

interface WsResponse<T = unknown> {
    stat?: string;
    result?: T;
    err?: string;
    message?: string;
}

function pwgPost<T = unknown>(
    method: string,
    data: Record<string, unknown>
): Promise<WsResponse<T>> {
    const body = new URLSearchParams();
    for (const [k, v] of Object.entries(data)) {
        if (Array.isArray(v))
            (v as unknown[]).forEach((item) => body.append(k + '[]', String(item)));
        else body.append(k, String(v ?? ''));
    }
    return fetch(`${config.wsUrl}format=json&method=${method}`, { method: 'POST', body }).then(
        (r) => r.json() as Promise<WsResponse<T>>
    );
}

function refreshTipTip() {
    tippy('.tiptip', { delay: [0, 0], duration: [200, 200] });
}

document.addEventListener('DOMContentLoaded', () => {
    const openUppercats = openCat === -1 ? [] : findAlbumById(data, openCat).uppercats.split(',');
    // Honour persisted-open ids so that nodes the user previously expanded
    // come back with their children already rendered (otherwise the saved
    // pwgtree-open class on the LI would have nothing inside it to show).
    const savedOpen: string[] = (() => {
        try {
            const raw = window.localStorage?.getItem('pwg_album_tree_open_admin_albums');
            return raw ? (JSON.parse(raw) as string[]) : [];
        } catch {
            return [];
        }
    })();
    const expandedIds = new Set<string>([...openUppercats.map(String), ...savedOpen.map(String)]);
    const new_data = data.map((a: any) => {
        const isExpanded = expandedIds.has(String(a.id));
        const al = { ...a, children: isExpanded ? a.children : [] };
        if (a.children) {
            al.load_on_demand = !isExpanded;
            al.haveChildren = a.children;
        }
        return al;
    });

    document
        .querySelector('h1')
        ?.insertAdjacentHTML('beforeend', `<span class='badge-number'>${nb_albums}</span>`);

    const treeEl = qs('.tree')!;

    tree = mount(treeEl, {
        data: new_data,
        dragAndDrop: true,
        saveStateKey: 'admin_albums',
        onCreateLi: createAlbumNode,
        onOpen: (n: TreeNode) => {
            const el = n.element?.querySelector<HTMLElement>(
                ':scope > .pwgtree-element .move-cat-toogler'
            );
            if (el) el.innerHTML = toggler_open;
        },
        onClose: (n: TreeNode) => {
            const el = n.element?.querySelector<HTMLElement>(
                ':scope > .pwgtree-element .move-cat-toogler'
            );
            if (el) el.innerHTML = toggler_close;
        },
        onMove: (moveInfo: MoveInfo, ev) => {
            ev.preventDefault();
            if (moveInfo.moved_node.status !== 'private') {
                parentIsPrivate = false;
                if (moveInfo.position === 'after')
                    parentIsPrivate = moveInfo.target_node.parent?.status === 'private';
                else if (moveInfo.position === 'inside')
                    parentIsPrivate = moveInfo.target_node.status === 'private';
                if (parentIsPrivate) {
                    if (
                        window.confirm(
                            str_are_you_sure.replace(/%s/g, String(moveInfo.moved_node.name)) +
                                '\n\n' +
                                str_yes_change_parent
                        )
                    ) {
                        makePrivateHierarchy(moveInfo.moved_node);
                        applyMove(moveInfo);
                    }
                } else {
                    applyMove(moveInfo);
                }
            } else {
                applyMove(moveInfo);
            }
        },
    });

    // Visual feedback: the tree element gets a "dragging" class during a
    // mousedown→mouseup, used by CSS in albums.tpl to dim non-target nodes.
    treeEl.addEventListener('mousedown', mouseState);
    treeEl.addEventListener('mouseup', mouseState);

    treeEl.addEventListener('click', (e: Event) => {
        const target = e.target as Element;

        // .move-cat-toogler
        const toggler_el = target.closest<HTMLElement>('.move-cat-toogler');
        if (toggler_el && tree) {
            const node_id = toggler_el.dataset['id'];
            const n = tree.getNodeById(node_id ?? null);
            if (!n) return;

            if (n.load_on_demand && Array.isArray(n.haveChildren)) loadOnDemand(n);

            if (tree.isNodeOpen(n)) tree.closeNode(n);
            else tree.openNode(n);
            return;
        }

        // .move-cat-order
        const orderEl = target.closest<HTMLElement>('.move-cat-order');
        if (orderEl && tree) {
            const node_id = orderEl.dataset['id'];
            const n = tree.getNodeById(node_id ?? null);
            if (n) {
                const popin = qs<HTMLElement>('.cat-move-order-popin');
                if (popin) popin.style.display = '';
                qs<HTMLElement>('.cat-move-order-popin .album-name')!.innerHTML = getPathNode(n);
                qs<HTMLInputElement>('.cat-move-order-popin input[name=id]')!.value = node_id!;
                qs<HTMLInputElement>('input[name=simpleAutoOrder]')?.setAttribute(
                    'value',
                    str_sub_album_order
                );
            }
            return;
        }

        // .move-cat-add
        const addEl = target.closest<HTMLElement>('.move-cat-add');
        if (addEl) {
            e.preventDefault();
            openAddAlbumPopIn(addEl.dataset['aid']);
            (qs<HTMLElement>('.AddAlbumSubmit') as any)['data-a-parent'] = addEl.dataset['aid'];
            qs<HTMLElement>('.AddAlbumSubmit')!.dataset['aParent'] = addEl.dataset['aid'] ?? '0';
            return;
        }

        // .move-cat-delete
        const deleteEl = target.closest<HTMLElement>('.move-cat-delete');
        if (deleteEl) {
            triggerDeleteAlbum(deleteEl.dataset['id']);
            return;
        }

        // .move-cat-title-container
        const titleEl = target.closest<HTMLElement>('.move-cat-title-container');
        if (titleEl) {
            openRenameAlbumPopIn(titleEl.querySelector<HTMLElement>('.move-cat-title')?.title);
            const submitBtn = qs<HTMLElement>('.RenameAlbumSubmit')!;
            submitBtn.dataset['catId'] = titleEl.dataset['id'];
            return;
        }
    });

    // .order-root
    qs('.order-root')?.addEventListener('click', () => {
        qs<HTMLElement>('.cat-move-order-popin')!.style.display = '';
        qs<HTMLElement>('.cat-move-order-popin .album-name')!.innerHTML =
            (window as any).str_root ?? 'root';
        qs<HTMLInputElement>('.cat-move-order-popin input[name=id]')!.value = '-1';
        qs<HTMLInputElement>('input[name=simpleAutoOrder]')?.setAttribute('value', str_root_order);
    });

    // RenameAlbum
    qsa('.RenameAlbumErrors').forEach((el) => {
        el.style.display = 'none';
    });
    qs('.CloseRenameAlbum')?.addEventListener('click', closeRenameAlbumPopIn);
    qs('.RenameAlbumCancel')?.addEventListener('click', closeRenameAlbumPopIn);
    qs('.RenameAlbumSubmit')?.addEventListener('click', () => {
        catToEdit = (qs<HTMLElement>('.RenameAlbumSubmit') as any).dataset['catId'];
        pwgPost('pwg.categories.setInfo', {
            category_id: catToEdit,
            name: qs<HTMLInputElement>('.RenameAlbumLabelUsername input')?.value ?? '',
        })
            .then(() => {
                const node_id = qs<HTMLElement>('#cat-' + catToEdit + ' .move-cat-toogler')
                    ?.dataset['id'];
                const n = tree?.getNodeById(node_id ?? null);
                const newName =
                    qs<HTMLInputElement>('.RenameAlbumLabelUsername input')?.value ?? '';
                if (n && tree) tree.updateNode(n, newName);
                closeRenameAlbumPopIn();
            })
            .catch(console.log);
    });

    // AddAlbum
    qsa('.AddAlbumErrors').forEach((el) => {
        el.style.display = 'none';
    });
    qsa('.DeleteAlbumErrors').forEach((el) => {
        el.style.display = 'none';
    });

    qs('.add-album-button')?.addEventListener('click', () => {
        openAddAlbumPopIn(0);
        qs<HTMLElement>('.AddAlbumSubmit')!.dataset['aParent'] = '0';
    });
    qs('.CloseAddAlbum')?.addEventListener('click', closeAddAlbumPopIn);
    qs('.AddAlbumCancel')?.addEventListener('click', closeAddAlbumPopIn);
    qs('.DeleteAlbumCancel')?.addEventListener('click', closeDeleteAlbumPopIn);

    qs('.AddAlbumSubmit')?.addEventListener('click', () => {
        qs<HTMLElement>('.AddAlbumSubmit')!.classList.add('notClickable');
        newAlbumName = qs<HTMLInputElement>('.AddAlbumLabelUsername input')?.value ?? '';
        newAlbumParent = qs<HTMLElement>('.AddAlbumSubmit')!.dataset['aParent'];
        newAlbumPosition = qs<HTMLInputElement>('input[name=position]:checked')?.value;

        pwgPost('pwg.categories.add', {
            name: newAlbumName,
            parent: newAlbumParent,
            position: newAlbumPosition,
        })
            .then((raw) => {
                const d = typeof raw === 'string' ? JSON.parse(raw) : raw;
                if (d.stat !== 'ok') {
                    qsa<HTMLElement>('.AddAlbumErrors').forEach((el) => {
                        el.textContent = str_album_name_empty;
                        el.style.display = '';
                    });
                    qs<HTMLElement>('.AddAlbumSubmit')!.classList.remove('notClickable');
                    return;
                }
                closeAddAlbumPopIn();
                updateTitleBadge(nb_albums + 1);
                qs<HTMLElement>('.AddAlbumSubmit')!.classList.remove('notClickable');
                if (!tree) return;
                const parent_node = tree.getNodeById(newAlbumParent);
                if (parent_node?.load_on_demand && Array.isArray(parent_node.haveChildren))
                    loadOnDemand(parent_node);
                if (parent_node) openNodeOnDemand(parent_node);
                const newData: NodeData = {
                    id: d.result.id,
                    isEmptyFolder: true,
                    name: newAlbumName,
                };
                if (newAlbumPosition === 'last') tree.appendNode(newData, parent_node);
                else tree.prependNode(newData, parent_node);
                if (parent_node) setSubcatsBadge(parent_node);
                const newNode = tree.getNodeById(d.result.id);
                if (newNode) goToNode(newNode, newNode);
                const newNodeEl = document.getElementById('cat-' + d.result.id);
                if (newNodeEl) {
                    window.scrollTo({
                        top:
                            newNodeEl.getBoundingClientRect().top +
                            window.scrollY -
                            screen.height / 2,
                        behavior: 'smooth',
                    });
                }
                refreshTipTip();
            })
            .catch(() => qs<HTMLElement>('.AddAlbumSubmit')!.classList.remove('notClickable'));
    });

    // Delete Album
    qs('.DeleteAlbumSubmit')?.addEventListener('click', () => {
        const deletionMode = qs<HTMLInputElement>('input[name=photo_deletion_mode]:checked')?.value;
        pwgPost('pwg.categories.delete', {
            category_id: id,
            photo_deletion_mode: deletionMode,
            pwg_token,
        })
            .then(() => {
                parentOfDeletedNode = node.parent;
                tree?.removeNode(node);
                updateTitleBadge(nb_albums - 1);
                // Root-level deletes have no parent in the new tree model.
                if (parentOfDeletedNode) setSubcatsBadge(parentOfDeletedNode);
                closeDeleteAlbumPopIn();
                refreshTipTip();
            })
            .catch(console.log);
    });

    // Selection checkboxes
    qsa('.user-list-checkbox').forEach((el) => {
        el.addEventListener('change', checkbox_change.bind(el));
        el.addEventListener('click', checkbox_click.bind(el));
    });

    if (openCat !== -1 && tree) {
        nodeToGo = tree.getNodeById(openCat);
        if (nodeToGo) goToNode(nodeToGo, nodeToGo);
        if (nodeToGo?.children?.length) tree.openNode(nodeToGo);
        const catEl = document.getElementById('cat-' + openCat);
        if (catEl) {
            const top =
                catEl.getBoundingClientRect().top +
                window.scrollY -
                window.innerHeight / 2 +
                catEl.offsetHeight / 2;
            window.scrollTo({ top, behavior: 'smooth' });
        }
    }

    if (!light_album_manager) refreshTipTip();
});

function mouseState(e: Event) {
    const treeEl = qs('.tree')!;
    if (e.type === 'mousedown') treeEl.classList.add('dragging');
    else if (e.type === 'mouseup') treeEl.classList.remove('dragging');
}

function createAlbumNode(node: TreeNode, li: HTMLElement) {
    const liEl = li;
    icon = "<span class='%icon%'></span>";
    title = '<span data-id="' + node.id + '" class="move-cat-title-container ';
    if (node.status === 'private' || node.parent?.status === 'private') {
        node.status = 'private';
        title += 'icon-lock';
    }
    title += '">';
    if (node.visible === 'false' || node.parent?.visble === 'false') {
        node.visble = 'false';
        title +=
            '<span class="tiptip icon-cone" title="' +
            tiptip_locked_album +
            '" style="font-size: 16px"></span>';
    }
    title +=
        '<p class="move-cat-title" title="' +
        node.name +
        '">' +
        node.name +
        '</p> <span class="icon-pencil"></span> </span>';
    toggler_cont = "<div class='move-cat-toogler' data-id=%id%>%content%</div>";
    toggler_close = "<span class='icon-left-open'></span>";
    toggler_open = "<span class='icon-down-open'></span>";
    actions =
        '<div class="move-cat-action-cont">' +
        "<div class='move-cat-action'>" +
        '<a class="move-cat-add icon-add-album tiptip" title="' +
        str_add_album +
        '" href="#" data-aid="' +
        node.id +
        '"></a>' +
        '<a class="move-cat-edit icon-pencil tiptip" title="' +
        str_edit_album +
        '" href="' +
        config.adminUrl +
        'page=album-' +
        node.id +
        '"></a>' +
        '<a class="move-cat-upload icon-plus-circled tiptip" title="' +
        str_add_photo +
        '" href="' +
        config.adminUrl +
        'page=photos_add&album=' +
        node.id +
        '"></a>' +
        '<a class="move-cat-see icon-eye tiptip" title="' +
        str_visit_gallery +
        '" href="index.php?/category/' +
        node.id +
        '"></a>' +
        '<a data-id="' +
        node.id +
        '" class="move-cat-order icon-sort-name-up tiptip" title="' +
        str_sort_order +
        '"></a>' +
        '<a data-id="' +
        node.id +
        '" class="move-cat-delete icon-trash tiptip" title="' +
        str_delete_album +
        '" ></a>' +
        '</div>' +
        '</div>';

    const contEl = liEl.querySelector<HTMLElement>('.pwgtree-element')!;
    contEl.classList.add('move-cat-container');
    contEl.id = 'cat-' + node.id;
    contEl.innerHTML = '';
    contEl.insertAdjacentHTML('beforeend', actions);
    cont = contEl; // keep global for compatibility

    if (node.haveChildren || node.children.length !== 0) {
        // The album-tree adds pwgtree-open / pwgtree-closed to the LI before
        // calling onCreateLi, so the open state is readable from the class.
        const isOpen = liEl.classList.contains('pwgtree-open');
        toggler = isOpen ? toggler_open : toggler_close;
        contEl.insertAdjacentHTML(
            'beforeend',
            toggler_cont.replace(/%content%/g, toggler).replace(/%id%/g, String(node.id))
        );
    } else {
        contEl.querySelector<HTMLElement>('.move-cat-order')?.classList.add('notClickable');
        contEl.insertAdjacentHTML(
            'beforeend',
            toggler_cont.replace(/%content%/g, toggler_close).replace(/%id%/g, String(node.id))
        );
        contEl.classList.add('disabledToggle');
    }

    contEl.insertAdjacentHTML('beforeend', icon.replace(/%icon%/g, 'icon-grip-vertical-solid'));
    contEl.querySelector<HTMLElement>('.icon-grip-vertical-solid')!.title = str_albs_drag_drop;

    if (node.haveChildren || node.children.length !== 0)
        contEl.insertAdjacentHTML('beforeend', icon.replace(/%icon%/g, 'icon-sitemap'));
    else contEl.insertAdjacentHTML('beforeend', icon.replace(/%icon%/g, 'icon-folder-open'));

    contEl.insertAdjacentHTML('beforeend', title.replace(/%name%/g, node.name));

    const colors = ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'];
    contEl
        .querySelectorAll<HTMLElement>('span.icon-folder-open, span.icon-sitemap')
        .forEach((el) => {
            el.classList.add(colors[Number(node.id) % 5], 'node-icon');
        });

    contEl
        .querySelector('.move-cat-title-container')
        ?.insertAdjacentHTML(
            'afterend',
            "<div class='badge-container'>" +
                "<i class='icon-blue icon-sitemap nb-subcats'>" +
                node.nb_subcats +
                '</i>' +
                "<i class='icon-purple icon-picture nb-images'>" +
                node.nb_images +
                '</i>' +
                "<i class='icon-green icon-imagefolder-01 nb-sub-photos'>" +
                node.nb_sub_photos +
                '</i>' +
                "<div class='badge-dropdown'>" +
                "<span class='icon-blue icon-sitemap nb-subcats'>" +
                x_nb_subcats.replace('%d', String(node.nb_subcats ?? '')) +
                '</span>' +
                "<span class='icon-purple icon-picture nb-images'>" +
                x_nb_images.replace('%d', String(node.nb_images ?? '')) +
                '</span>' +
                "<span class='icon-green icon-imagefolder-01 nb-sub-photos'>" +
                x_nb_sub_photos.replace('%d', String(node.nb_sub_photos ?? '')) +
                '</span>' +
                '</div></div>'
        );

    if (!node.nb_subcats)
        contEl.querySelector<HTMLElement>('.nb-subcats')?.style.setProperty('display', 'none');
    if (!node.nb_images)
        contEl.querySelector<HTMLElement>('.nb-images')?.style.setProperty('display', 'none');
    if (!node.nb_sub_photos)
        contEl.querySelector<HTMLElement>('.nb-sub-photos')?.style.setProperty('display', 'none');
    if (node.has_not_access)
        contEl.querySelector<HTMLElement>('.move-cat-see')?.classList.add('notClickable');
}

function checkbox_change(this: HTMLElement) {
    if (this.dataset['selected'] === '1') hide(this.querySelector('i'));
    else show(this.querySelector('i'));
}
function checkbox_click(this: HTMLElement) {
    if (this.dataset['selected'] === '1') {
        this.dataset['selected'] = '0';
        hide(this.querySelector('i'));
    } else {
        this.dataset['selected'] = '1';
        show(this.querySelector('i'));
    }
}
function show(el: Element | null | undefined) {
    if (el) (el as HTMLElement).style.display = '';
}
function hide(el: Element | null | undefined) {
    if (el) (el as HTMLElement).style.display = 'none';
}

function isNumeric(num: any) {
    return !isNaN(num);
}

function openAddAlbumPopIn(parentAlbumId: any) {
    const popin = document.getElementById('AddAlbum')!;
    if (parentAlbumId !== 0) {
        const parentName = String(tree?.getNodeById(parentAlbumId)?.name ?? '');
        qs<HTMLElement>('#AddAlbum .AddIconTitle span')!.innerHTML = add_sub_album_of.replace(
            '%s',
            parentName
        );
    } else {
        qs<HTMLElement>('#AddAlbum .AddIconTitle span')!.innerHTML = add_album_root_title;
    }
    // Inline 'block' overrides the #AddAlbum { display: none } CSS rule.
    popin.style.display = 'block';
    const input = qs<HTMLInputElement>('.AddAlbumLabelUsername .user-property-input')!;
    input.value = '';
    input.focus();
    input.addEventListener(
        'keyup',
        () => {
            if (input.value !== '')
                qsa<HTMLElement>('.AddAlbumErrors').forEach((el) => {
                    el.style.display = 'none';
                });
        },
        { once: false }
    );
    popin.addEventListener('keyup', function handler(e: Event) {
        const ke = e as KeyboardEvent;
        if (ke.key === 'Enter') qs<HTMLElement>('.AddAlbumSubmit')?.click();
        if (ke.key === 'Escape') {
            closeAddAlbumPopIn();
            popin.removeEventListener('keyup', handler);
        }
    });
}

function closeAddAlbumPopIn() {
    const popin = document.getElementById('AddAlbum');
    if (popin) {
        popin.style.display = 'none';
        qsa<HTMLElement>('.AddAlbumErrors').forEach((el) => {
            el.style.display = 'none';
        });
    }
}

function openRenameAlbumPopIn(replacedAlbumName: any) {
    const popin = document.getElementById('RenameAlbum')!;
    // Inline 'block' overrides the #RenameAlbum { display: none } CSS rule.
    popin.style.display = 'block';
    qs<HTMLElement>('.RenameAlbumTitle span')!.innerHTML = rename_item.replace(
        '%s',
        replacedAlbumName
    );
    const input = qs<HTMLInputElement>('.RenameAlbumLabelUsername .user-property-input')!;
    input.value = replacedAlbumName;
    input.focus();
    document.addEventListener('keypress', function handler(e: Event) {
        if ((e as KeyboardEvent).key === 'Enter') {
            qs<HTMLElement>('.RenameAlbumSubmit')?.click();
            document.removeEventListener('keypress', handler);
        }
    });
}

function closeRenameAlbumPopIn() {
    const popin = document.getElementById('RenameAlbum');
    if (popin) popin.style.display = 'none';
}

function triggerDeleteAlbum(cat_id: any) {
    pwgPost('pwg.categories.calculateOrphans', { category_id: cat_id })
        .then((raw) => {
            const orphanData = (typeof raw === 'string' ? JSON.parse(raw) : raw).result[0];
            if (orphanData.nb_images_recursive === 0) {
                qsa('.deleteAlbumOptions').forEach((el) => (el.style.display = 'none'));
            } else {
                qsa<HTMLElement>('.deleteAlbumOptions').forEach((el) => {
                    el.style.display = '';
                });
                if (orphanData.nb_images_associated_outside === 0) {
                    hide(document.getElementById('IMAGES_ASSOCIATED_OUTSIDE'));
                } else {
                    const el = qs<HTMLElement>('#IMAGES_ASSOCIATED_OUTSIDE .innerText')!;
                    el.innerHTML = has_images_associated_outside
                        .replace('%d', orphanData.nb_images_recursive)
                        .replace('%d', orphanData.nb_images_associated_outside);
                }
                if (orphanData.nb_images_becoming_orphan === 0) {
                    hide(document.getElementById('IMAGES_BECOMING_ORPHAN'));
                } else {
                    const el = qs<HTMLElement>('#IMAGES_BECOMING_ORPHAN .innerText')!;
                    el.innerHTML = has_images_becomming_orphans.replace(
                        '%d',
                        orphanData.nb_images_becoming_orphan
                    );
                }
            }
            openDeleteAlbumPopIn(cat_id);
        })
        .catch(console.log);
}

function openDeleteAlbumPopIn(cat_to_delete: any) {
    const popin = document.getElementById('DeleteAlbum')!;
    // Inline 'block' overrides the #DeleteAlbum { display: none } CSS rule.
    popin.style.display = 'block';
    node = tree?.getNodeById(cat_to_delete);
    const iconTitle = qs<HTMLElement>('.DeleteIconTitle span')!;
    if (!node) return;
    if (node.children.length === 0) {
        iconTitle.innerHTML = delete_album_with_name.replace('%s', node.name);
    } else {
        nb_sub_cats = 0;
        iconTitle.innerHTML = delete_album_with_subs
            .replace('%s', node.name)
            .replace('%d', getAllSubAlbumsFromNode(node, nb_sub_cats));
    }
    id = cat_to_delete;
}

function closeDeleteAlbumPopIn() {
    const popin = document.getElementById('DeleteAlbum');
    if (popin) popin.style.display = 'none';
}

function getAllSubAlbumsFromNode(n: any, nb: any) {
    nb = 0;
    if (n.children !== 0) {
        n.children.forEach((child: any) => {
            nb++;
            tmp = getAllSubAlbumsFromNode(child, nb);
            nb += tmp;
        });
    } else {
        return 0;
    }
    return nb;
}

function setSubcatsBadge(n: any) {
    const catEl = document.getElementById('cat-' + n.id);
    if (!catEl) return;
    const nbSubcats = catEl.querySelector<HTMLElement>('.nb-subcats');
    const badgeSubcats = catEl.querySelector<HTMLElement>('.badge-dropdown .nb-subcats');
    if (n.children.length !== 0) {
        if (nbSubcats) {
            nbSubcats.textContent = String(n.children.length);
            nbSubcats.style.display = '';
        }
        if (badgeSubcats) badgeSubcats.textContent = x_nb_subcats.replace('%d', n.children.length);
    } else {
        if (nbSubcats) nbSubcats.style.display = 'none';
    }
}

function updateTitleBadge(new_nb_albums: any) {
    nb_albums = new_nb_albums;
    qs('.badge-number')!.textContent = String(new_nb_albums);
}

function goToNode(n: any, firstNode: any) {
    if (n.parent) {
        goToNode(n.parent, firstNode);
        if (n !== firstNode) {
            tree?.openNode(n);
            const parentCatEl = document.getElementById('cat-' + n.parent.id);
            if (parentCatEl) {
                parentCatEl.style.display = '';
                parentCatEl.classList.add('imune');
            }
        }
    } else {
        tree?.openNode(n);
        const catEl = document.getElementById('cat-' + firstNode.id);
        if (catEl) catEl.classList.add('animateFocus');
        showNodeChildrens(firstNode);
    }
}

function showNodeChildrens(n: any) {
    if (n.children) {
        n.children.forEach((child: any) => {
            const childEl = document.getElementById('cat-' + child.id);
            if (childEl) childEl.classList.add('imune');
            showNodeChildrens(child);
        });
    }
}

// closeTree() helper removed — was unreferenced.

function getId(parent: TreeNode | null | undefined): number | string {
    // Root-level nodes have no parent in the new tree; jqtree had a synthetic
    // level-0 container with id 0 — surface the same value to keep API parity
    // with the existing PHP "parent=0 means root" convention.
    if (!parent) return 0;
    return parent.id;
}

function getRank(n: any, ignoreId: any = null): any {
    if (n.getPreviousSibling() !== null) {
        if (n.id !== ignoreId) return 1 + getRank(n.getPreviousSibling(), ignoreId);
        else return getRank(n.getPreviousSibling(), ignoreId);
    } else {
        return n.id !== ignoreId ? 1 : 0;
    }
}

function applyMove(event: MoveInfo) {
    waitingTimeout = setTimeout(() => {
        qs<HTMLElement>('.waiting-message')!.classList.add('visible');
    }, 500);
    id = event.moved_node.id;
    moveParent = null;
    moveRank = null;
    previous_parent = event.previous_parent;
    target = event.target_node;
    if (event.position === 'after') {
        if (getId(previous_parent) !== getId(target.parent)) moveParent = getId(target.parent);
        moveRank = getRank(target, id) + 1;
    } else if (event.position === 'inside') {
        if (getId(previous_parent) !== getId(target)) {
            moveParent = getId(target);
            const currentNode = tree?.getNodeById(moveParent);
            if (currentNode?.load_on_demand && Array.isArray(currentNode.haveChildren))
                loadOnDemand(currentNode);
        }
        moveRank = 1;
    } else if (event.position === 'before') {
        if (getId(previous_parent) !== getId(target.parent)) moveParent = getId(target.parent);
        moveRank = 1;
    }
    moveNode(id, moveRank, moveParent)
        .then(() => {
            event.do_move();
            clearTimeout(waitingTimeout);
            qs<HTMLElement>('.waiting-message')!.classList.remove('visible');
            if (previous_parent) setSubcatsBadge(previous_parent);
            const newParent = tree?.getNodeById(moveParent);
            if (newParent) setSubcatsBadge(newParent);
            refreshTipTip();
        })
        .catch((msg) => {
            console.log('An error has occurred: ' + msg);
            refreshTipTip();
        });
}

function moveNode(n: any, rank: any, parent: any): Promise<void> {
    return new Promise<void>((res, rej) => {
        if (parent !== null) changeParent(n, parent, rank).then(res).catch(rej);
        else if (rank !== null) changeRank(n, rank).then(res).catch(rej);
        else res();
    });
}

function changeParent(n: any, parent: any, rank: any): Promise<void> {
    oldParent = n.parent;
    return pwgPost('pwg.categories.move', { category_id: n, parent, pwg_token }).then((raw) => {
        const d = typeof raw === 'string' ? JSON.parse(raw) : raw;
        if (d.stat === 'ok') {
            void changeRank(n, rank);
            (d.result.updated_cats ?? []).forEach((cat: any) => {
                const treeNode = tree?.getNodeById(cat.cat_id);
                if (treeNode) {
                    treeNode.nb_sub_photos = cat.nb_sub_photos;
                    tree?.updateNode(treeNode, String(treeNode.name));
                }
            });
        } else {
            throw raw;
        }
    });
}

function changeRank(n: any, rank: any): Promise<void> {
    return pwgPost('pwg.categories.setRank', { category_id: n, rank }).then((raw) => {
        const d = typeof raw === 'string' ? JSON.parse(raw) : raw;
        if (d.stat !== 'ok') throw raw;
    });
}

function makePrivateHierarchy(n: any) {
    n.status = 'private';
    n.children.forEach((child: any) => makePrivateHierarchy(child));
}

function getPathNode(n: any): any {
    return n.parent.getLevel() !== 0 ? getPathNode(n.parent) + ' / ' + n.name : n.name;
}

function findAlbumById(a: any, id: any): any {
    for (const album of a) {
        if (album.id === id) return album;
        if (album.haveChildren?.length || album.children?.length) {
            const al = findAlbumById(album.haveChildren ?? album.children, id);
            if (al) return al;
        }
    }
    return null;
}

function loadOnDemand(n: TreeNode) {
    const formated: NodeData[] = (Array.isArray(n.haveChildren) ? n.haveChildren : []).map(
        (a: NodeData) => {
            const al: NodeData = { ...a, children: [] };
            if (a.children) {
                al.load_on_demand = true;
                al.haveChildren = a.children;
            }
            return al;
        }
    );
    tree?.loadData(formated, n);
    n.load_on_demand = false;
}

function openNodeOnDemand(n: TreeNode) {
    if (tree && !tree.isNodeOpen(n)) tree.openNode(n);
}

export {};
