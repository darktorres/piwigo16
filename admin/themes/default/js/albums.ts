import { initModule } from './moduleInit.js';
import tippy from 'tippy.js';
import { PwgTree, TreeNode, TreeNodeData } from './PwgTree.js';
import { pwgConfirm } from './pwgConfirm.js';

interface AlbumsConfig {
    data: TreeNodeData[];
    pwgToken: string;
    strShowSub: string;
    strHideSub: string;
    strManageSubAlbum: string;
    strApplyOrderRaw: string;
    strEdit: string;
    strAreYouSure: string;
    strYesChangeParent: string;
    strNoChangeParent: string;
    strRoot: string;
    openCat: string | number;
    nbAlbums: number;
    lightAlbumManager: boolean;
    xNbSubcats: string;
    xNbImages: string;
    xNbSubPhotos: string;
    delayAutoOpen: number;
    deleteAlbumWithName: string;
    deleteAlbumWithSubs: string;
    hasImagesAssociatedOutside: string;
    hasImagesBecomingOrphans: string;
    hasImagesRecursives: string;
    renameItem: string;
    strAddAlbum: string;
    strEditAlbum: string;
    strAddPhoto: string;
    strVisitGallery: string;
    strSortOrder: string;
    strDeleteAlbum: string;
    strRootOrder: string;
    strSubAlbumOrder: string;
    strAlbumNameEmpty: string;
    addAlbumRootTitle: string;
    addSubAlbumOf: string;
    tiptipLockedAlbum: string;
}

interface TreeNodeEvent extends Event { node: TreeNode; }
interface TreeMoveParent {
    id: string | number | undefined;
    children: TreeNode[];
    getLevel(): number;
}
interface TreeMoveInfo {
    moved_node: TreeNode;
    target_node: TreeNode;
    previous_parent: TreeMoveParent;
    position: 'before' | 'after' | 'inside';
    do_move: () => void;
}
interface TreeMoveEvent extends Event { move_info: TreeMoveInfo; }

interface AddAlbumResponse {
    stat: string;
    result: { id: string | number };
}

interface ApiStatResponse {
    stat: string;
    result: { updated_cats?: Array<{ cat_id: string | number; nb_sub_photos: number }> };
}

interface OrphanResult {
    nb_images_recursive: number;
    nb_images_associated_outside: number;
    nb_images_becoming_orphan: number;
}

let pwg_token = '';
let formatedData: TreeNodeData[] = [];
let str_show_sub = '', str_hide_sub = '', str_manage_sub_album = '', str_apply_order = '';
let str_edit = '', str_are_you_sure = '', str_yes_change_parent = '', str_no_change_parent = '', str_root = '';
let openCat: string | number = -1;
let nb_albums = 0;
let light_album_manager = false;
let x_nb_subcats = '', x_nb_images = '', x_nb_sub_photos = '';
let delay_autoOpen = 0;
let delete_album_with_name = '', delete_album_with_subs = '';
let has_images_associated_outside = '', has_images_becoming_orphans = '', has_images_recursives = '';
let rename_item = '';
let str_add_album = '', str_edit_album = '', str_add_photo = '', str_visit_gallery = '';
let str_sort_order = '', str_delete_album = '', str_root_order = '', str_sub_album_order = '';
let str_album_name_empty = '', add_album_root_title = '', add_sub_album_of = '';
let tiptip_locked_album = '';
let toggler_open = '';
let toggler_close = '';
let _addAlbumKeyupHandler: ((e: KeyboardEvent) => void) | null = null;
let _renameAlbumKeypressHandler: ((e: KeyboardEvent) => void) | null = null;
let _deleteAlbumClickHandler: (() => void) | null = null;

void str_show_sub; void str_hide_sub; void str_manage_sub_album; void str_apply_order;
void str_edit; void has_images_recursives; void str_are_you_sure;

