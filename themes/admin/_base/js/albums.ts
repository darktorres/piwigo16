import { mount, type AlbumTree, type TreeNode, type MoveInfo, type NodeData } from './album-tree';
import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import { getPageData } from './page-data';
import { config } from './config';

// ---------------------------------------------------------------------------
// Page-data interface — values injected by PHP via #pwg-page-data JSON block
// ---------------------------------------------------------------------------
interface AlbumsPageData {
    data: NodeData[];
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
    delay_autoOpen: _delay_autoOpen,
    x_nb_subcats,
    x_nb_images,
    x_nb_sub_photos,
    str_are_you_sure,
    str_yes_change_parent,
    str_no_change_parent: _str_no_change_parent,
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
let actions: string;
let catToEdit: string | undefined;
let icon: string;
let id: string | number | undefined;
let moveParent: number | string | null;
let moveRank: number | null;
let nb_sub_cats: number;
let newAlbumName: string;
let newAlbumParent: string | undefined;
let newAlbumPosition: string | undefined;
let node: TreeNode | null | undefined;
let nodeToGo: TreeNode | null;
let _oldParent: TreeNode | null;
let _parentIsPrivate: boolean;
let parentOfDeletedNode: TreeNode | null;
let previous_parent: TreeNode | null;
let target: TreeNode;
let title: string;
let tmp: number;
let toggler: string;
let toggler_close: string;
let toggler_cont: string;
let toggler_open: string;
let waitingTimeout: ReturnType<typeof setTimeout>;

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
    const stringify = (val: unknown): string => {
        if (val === null || val === undefined) return '';
        if (typeof val === 'string') return val;
        if (typeof val === 'number' || typeof val === 'boolean') return String(val);
        return JSON.stringify(val);
    };
    const body = new URLSearchParams();
    for (const [k, v] of Object.entries(data)) {
        if (Array.isArray(v))
            (v as unknown[]).forEach((item) => body.append(k + '[]', stringify(item)));
        else body.append(k, stringify(v));
    }
    return fetch(`${config.wsUrl}format=json&method=${method}`, { method: 'POST', body }).then(
        (r) => r.json() as Promise<WsResponse<T>>
    );
}

function refreshTipTip() {
    tippy('.tiptip', { delay: [0, 0], duration: [200, 200] });
}