export function init(cfg: AlbumsConfig): void {
    formatedData = cfg.data;
    pwg_token = cfg.pwgToken;
    str_show_sub = cfg.strShowSub;
    str_hide_sub = cfg.strHideSub;
    str_manage_sub_album = cfg.strManageSubAlbum;
    str_apply_order = cfg.strApplyOrderRaw.charAt(0).toUpperCase() + cfg.strApplyOrderRaw.slice(1);
    str_edit = cfg.strEdit;
    str_are_you_sure = cfg.strAreYouSure;
    str_yes_change_parent = cfg.strYesChangeParent;
    str_no_change_parent = cfg.strNoChangeParent;
    str_root = cfg.strRoot;
    openCat = cfg.openCat;
    nb_albums = cfg.nbAlbums;
    light_album_manager = cfg.lightAlbumManager;
    x_nb_subcats = cfg.xNbSubcats;
    x_nb_images = cfg.xNbImages;
    x_nb_sub_photos = cfg.xNbSubPhotos;
    delay_autoOpen = cfg.delayAutoOpen;
    delete_album_with_name = cfg.deleteAlbumWithName;
    delete_album_with_subs = cfg.deleteAlbumWithSubs;
    has_images_associated_outside = cfg.hasImagesAssociatedOutside;
    has_images_becoming_orphans = cfg.hasImagesBecomingOrphans;
    has_images_recursives = cfg.hasImagesRecursives;
    rename_item = cfg.renameItem;
    str_add_album = cfg.strAddAlbum;
    str_edit_album = cfg.strEditAlbum;
    str_add_photo = cfg.strAddPhoto;
    str_visit_gallery = cfg.strVisitGallery;
    str_sort_order = cfg.strSortOrder;
    str_delete_album = cfg.strDeleteAlbum;
    str_root_order = cfg.strRootOrder;
    str_sub_album_order = cfg.strSubAlbumOrder;
    str_album_name_empty = cfg.strAlbumNameEmpty;
    add_album_root_title = cfg.addAlbumRootTitle;
    add_sub_album_of = cfg.addSubAlbumOf;
    tiptip_locked_album = cfg.tiptipLockedAlbum;

    const h1 = document.querySelector('h1')!;
    const badge = document.createElement('span');
    badge.className = 'badge-number';
    h1.appendChild(badge);
    document.querySelector('h1 .badge-number')!.textContent = String(nb_albums);

    const treeEl = document.querySelector<HTMLElement>('.tree')!;

    const pwgTree = new PwgTree(treeEl, {
        data: formatedData,
        dragAndDrop: true,
        onCreateLi: createAlbumNode,
    });

    treeEl.addEventListener('click', function (e) {
        const toggler = (e.target as Element).closest<HTMLElement>('.move-cat-toggler');
        if (!toggler) return;
        const node_id = toggler.getAttribute('data-id')!;
        const node = pwgTree.getNodeById(node_id);
        if (node) {
            const open_nodes = pwgTree.getState().open_nodes;
            if (!open_nodes.includes(node_id)) {
                toggler.innerHTML = toggler_open;
                pwgTree.openNode(node);
            } else {
                toggler.innerHTML = toggler_close;
                pwgTree.closeNode(node);
            }
        }
    });

    treeEl.addEventListener('tree.open', function (e) {
        const treeEv = e as TreeNodeEvent;
        const el = document.querySelector<HTMLElement>('.move-cat-toggler[data-id="' + String(treeEv.node.id) + '"]');
        if (el) el.innerHTML = toggler_open;
    });

    treeEl.addEventListener('tree.close', function (e) {
        const treeEv = e as TreeNodeEvent;
        const el = document.querySelector<HTMLElement>('.move-cat-toggler[data-id="' + String(treeEv.node.id) + '"]');
        if (el) el.innerHTML = toggler_close;
    });

    treeEl.addEventListener('tree.move', function (e) {
        const event = { move_info: (e as TreeMoveEvent).move_info };

        if ((event.move_info.moved_node as TreeNode & { status?: string }).status != 'private') {
            let parentIsPrivate = false;
            if (event.move_info.position == 'after') {
                parentIsPrivate =
                    ((event.move_info.target_node.parent as TreeNode).status == 'private');
            } else if (event.move_info.position == 'inside') {
                parentIsPrivate =
                    (event.move_info.target_node.status == 'private');
            }

            if (parentIsPrivate) {
                pwgConfirm({
                    title: str_are_you_sure.replace(/%s/g, event.move_info.moved_node.name),
                    buttons: {
                        confirm: {
                            text: str_yes_change_parent,
                            btnClass: 'btn-red',
                            action: function () {
                                makePrivateHierarchy(event.move_info.moved_node);
                                applyMove(event, pwgTree);
                            },
                        },
                        cancel: {
                            text: str_no_change_parent,
                        },
                    },
                });
            } else {
                applyMove(event, pwgTree);
            }
        } else {
            applyMove(event, pwgTree);
        }
    });

    treeEl.addEventListener('click', function (e) {
        const orderBtn = (e.target as Element).closest<HTMLElement>('.move-cat-order');
        if (!orderBtn) return;
        const node_id = orderBtn.getAttribute('data-id')!;
        const node = pwgTree.getNodeById(node_id);
        if (node) {
            (document.querySelector<HTMLElement>('.cat-move-order-popin'))!.style.display = '';
            document.querySelector<HTMLElement>('.cat-move-order-popin .album-name')!.innerHTML = getPathNode(node);
            (document.querySelector<HTMLInputElement>('.cat-move-order-popin input[name=id]'))!.value = node_id;
            (document.querySelector<HTMLInputElement>('input[name=simpleAutoOrder]'))!.setAttribute('value', str_sub_album_order);
        }
    });

    document.querySelector<HTMLElement>('.order-root')!.addEventListener('click', function () {
        document.querySelector<HTMLElement>('.cat-move-order-popin')!.style.display = '';
        document.querySelector<HTMLElement>('.cat-move-order-popin .album-name')!.innerHTML = str_root;
        (document.querySelector<HTMLInputElement>('.cat-move-order-popin input[name=id]'))!.value = '-1';
        (document.querySelector<HTMLInputElement>('input[name=simpleAutoOrder]'))!.setAttribute('value', str_root_order);
    });

    treeEl.addEventListener('mousedown', function () {
        treeEl.classList.add('dragging');
    });
    treeEl.addEventListener('mouseup', function () {
        document.querySelectorAll<HTMLElement>('.dragging').forEach(function (el) {
            el.classList.remove('dragging');
        });
    });

    if (openCat != -1) {
        const nodeToGo = pwgTree.getNodeById(openCat)!;

        goToNode(nodeToGo, nodeToGo, pwgTree);
        if (nodeToGo.children) {
            pwgTree.openNode(nodeToGo);
        }

        let scrollElement: HTMLElement = document.documentElement;
        if (document.body.scrollHeight > document.documentElement.scrollHeight) {
            scrollElement = document.body;
        }
        const targetElement = document.getElementById('cat-' + String(openCat));
        if (targetElement) {
            scrollElement.scrollTop = targetElement.offsetTop;
        }
    }

    // RenameAlbumPopIn
    document.querySelector<HTMLElement>('.RenameAlbumErrors')!.style.display = 'none';
    document.querySelector<HTMLElement>('.move-cat-title-container')!.addEventListener('click', function (this: HTMLElement) {
        const titleEl = this.querySelector<HTMLElement>('.move-cat-title');
        openRenameAlbumPopIn(titleEl ? (titleEl.getAttribute('title') ?? '') : '');
        document.querySelector<HTMLElement & { dataset: DOMStringMap }>('.RenameAlbumSubmit')!.dataset.cat_id = this.getAttribute('data-id') ?? '';
    });
    document.querySelector<HTMLElement>('.CloseRenameAlbum')!.addEventListener('click', function () {
        closeRenameAlbumPopIn();
    });
    document.querySelector<HTMLElement>('.RenameAlbumCancel')!.addEventListener('click', function () {
        closeRenameAlbumPopIn();
    });

    document.querySelector<HTMLElement>('.RenameAlbumSubmit')!.addEventListener('click', function (this: HTMLElement) {
        const catToEdit = this.dataset.cat_id ?? '';
        fetch('ws.php?format=json&method=pwg.categories.setInfo', {
            method: 'POST',
            body: new URLSearchParams({
                category_id: catToEdit,
                name: (document.querySelector<HTMLInputElement>('.RenameAlbumLabelUsername input'))!.value,
            }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
        .then(function (response) { return response.text(); })
        .then(function (raw_data) {
            JSON.parse(raw_data);
            const node_id = document.getElementById('cat-' + catToEdit)!
                .querySelector<HTMLElement>('.move-cat-toggler')!
                .getAttribute('data-id')!;
            const node = pwgTree.getNodeById(node_id)!;
            const newName = (document.querySelector<HTMLInputElement>('.RenameAlbumLabelUsername input'))!.value;
            node.name = newName;
            pwgTree.updateNode(node, newName);

            rebindTitleContainers(pwgTree);
            rebindAddButtons(pwgTree);

            tippy('.tiptip', { delay: 0, placement: 'top' });
            closeRenameAlbumPopIn();
        })
        .catch(function (error) { console.log(error); });
    });

    // AddAlbumPopIn
    document.querySelector<HTMLElement>('.AddAlbumErrors')!.style.display = 'none';
    document.querySelector<HTMLElement>('.DeleteAlbumErrors')!.style.display = 'none';
    document.querySelector<HTMLElement>('.add-album-button')!.addEventListener('click', function () {
        openAddAlbumPopIn(0, pwgTree);
        document.querySelector<HTMLElement>('.AddAlbumSubmit')!.dataset.a_parent = '0';
    });
    rebindAddButtons(pwgTree);
    document.querySelector<HTMLElement>('.CloseAddAlbum')!.addEventListener('click', function () {
        closeAddAlbumPopIn();
    });
    document.querySelector<HTMLElement>('.AddAlbumCancel')!.addEventListener('click', function () {
        closeAddAlbumPopIn();
    });
    document.querySelector<HTMLElement>('.DeleteAlbumCancel')!.addEventListener('click', function () {
        closeDeleteAlbumPopIn();
    });

    document.querySelector<HTMLElement>('.AddAlbumSubmit')!.addEventListener('click', function (this: HTMLElement) {
        this.classList.add('notClickable');

        const newAlbumName = (document.querySelector<HTMLInputElement>('.AddAlbumLabelUsername input'))!.value;
        const newAlbumParent = this.dataset.a_parent ?? '0';
        const newAlbumPosition = (document.querySelector<HTMLInputElement>('input[name=position]:checked'))!.value;

        fetch('ws.php?format=json&method=pwg.categories.add', {
            method: 'POST',
            body: new URLSearchParams({
                name: newAlbumName,
                parent: newAlbumParent,
                position: newAlbumPosition,
            }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
        .then(function (response) { return response.text(); })
        .then(function (raw_data) {
            const responseData = JSON.parse(raw_data) as AddAlbumResponse;
            const parent_node = pwgTree.getNodeById(newAlbumParent);

            if (responseData.stat == 'ok') {
                if (newAlbumPosition == 'last') {
                    pwgTree.appendNode(
                        { id: responseData.result.id, isEmptyFolder: true, name: newAlbumName },
                        parent_node ?? undefined,
                    );
                } else {
                    pwgTree.prependNode(
                        { id: responseData.result.id, isEmptyFolder: true, name: newAlbumName },
                        parent_node ?? undefined,
                    );
                }

                if (parent_node) {
                    setSubcatsBadge(parent_node);

                    document.getElementById('cat-' + String(parent_node.id))!.addEventListener(
                        'click',
                        function (e) {
                            if ((e.target as Element).classList.contains('move-cat-toggler')) {
                                const node_id = String(parent_node.id);
                                const node = pwgTree.getNodeById(node_id);
                                if (node) {
                                    const open_nodes = pwgTree.getState().open_nodes;
                                    if (!open_nodes.includes(node_id)) {
                                        (e.target as HTMLElement).innerHTML = toggler_open;
                                        pwgTree.openNode(node);
                                    } else {
                                        (e.target as HTMLElement).innerHTML = toggler_close;
                                        pwgTree.closeNode(node);
                                    }
                                }
                            }
                        },
                    );
                }

                rebindAddButtons(pwgTree);
                rebindDeleteButtons(pwgTree);
                rebindTitleContainers(pwgTree);

                tippy('.tiptip', { delay: 0, placement: 'top' });

                updateTitleBadge(nb_albums + 1);

                const newNode = pwgTree.getNodeById(responseData.result.id)!;
                goToNode(newNode, newNode, pwgTree);

                const targetElement = document.getElementById('cat-' + String(responseData.result.id));
                if (targetElement) {
                    window.scrollTo(0, targetElement.offsetTop - window.innerHeight / 2);
                }

                closeAddAlbumPopIn();
                document.querySelector<HTMLElement>('.AddAlbumSubmit')!.classList.remove('notClickable');
            } else {
                document.querySelector<HTMLElement>('.AddAlbumErrors')!.textContent = str_album_name_empty;
                document.querySelector<HTMLElement>('.AddAlbumErrors')!.style.display = '';
                document.querySelector<HTMLElement>('.AddAlbumSubmit')!.classList.remove('notClickable');
            }
        })
        .catch(function (error) {
            console.log(error);
            document.querySelector<HTMLElement>('.AddAlbumSubmit')!.classList.remove('notClickable');
        });
    });

    // Delete Album
    rebindDeleteButtons(pwgTree);

    document.querySelectorAll<HTMLElement>('.user-list-checkbox').forEach(function (el) {
        el.removeEventListener('change', checkbox_change);
        el.addEventListener('change', checkbox_change);
        el.removeEventListener('click', checkbox_click);
        el.addEventListener('click', checkbox_click);
    });

    if (!light_album_manager) {
        tippy('.tiptip', { delay: 0, placement: 'top' });
    }
}

function rebindAddButtons(pwgTree: PwgTree): void {
    document.querySelectorAll<HTMLElement>('.move-cat-add').forEach(function (el) {
        el.addEventListener('click', function (this: HTMLElement) {
            openAddAlbumPopIn(this.dataset.aid ?? '0', pwgTree);
            document.querySelector<HTMLElement>('.AddAlbumSubmit')!.dataset.a_parent = this.dataset.aid ?? '0';
        });
    });
}

function rebindDeleteButtons(pwgTree: PwgTree): void {
    document.querySelectorAll<HTMLElement>('.move-cat-delete').forEach(function (el) {
        el.addEventListener('click', function (this: HTMLElement) {
            triggerDeleteAlbum(this.dataset.id ?? '', pwgTree);
        });
    });
}

function rebindTitleContainers(pwgTree: PwgTree): void {
    void pwgTree;
    document.querySelectorAll<HTMLElement>('.move-cat-title-container').forEach(function (el) {
        el.addEventListener('click', function (this: HTMLElement) {
            const titleEl = this.querySelector<HTMLElement>('.move-cat-title');
            openRenameAlbumPopIn(titleEl ? (titleEl.getAttribute('title') ?? '') : '');
            document.querySelector<HTMLElement>('.RenameAlbumSubmit')!.dataset.cat_id = this.getAttribute('data-id') ?? '';
        });
    });
}

function createAlbumNode(node: TreeNode, li: HTMLElement): void {
    const icon = "<span class='%icon%'></span>";
    let title = '<span data-id="' + String(node.id) + '" class="move-cat-title-container ';
    const parentNode = node.parent as TreeNode & { status?: string; visble?: string };
    if (node.status == 'private' || parentNode.status == 'private') {
        node.status = 'private';
        title += 'icon-lock';
    }
    title += '">';
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    if ((node as any).visible == 'false' || (parentNode as any).visble == 'false') {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        (node as any).visble = 'false';
        title +=
            '<span class="tiptip icon-cone" title="' +
            tiptip_locked_album +
            '" style="font-size: 16px"></span>';
    }
    title +=
        '<p class="move-cat-title" title="' +
        node.name +
        '">%name%</p> <span class="icon-pencil"></span> </span>';
    const toggler_cont = "<div class='move-cat-toggler' data-id=%id%>%content%</div>";
    toggler_close = "<span class='icon-left-open'></span>";
    toggler_open = "<span class='icon-down-open'></span>";
    const actions =
        '<div class="move-cat-action-cont">' +
        "<div class='move-cat-action'>" +
        '<a class="move-cat-add icon-add-album tiptip" title="' +
        str_add_album +
        '" href="#" data-aid="' +
        String(node.id) +
        '"></a>' +
        '<a class="move-cat-edit icon-pencil tiptip" title="' +
        str_edit_album +
        '" href="admin.php?page=album-' +
        String(node.id) +
        '"></a>' +
        '<a class="move-cat-upload icon-plus-circled tiptip" title="' +
        str_add_photo +
        '" href="admin.php?page=photos_add&album=' +
        String(node.id) +
        '"></a>' +
        '<a class="move-cat-see icon-eye tiptip" title="' +
        str_visit_gallery +
        '" href="index.php?/category/' +
        String(node.id) +
        '"></a>' +
        '<a data-id="' +
        String(node.id) +
        '" class="move-cat-order icon-sort-name-up tiptip" title="' +
        str_sort_order +
        '"></a>' +
        '<a data-id="' +
        String(node.id) +
        '" class="move-cat-delete icon-trash tiptip" title="' +
        str_delete_album +
        '" ></a>' +
        '</div>' +
        '</div>';

    const cont = li.querySelector<HTMLElement>('.jqtree-element')!;
    cont.classList.add('move-cat-container');
    cont.id = 'cat-' + String(node.id);
    cont.innerHTML = '';

    cont.insertAdjacentHTML('beforeend', actions);

    cont.querySelectorAll<HTMLElement>('.toggle-cat-option').forEach(function (el) {
        el.addEventListener('click', function () {
            document.querySelectorAll<HTMLElement>('.cat-option').forEach(function (opt) { opt.style.display = 'none'; });
            const options = (el as HTMLElement).querySelector<HTMLElement>('.cat-option');
            if (options) {
                options.style.display = options.style.display === 'none' ? '' : 'none';
            }
        });
    });

    if (node.children.length != 0) {
        cont.insertAdjacentHTML('beforeend',
            toggler_cont
                .replace(/%content%/g, toggler_close)
                .replace(/%id%/g, String(node.id)),
        );
    } else {
        const orderBtn = cont.querySelector<HTMLElement>('.move-cat-order');
        if (orderBtn) orderBtn.classList.add('notClickable');

        cont.insertAdjacentHTML('beforeend',
            toggler_cont
                .replace(/%content%/g, toggler_close)
                .replace(/%id%/g, String(node.id)),
        );
        cont.classList.add('disabledToggle');
    }

    cont.insertAdjacentHTML('beforeend', icon.replace(/%icon%/g, 'icon-grip-vertical-solid'));

    if (node.children.length != 0) {
        cont.insertAdjacentHTML('beforeend', icon.replace(/%icon%/g, 'icon-sitemap'));
    } else {
        cont.insertAdjacentHTML('beforeend', icon.replace(/%icon%/g, 'icon-folder-open'));
    }

    cont.insertAdjacentHTML('beforeend', title.replace(/%name%/g, node.name));

    const colors = ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'];
    const colorId = Number(node.id) % 5;
    cont.querySelectorAll<HTMLElement>('span.icon-folder-open, span.icon-sitemap').forEach(function (el) {
        el.classList.add(colors[colorId]);
        el.classList.add('node-icon');
    });

    const titleContainer = cont.querySelector<HTMLElement>('.move-cat-title-container');
    if (titleContainer) {
        titleContainer.insertAdjacentHTML('afterend',
            "<div class='badge-container'>" +
                "<i class='icon-blue icon-sitemap nb-subcats'>" +
                String(node.nb_subcats) +
                '</i>' +
                "<i class='icon-purple icon-picture nb-images'>" +
                String(node.nb_images) +
                '</i>' +
                "<i class='icon-green icon-imagefolder-01 nb-sub-photos'>" +
                String(node.nb_sub_photos) +
                '</i>' +
                "<div class='badge-dropdown'>" +
                "<span class='icon-blue icon-sitemap nb-subcats'>" +
                x_nb_subcats.replace('%d', String(node.nb_subcats)) +
                '</span>' +
                "<span class='icon-purple icon-picture nb-images'>" +
                x_nb_images.replace('%d', String(node.nb_images)) +
                '</span>' +
                "<span class='icon-green icon-imagefolder-01 nb-sub-photos'>" +
                x_nb_sub_photos.replace('%d', String(node.nb_sub_photos)) +
                '</span>' +
                '</div>' +
                '</div>',
        );
    }

    if (!node.nb_subcats) {
        cont.querySelectorAll<HTMLElement>('.nb-subcats').forEach(function (el) { el.style.display = 'none'; });
    }

    if (!(node.nb_images != 0 && node.nb_images)) {
        cont.querySelectorAll<HTMLElement>('.nb-images').forEach(function (el) { el.style.display = 'none'; });
    }

    if (!node.nb_sub_photos) {
        cont.querySelectorAll<HTMLElement>('.nb-sub-photos').forEach(function (el) { el.style.display = 'none'; });
    }

    if (node.has_not_access) {
        const seeBtn = cont.querySelector<HTMLElement>('.move-cat-see');
        if (seeBtn) seeBtn.classList.add('notClickable');
    }

    cont.setAttribute('draggable', 'true');
}

function checkbox_change(this: HTMLElement): void {
    if (this.getAttribute('data-selected') == '1') {
        const icon = this.querySelector<HTMLElement>('i');
        if (icon) icon.style.display = 'none';
    } else {
        const icon = this.querySelector<HTMLElement>('i');
        if (icon) icon.style.display = '';
    }
}

function checkbox_click(this: HTMLElement): void {
    if (this.getAttribute('data-selected') == '1') {
        this.setAttribute('data-selected', '0');
        const icon = this.querySelector<HTMLElement>('i');
        if (icon) icon.style.display = 'none';
    } else {
        this.setAttribute('data-selected', '1');
        const icon = this.querySelector<HTMLElement>('i');
        if (icon) icon.style.display = '';
    }
}

function openAddAlbumPopIn(parentAlbumId: string | number, pwgTree: PwgTree): void {
    if (String(parentAlbumId) !== '0') {
        document.querySelector<HTMLElement>('#AddAlbum .AddIconTitle span')!.innerHTML =
            add_sub_album_of.replace('%s', pwgTree.getNodeById(parentAlbumId)!.name);
    } else {
        document.querySelector<HTMLElement>('#AddAlbum .AddIconTitle span')!.innerHTML = add_album_root_title;
    }
    document.getElementById('AddAlbum')!.style.display = 'block';
    (document.querySelector<HTMLInputElement>('.AddAlbumLabelUsername .user-property-input'))!.value = '';
    (document.querySelector<HTMLInputElement>('.AddAlbumLabelUsername .user-property-input'))!.focus();

    if (_addAlbumKeyupHandler) {
        document.getElementById('AddAlbum')!.removeEventListener('keyup', _addAlbumKeyupHandler as EventListener);
    }
    _addAlbumKeyupHandler = function (e: KeyboardEvent) {
        if (e.keyCode === 13) {
            document.querySelector<HTMLElement>('.AddAlbumSubmit')!.click();
        }
        if (e.keyCode === 27) {
            closeAddAlbumPopIn();
        }
    };
    document.getElementById('AddAlbum')!.addEventListener('keyup', _addAlbumKeyupHandler as EventListener);
}

function closeAddAlbumPopIn(): void {
    document.getElementById('AddAlbum')!.style.display = 'none';
}

function openRenameAlbumPopIn(replacedAlbumName: string): void {
    document.getElementById('RenameAlbum')!.style.display = 'block';
    document.querySelector<HTMLElement>('.RenameAlbumTitle span')!.innerHTML =
        rename_item.replace('%s', replacedAlbumName);
    (document.querySelector<HTMLInputElement>('.RenameAlbumLabelUsername .user-property-input'))!.value = replacedAlbumName;
    (document.querySelector<HTMLInputElement>('.RenameAlbumLabelUsername .user-property-input'))!.focus();

    if (_renameAlbumKeypressHandler) {
        document.removeEventListener('keypress', _renameAlbumKeypressHandler as EventListener);
    }
    _renameAlbumKeypressHandler = function (e: KeyboardEvent) {
        if (e.which == 13) {
            document.querySelector<HTMLElement>('.RenameAlbumSubmit')!.click();
        }
    };
    document.addEventListener('keypress', _renameAlbumKeypressHandler as EventListener);
}

function closeRenameAlbumPopIn(): void {
    document.getElementById('RenameAlbum')!.style.display = 'none';
}

function triggerDeleteAlbum(cat_id: string, pwgTree: PwgTree): void {
    fetch('ws.php?format=json&method=pwg.categories.calculateOrphans?category_id=' + cat_id, {
        method: 'GET',
    })
    .then(function (response) { return response.text(); })
    .then(function (raw_data) {
        const orphanData = (JSON.parse(raw_data) as { result: OrphanResult[] }).result[0];
        if (orphanData.nb_images_recursive == 0) {
            document.querySelector<HTMLElement>('.deleteAlbumOptions')!.style.display = 'none';
        } else {
            document.querySelector<HTMLElement>('.deleteAlbumOptions')!.style.display = '';
            if (orphanData.nb_images_associated_outside == 0) {
                document.getElementById('IMAGES_ASSOCIATED_OUTSIDE')!.style.display = 'none';
            } else {
                document.querySelector<HTMLElement>('#IMAGES_ASSOCIATED_OUTSIDE .innerText')!.innerHTML = '';
                document.querySelector<HTMLElement>('#IMAGES_ASSOCIATED_OUTSIDE .innerText')!.appendChild(
                    document.createTextNode(
                        has_images_associated_outside
                            .replace('%d', String(orphanData.nb_images_recursive))
                            .replace('%d', String(orphanData.nb_images_associated_outside)),
                    ),
                );
            }
            if (orphanData.nb_images_becoming_orphan == 0) {
                document.getElementById('IMAGES_BECOMING_ORPHAN')!.style.display = 'none';
            } else {
                document.querySelector<HTMLElement>('#IMAGES_BECOMING_ORPHAN .innerText')!.innerHTML = '';
                document.querySelector<HTMLElement>('#IMAGES_BECOMING_ORPHAN .innerText')!.appendChild(
                    document.createTextNode(
                        has_images_becoming_orphans.replace('%d', String(orphanData.nb_images_becoming_orphan)),
                    ),
                );
            }
        }
        openDeleteAlbumPopIn(cat_id, pwgTree);
    })
    .catch(function (error) { console.log(error); });
}

function openDeleteAlbumPopIn(cat_to_delete: string, pwgTree: PwgTree): void {
    document.getElementById('DeleteAlbum')!.style.display = 'block';
    const node = pwgTree.getNodeById(cat_to_delete)!;
    if (node.children.length == 0) {
        document.querySelector<HTMLElement>('.DeleteIconTitle span')!.innerHTML =
            delete_album_with_name.replace('%s', node.name);
    } else {
        document.querySelector<HTMLElement>('.DeleteIconTitle span')!.innerHTML =
            delete_album_with_subs
                .replace('%s', node.name)
                .replace('%d', String(getAllSubAlbumsFromNode(node, 0)));
    }

    if (_deleteAlbumClickHandler) {
        document.querySelector<HTMLElement>('.DeleteAlbumSubmit')!.removeEventListener('click', _deleteAlbumClickHandler);
    }
    _deleteAlbumClickHandler = function () {
        fetch('ws.php?format=json&method=pwg.categories.delete', {
            method: 'POST',
            body: new URLSearchParams({
                category_id: cat_to_delete,
                photo_deletion_mode: (document.querySelector<HTMLInputElement>('input[name=photo_deletion_mode]:checked'))!.value,
                pwg_token: pwg_token,
            }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
        .then(function (response) { return response.text(); })
        .then(function (_raw_data) {
            const parentOfDeletedNode = node.parent;
            pwgTree.removeNode(node);

            rebindAddButtons(pwgTree);
            rebindDeleteButtons(pwgTree);
            rebindTitleContainers(pwgTree);

            tippy('.tiptip', { delay: 0, placement: 'top' });

            updateTitleBadge(nb_albums - 1);
            setSubcatsBadge(parentOfDeletedNode as TreeNode | null);
            closeDeleteAlbumPopIn();
        })
        .catch(function (error) { console.log(error); });
    };
    document.querySelector<HTMLElement>('.DeleteAlbumSubmit')!.addEventListener('click', _deleteAlbumClickHandler);
}

function closeDeleteAlbumPopIn(): void {
    document.getElementById('DeleteAlbum')!.style.display = 'none';
}

function getAllSubAlbumsFromNode(node: TreeNode, nb_sub_cats: number): number {
    nb_sub_cats = 0;
    if (node.children.length > 0) {
        node.children.forEach(function (child) {
            nb_sub_cats++;
            const tmp = getAllSubAlbumsFromNode(child, nb_sub_cats);
            nb_sub_cats += tmp;
        });
    } else {
        return 0;
    }
    return nb_sub_cats;
}

function setSubcatsBadge(node: TreeNode | null): void {
    if (!node) return;
    if (node.children.length != 0) {
        const nbSubcatsEl = document.querySelector<HTMLElement>('#cat-' + String(node.id) + ' .nb-subcats');
        if (nbSubcatsEl) {
            nbSubcatsEl.textContent = String(node.children.length);
            nbSubcatsEl.style.display = '';
        }
        const badgeDropdown = document.querySelector<HTMLElement>('#cat-' + String(node.id) + ' .badge-dropdown .nb-subcats');
        if (badgeDropdown) {
            badgeDropdown.textContent = x_nb_subcats.replace('%d', String(node.children.length));
        }
    } else {
        const nbSubcatsEl = document.querySelector<HTMLElement>('#cat-' + String(node.id) + ' .nb-subcats');
        if (nbSubcatsEl) {
            nbSubcatsEl.style.display = 'none';
        }
    }
}

function updateTitleBadge(new_nb_albums: number): void {
    nb_albums = new_nb_albums;
    document.querySelector<HTMLElement>('.badge-number')!.textContent = String(new_nb_albums);
}

function goToNode(node: TreeNode, firstNode: TreeNode, pwgTree: PwgTree): void {
    if (node.parent) {
        goToNode(node.parent as TreeNode, firstNode, pwgTree);
        if (node != firstNode) {
            pwgTree.openNode(node);
            const parentEl = document.getElementById('cat-' + String((node.parent as TreeNode).id));
            if (parentEl) {
                parentEl.style.display = '';
                parentEl.classList.add('immune');
            }
        }
    } else {
        pwgTree.openNode(node);
        const firstEl = document.getElementById('cat-' + String(firstNode.id));
        if (firstEl) {
            firstEl.classList.add('animateFocus');
        }
        showNodeChildren(firstNode);
    }
}

function showNodeChildren(node: TreeNode): void {
    if (node.children) {
        node.children.forEach(function (child) {
            const childEl = document.getElementById('cat-' + String(child.id));
            if (childEl) {
                childEl.classList.add('immune');
            }
            showNodeChildren(child);
        });
    }
}

function getId(parent: TreeMoveParent): number {
    if (parent.getLevel() == 0) {
        return 0;
    } else {
        return parent.id as number;
    }
}

function getRank(node: TreeNode, ignoreId: string | number | null = null): number {
    if (node.getPreviousSibling() != null) {
        if (node.id != ignoreId) {
            return 1 + getRank(node.getPreviousSibling()!, ignoreId);
        } else {
            return getRank(node.getPreviousSibling()!, ignoreId);
        }
    } else {
        if (node.id != ignoreId) {
            return 1;
        } else {
            return 0;
        }
    }
}

function applyMove(event: { move_info: TreeMoveInfo }, pwgTree: PwgTree): void {
    const waitingTimeout = setTimeout(function () {
        document.querySelector<HTMLElement>('.waiting-message')!.classList.add('visible');
    }, 500);
    const id = event.move_info.moved_node.id;
    let moveParent: number | null = null;
    let moveRank: number | null = null;
    const previous_parent = event.move_info.previous_parent;
    const target = event.move_info.target_node;
    if (event.move_info.position == 'after') {
        if (getId(previous_parent) != getId(target.parent as TreeMoveParent)) {
            moveParent = getId(target.parent as TreeMoveParent);
        }
        moveRank = getRank(target, id) + 1;
    } else if (event.move_info.position == 'inside') {
        if (getId(previous_parent) != getId(target as unknown as TreeMoveParent)) {
            moveParent = getId(target as unknown as TreeMoveParent);
        }
        moveRank = 1;
    } else if (event.move_info.position == 'before') {
        if (getId(previous_parent) != getId(target.parent as TreeMoveParent)) {
            moveParent = getId(target.parent as TreeMoveParent);
        }
        moveRank = 1;
    }
    moveNode(id, moveRank, moveParent, pwgTree)
        .then(function () {
            event.move_info.do_move();
            clearTimeout(waitingTimeout);
            document.querySelector<HTMLElement>('.waiting-message')!.classList.remove('visible');
            setSubcatsBadge(previous_parent as TreeNode | null);
            setSubcatsBadge(moveParent !== null ? pwgTree.getNodeById(moveParent) : null);

            rebindAddButtons(pwgTree);
            rebindDeleteButtons(pwgTree);
            rebindTitleContainers(pwgTree);

            tippy('.tiptip', { delay: 0, placement: 'top' });
        })
        .catch(function (message: unknown) {
            console.log('An error has occurred : ' + String(message));
            rebindAddButtons(pwgTree);
            rebindDeleteButtons(pwgTree);
            rebindTitleContainers(pwgTree);

            tippy('.tiptip', { delay: 0, placement: 'top' });
        });
}

function moveNode(node: string | number, rank: number | null, parent: number | null, pwgTree: PwgTree): Promise<void> {
    return new Promise(function (res, rej) {
        if (parent != null) {
            changeParent(node, parent, rank, pwgTree)
                .then(function () { res(); })
                .catch(function () { rej(); });
        } else if (rank != null) {
            changeRank(node, rank)
                .then(function () { res(); })
                .catch(function () { rej(); });
        }
    });
}

function changeParent(node: string | number, parent: number, rank: number | null, pwgTree: PwgTree): Promise<void> {
    return new Promise(function (res, rej) {
        fetch('ws.php?format=json&method=pwg.categories.move', {
            method: 'POST',
            body: new URLSearchParams({
                category_id: String(node),
                parent: String(parent),
                pwg_token: pwg_token,
            }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
        .then(function (response) { return response.text(); })
        .then(function (raw_data) {
            const responseData = JSON.parse(raw_data) as ApiStatResponse;
            if (responseData.stat === 'ok') {
                if (rank !== null) changeRank(node, rank);
                const updated_cats = responseData.result.updated_cats;
                if (updated_cats) {
                    updated_cats.forEach(function (cat) {
                        const catNode = pwgTree.getNodeById(cat.cat_id);
                        if (catNode) {
                            catNode.nb_sub_photos = cat.nb_sub_photos;
                            pwgTree.updateNode(catNode, catNode.name);
                        }
                    });
                }
                res();
            } else {
                rej(raw_data);
            }
        })
        .catch(function (error) { rej(error); });
    });
}

function changeRank(node: string | number, rank: number): Promise<void> {
    return new Promise(function (res, rej) {
        fetch('ws.php?format=json&method=pwg.categories.setRank', {
            method: 'POST',
            body: new URLSearchParams({
                category_id: String(node),
                rank: String(rank),
            }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
        .then(function (response) { return response.text(); })
        .then(function (raw_data) {
            const responseData = JSON.parse(raw_data) as { stat: string };
            if (responseData.stat === 'ok') {
                res();
            } else {
                rej(raw_data);
            }
        })
        .catch(function (error) { rej(error); });
    });
}

function makePrivateHierarchy(node: TreeNode): void {
    node.status = 'private';
    node.children.forEach(function (child) {
        makePrivateHierarchy(child);
    });
}

function getPathNode(node: TreeNode): string {
    if (node.parent.getLevel() != 0) {
        return getPathNode(node.parent as TreeNode) + ' / ' + node.name;
    } else {
        return node.name;
    }
}

initModule(init as unknown as (cfg: Record<string, unknown>) => void);