document.addEventListener('DOMContentLoaded', () => {
    const openCatNode = openCat === -1 ? null : findAlbumById(data, openCat);
    const openUppercats: string[] =
        openCatNode !== null && typeof openCatNode.uppercats === 'string'
            ? openCatNode.uppercats.split(',')
            : [];
    // Honour persisted-open ids so that nodes the user previously expanded
    // come back with their children already rendered (otherwise the saved
    // pwgtree-open class on the LI would have nothing inside it to show).
    const savedOpen: string[] = (() => {
        try {
            const raw = window.localStorage.getItem('pwg_album_tree_open_admin_albums');
            return raw !== null && raw !== '' ? (JSON.parse(raw) as string[]) : [];
        } catch {
            return [];
        }
    })();
    const expandedIds = new Set<string>([...openUppercats, ...savedOpen]);
    const new_data: NodeData[] = data.map((a) => {
        const isExpanded = expandedIds.has(String(a.id));
        const al: NodeData = { ...a, children: isExpanded ? a.children : [] };
        if (a.children !== undefined) {
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
                let parentPrivate = false;
                if (moveInfo.position === 'after')
                    parentPrivate = moveInfo.target_node.parent?.status === 'private';
                else if (moveInfo.position === 'inside')
                    parentPrivate = moveInfo.target_node.status === 'private';
                _parentIsPrivate = parentPrivate;
                if (parentPrivate) {
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

            if (n.load_on_demand === true && Array.isArray(n.haveChildren)) loadOnDemand(n);

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
            openAddAlbumPopIn(addEl.dataset['aid'] ?? '');
            qs<HTMLElement>('.AddAlbumSubmit')!.dataset['aParent'] = addEl.dataset['aid'] ?? '0';
            return;
        }

        // .move-cat-delete
        const deleteEl = target.closest<HTMLElement>('.move-cat-delete');
        if (deleteEl) {
            triggerDeleteAlbum(deleteEl.dataset['id'] ?? '');
            return;
        }

        // .move-cat-title-container
        const titleEl = target.closest<HTMLElement>('.move-cat-title-container');
        if (titleEl) {
            openRenameAlbumPopIn(
                titleEl.querySelector<HTMLElement>('.move-cat-title')?.title ?? ''
            );
            const submitBtn = qs<HTMLElement>('.RenameAlbumSubmit')!;
            submitBtn.dataset['catId'] = titleEl.dataset['id'];
            return;
        }
    });

    // .order-root
    qs('.order-root')?.addEventListener('click', () => {
        qs<HTMLElement>('.cat-move-order-popin')!.style.display = '';
        qs<HTMLElement>('.cat-move-order-popin .album-name')!.innerHTML =
            (window as Window & { str_root?: string }).str_root ?? 'root';
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
        catToEdit = qs<HTMLElement>('.RenameAlbumSubmit')?.dataset['catId'];
        void pwgPost('pwg.categories.setInfo', {
            category_id: catToEdit,
            name: qs<HTMLInputElement>('.RenameAlbumLabelUsername input')?.value ?? '',
        })
            .then(() => {
                const node_id = qs<HTMLElement>('#cat-' + (catToEdit ?? '') + ' .move-cat-toogler')
                    ?.dataset['id'];
                const n = tree?.getNodeById(node_id ?? null);
                const newName =
                    qs<HTMLInputElement>('.RenameAlbumLabelUsername input')?.value ?? '';
                if (n !== null && n !== undefined && tree !== null) tree.updateNode(n, newName);
                closeRenameAlbumPopIn();
            })
            .catch((e: unknown) => console.error(e));
    });

    // AddAlbum
    qsa('.AddAlbumErrors').forEach((el) => {
        el.style.display = 'none';
    });
    qsa('.DeleteAlbumErrors').forEach((el) => {
        el.style.display = 'none';
    });

    qs('.add-album-button')?.addEventListener('click', () => {
        openAddAlbumPopIn('0');
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

        void pwgPost<{ id: number }>('pwg.categories.add', {
            name: newAlbumName,
            parent: newAlbumParent,
            position: newAlbumPosition,
        })
            .then((d) => {
                if (d.stat !== 'ok' || d.result === undefined) {
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
                if (tree === null) return;
                const parent_node = tree.getNodeById(newAlbumParent);
                if (parent_node?.load_on_demand === true && Array.isArray(parent_node.haveChildren))
                    loadOnDemand(parent_node);
                if (parent_node !== null) openNodeOnDemand(parent_node);
                const newData: NodeData = {
                    id: d.result.id,
                    isEmptyFolder: true,
                    name: newAlbumName,
                };
                if (newAlbumPosition === 'last') tree.appendNode(newData, parent_node);
                else tree.prependNode(newData, parent_node);
                if (parent_node !== null) setSubcatsBadge(parent_node);
                const newNode = tree.getNodeById(d.result.id);
                if (newNode !== null) goToNode(newNode, newNode);
                const newNodeEl = document.getElementById('cat-' + String(d.result.id));
                if (newNodeEl !== null) {
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
        void pwgPost('pwg.categories.delete', {
            category_id: id,
            photo_deletion_mode: deletionMode,
            pwg_token,
        })
            .then(() => {
                if (node === null || node === undefined) return;
                parentOfDeletedNode = node.parent;
                tree?.removeNode(node);
                updateTitleBadge(nb_albums - 1);
                // Root-level deletes have no parent in the new tree model.
                if (parentOfDeletedNode !== null) setSubcatsBadge(parentOfDeletedNode);
                closeDeleteAlbumPopIn();
                refreshTipTip();
            })
            .catch((e: unknown) => console.error(e));
    });

    // Selection checkboxes
    qsa('.user-list-checkbox').forEach((el) => {
        el.addEventListener('change', checkbox_change.bind(el));
        el.addEventListener('click', checkbox_click.bind(el));
    });

    if (openCat !== -1) {
        nodeToGo = tree.getNodeById(openCat);
        if (nodeToGo !== null) {
            goToNode(nodeToGo, nodeToGo);
            if (nodeToGo.children.length > 0) tree.openNode(nodeToGo);
        }
        const catEl = document.getElementById('cat-' + String(openCat));
        if (catEl) {
            const top =
                catEl.getBoundingClientRect().top +
                window.scrollY -
                window.innerHeight / 2 +
                catEl.offsetHeight / 2;
            window.scrollTo({ top, behavior: 'smooth' });
        }
    }

    if (light_album_manager !== true) refreshTipTip();
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

    const hasChildren =
        (Array.isArray(node.haveChildren) && node.haveChildren.length > 0) ||
        node.children.length !== 0;
    if (hasChildren) {
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

    if (hasChildren)
        contEl.insertAdjacentHTML('beforeend', icon.replace(/%icon%/g, 'icon-sitemap'));
    else contEl.insertAdjacentHTML('beforeend', icon.replace(/%icon%/g, 'icon-folder-open'));

    contEl.insertAdjacentHTML('beforeend', title.replace(/%name%/g, node.name));

    const colors = ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'];
    contEl
        .querySelectorAll<HTMLElement>('span.icon-folder-open, span.icon-sitemap')
        .forEach((el) => {
            el.classList.add(colors[Number(node.id) % 5]!, 'node-icon');
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

    if (Number(node.nb_subcats ?? 0) === 0)
        contEl.querySelector<HTMLElement>('.nb-subcats')?.style.setProperty('display', 'none');
    if (Number(node.nb_images ?? 0) === 0)
        contEl.querySelector<HTMLElement>('.nb-images')?.style.setProperty('display', 'none');
    if (Number(node.nb_sub_photos ?? 0) === 0)
        contEl.querySelector<HTMLElement>('.nb-sub-photos')?.style.setProperty('display', 'none');
    if (node.has_not_access === true)
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

function openAddAlbumPopIn(parentAlbumId: string | number) {
    const popin = document.getElementById('AddAlbum')!;
    if (parentAlbumId !== 0 && parentAlbumId !== '0') {
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

function openRenameAlbumPopIn(replacedAlbumName: string) {
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

interface OrphanInfo {
    nb_images_recursive: number;
    nb_images_associated_outside: number;
    nb_images_becoming_orphan: number;
}

function triggerDeleteAlbum(cat_id: string | number) {
    void pwgPost<OrphanInfo[]>('pwg.categories.calculateOrphans', { category_id: cat_id })
        .then((raw) => {
            if (raw.result === undefined) return;
            const orphanData = raw.result[0];
            if (orphanData === undefined) return;
            if (orphanData.nb_images_recursive === 0) {
                qsa<HTMLElement>('.deleteAlbumOptions').forEach((el) => {
                    el.style.display = 'none';
                });
            } else {
                qsa<HTMLElement>('.deleteAlbumOptions').forEach((el) => {
                    el.style.display = '';
                });
                if (orphanData.nb_images_associated_outside === 0) {
                    hide(document.getElementById('IMAGES_ASSOCIATED_OUTSIDE'));
                } else {
                    const el = qs<HTMLElement>('#IMAGES_ASSOCIATED_OUTSIDE .innerText')!;
                    el.innerHTML = has_images_associated_outside
                        .replace('%d', String(orphanData.nb_images_recursive))
                        .replace('%d', String(orphanData.nb_images_associated_outside));
                }
                if (orphanData.nb_images_becoming_orphan === 0) {
                    hide(document.getElementById('IMAGES_BECOMING_ORPHAN'));
                } else {
                    const el = qs<HTMLElement>('#IMAGES_BECOMING_ORPHAN .innerText')!;
                    el.innerHTML = has_images_becomming_orphans.replace(
                        '%d',
                        String(orphanData.nb_images_becoming_orphan)
                    );
                }
            }
            openDeleteAlbumPopIn(cat_id);
        })
        .catch((e: unknown) => console.error(e));
}

function openDeleteAlbumPopIn(cat_to_delete: string | number) {
    const popin = document.getElementById('DeleteAlbum')!;
    // Inline 'block' overrides the #DeleteAlbum { display: none } CSS rule.
    popin.style.display = 'block';
    node = tree?.getNodeById(cat_to_delete) ?? null;
    const iconTitle = qs<HTMLElement>('.DeleteIconTitle span')!;
    if (node === null) return;
    if (node.children.length === 0) {
        iconTitle.innerHTML = delete_album_with_name.replace('%s', node.name);
    } else {
        nb_sub_cats = 0;
        iconTitle.innerHTML = delete_album_with_subs
            .replace('%s', node.name)
            .replace('%d', String(getAllSubAlbumsFromNode(node, nb_sub_cats)));
    }
    id = cat_to_delete;
}

function closeDeleteAlbumPopIn() {
    const popin = document.getElementById('DeleteAlbum');
    if (popin) popin.style.display = 'none';
}

function getAllSubAlbumsFromNode(n: TreeNode, _nb: number): number {
    let nb = 0;
    if (n.children.length !== 0) {
        n.children.forEach((child) => {
            nb++;
            tmp = getAllSubAlbumsFromNode(child, nb);
            nb += tmp;
        });
    } else {
        return 0;
    }
    return nb;
}

function setSubcatsBadge(n: TreeNode) {
    const catEl = document.getElementById('cat-' + String(n.id));
    if (catEl === null) return;
    const nbSubcats = catEl.querySelector<HTMLElement>('.nb-subcats');
    const badgeSubcats = catEl.querySelector<HTMLElement>('.badge-dropdown .nb-subcats');
    if (n.children.length !== 0) {
        if (nbSubcats) {
            nbSubcats.textContent = String(n.children.length);
            nbSubcats.style.display = '';
        }
        if (badgeSubcats)
            badgeSubcats.textContent = x_nb_subcats.replace('%d', String(n.children.length));
    } else {
        if (nbSubcats) nbSubcats.style.display = 'none';
    }
}

function updateTitleBadge(new_nb_albums: number) {
    nb_albums = new_nb_albums;
    qs('.badge-number')!.textContent = String(new_nb_albums);
}

function goToNode(n: TreeNode, firstNode: TreeNode) {
    if (n.parent !== null) {
        goToNode(n.parent, firstNode);
        if (n !== firstNode) {
            tree?.openNode(n);
            const parentCatEl = document.getElementById('cat-' + String(n.parent.id));
            if (parentCatEl !== null) {
                parentCatEl.style.display = '';
                parentCatEl.classList.add('imune');
            }
        }
    } else {
        tree?.openNode(n);
        const catEl = document.getElementById('cat-' + String(firstNode.id));
        if (catEl !== null) catEl.classList.add('animateFocus');
        showNodeChildrens(firstNode);
    }
}

function showNodeChildrens(n: TreeNode) {
    n.children.forEach((child) => {
        const childEl = document.getElementById('cat-' + String(child.id));
        if (childEl !== null) childEl.classList.add('imune');
        showNodeChildrens(child);
    });
}

// closeTree() helper removed — was unreferenced.

function getId(parent: TreeNode | null | undefined): number | string {
    // Root-level nodes have no parent in the new tree; jqtree had a synthetic
    // level-0 container with id 0 — surface the same value to keep API parity
    // with the existing PHP "parent=0 means root" convention.
    if (parent === null || parent === undefined) return 0;
    return parent.id;
}

function getRank(n: TreeNode, ignoreId: string | number | undefined = undefined): number {
    const prev = n.getPreviousSibling();
    if (prev !== null) {
        if (n.id !== ignoreId) return 1 + getRank(prev, ignoreId);
        else return getRank(prev, ignoreId);
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
            if (currentNode?.load_on_demand === true && Array.isArray(currentNode.haveChildren))
                loadOnDemand(currentNode);
        }
        moveRank = 1;
    } else {
        // 'before'
        if (getId(previous_parent) !== getId(target.parent)) moveParent = getId(target.parent);
        moveRank = 1;
    }
    moveNode(id, moveRank, moveParent)
        .then(() => {
            event.do_move();
            clearTimeout(waitingTimeout);
            qs<HTMLElement>('.waiting-message')!.classList.remove('visible');
            if (previous_parent !== null) setSubcatsBadge(previous_parent);
            const newParent = moveParent !== null ? (tree?.getNodeById(moveParent) ?? null) : null;
            if (newParent !== null) setSubcatsBadge(newParent);
            refreshTipTip();
        })
        .catch((msg: unknown) => {
            console.error('An error has occurred:', msg);
            refreshTipTip();
        });
}

function moveNode(
    n: string | number,
    rank: number | null,
    parent: string | number | null
): Promise<void> {
    return new Promise<void>((res, rej) => {
        if (parent !== null) changeParent(n, parent, rank).then(res).catch(rej);
        else if (rank !== null) changeRank(n, rank).then(res).catch(rej);
        else res();
    });
}

interface MoveResult {
    updated_cats?: Array<{ cat_id: number | string; nb_sub_photos: number }>;
}

function changeParent(
    n: string | number,
    parent: string | number,
    rank: number | null
): Promise<void> {
    _oldParent = null;
    return pwgPost<MoveResult>('pwg.categories.move', {
        category_id: n,
        parent,
        pwg_token,
    }).then((d) => {
        if (d.stat === 'ok' && d.result !== undefined) {
            if (rank !== null) void changeRank(n, rank);
            (d.result.updated_cats ?? []).forEach((cat) => {
                const treeNode = tree?.getNodeById(cat.cat_id);
                if (treeNode !== null && treeNode !== undefined) {
                    treeNode.nb_sub_photos = cat.nb_sub_photos;
                    tree?.updateNode(treeNode, String(treeNode.name));
                }
            });
        } else {
            throw new Error(d.message ?? 'move failed');
        }
    });
}

function changeRank(n: string | number, rank: number): Promise<void> {
    return pwgPost('pwg.categories.setRank', { category_id: n, rank }).then((d) => {
        if (d.stat !== 'ok') throw new Error(d.message ?? 'setRank failed');
    });
}

function makePrivateHierarchy(n: TreeNode) {
    n.status = 'private';
    n.children.forEach((child) => makePrivateHierarchy(child));
}

function getPathNode(n: TreeNode): string {
    return n.parent !== null && n.parent.getLevel() !== 0
        ? getPathNode(n.parent) + ' / ' + n.name
        : n.name;
}

function findAlbumById(a: NodeData[], id: number | string): NodeData | null {
    for (const album of a) {
        if (album.id === id) return album;
        const haveLen = Array.isArray(album.haveChildren) ? album.haveChildren.length : 0;
        const childrenLen = album.children?.length ?? 0;
        if (haveLen > 0 || childrenLen > 0) {
            const sublist =
                (Array.isArray(album.haveChildren) ? album.haveChildren : album.children) ?? [];
            const al = findAlbumById(sublist, id);
            if (al !== null) return al;
        }
    }
    return null;
}

function loadOnDemand(n: TreeNode) {
    const formated: NodeData[] = (Array.isArray(n.haveChildren) ? n.haveChildren : []).map((a) => {
        const al: NodeData = { ...a, children: [] };
        if (a.children !== undefined) {
            al.load_on_demand = true;
            al.haveChildren = a.children;
        }
        return al;
    });
    tree?.loadData(formated, n);
    n.load_on_demand = false;
}

function openNodeOnDemand(n: TreeNode) {
    if (tree && !tree.isNodeOpen(n)) tree.openNode(n);
}

export {};
