export interface TreeNodeData {
    id: string | number;
    name: string;
    status?: string;
    visible?: boolean;
    nb_subcats?: number;
    nb_images?: number;
    nb_sub_photos?: number;
    nb_images_associated?: number;
    has_not_access?: boolean;
    isEmptyFolder?: boolean;
    children?: TreeNodeData[];
}

interface RootNode {
    id: undefined;
    children: TreeNode[];
    parent: null;
    getLevel(): number;
}

export class TreeNode {
    id: string | number;
    name: string;
    parent: TreeNode | RootNode;
    children: TreeNode[] = [];
    status: string;
    visible: boolean;
    nb_subcats: number;
    nb_images: number;
    nb_sub_photos: number;
    nb_images_associated: number;
    has_not_access: boolean;
    isEmptyFolder: boolean;
    _el: HTMLElement | null = null;

    constructor(data: TreeNodeData, parent: TreeNode | RootNode) {
        this.id = data.id;
        this.name = data.name;
        this.parent = parent;
        this.status = data.status ?? '';
        this.visible = data.visible !== undefined ? data.visible : true;
        this.nb_subcats = data.nb_subcats ?? 0;
        this.nb_images = data.nb_images ?? 0;
        this.nb_sub_photos = data.nb_sub_photos ?? 0;
        this.nb_images_associated = data.nb_images_associated ?? 0;
        this.has_not_access = data.has_not_access ?? false;
        this.isEmptyFolder = data.isEmptyFolder ?? false;
    }

    getLevel(): number {
        let level = 0;
        let p: TreeNode | RootNode = this.parent;
        while (p && p.id !== undefined) { level++; p = (p).parent; }
        return level;
    }

    getPreviousSibling(): TreeNode | null {
        if (!this.parent) return null;
        const siblings = this.parent.children;
        const idx = siblings.indexOf(this);
        return idx > 0 ? (siblings[idx - 1] ?? null) : null;
    }
}

export interface PwgTreeOptions {
    data?: TreeNodeData[];
    dragAndDrop?: boolean;
    onCreateLi?: (node: TreeNode, li: HTMLElement) => void;
}

type DropPosition = 'before' | 'after' | 'inside';

interface MoveInfo {
    moved_node: TreeNode;
    target_node: TreeNode;
    previous_parent: TreeNode | RootNode;
    position: DropPosition;
    do_move: () => void;
}

export class PwgTree {
    container: HTMLElement;
    options: PwgTreeOptions;
    private _nodes: Record<string, TreeNode> = {};
    private _root: RootNode;
    private _openNodes: (string | number)[] = [];

    constructor(container: string | HTMLElement, options: PwgTreeOptions = {}) {
        this.container = typeof container === 'string'
            ? document.querySelector<HTMLElement>(container)!
            : container;
        this.options = options;
        this._root = {
            id: undefined,
            children: [],
            parent: null,
            getLevel() { return -1; },
        };

        this._buildTree(options.data ?? [], this._root);
        this._render();
        if (options.dragAndDrop) this._initDnD();
    }

    private _buildTree(data: TreeNodeData[], parent: TreeNode | RootNode): void {
        for (const item of data) {
            const node = new TreeNode(item, parent);
            parent.children.push(node);
            this._nodes[String(node.id)] = node;
            if (item.children?.length) this._buildTree(item.children, node);
        }
    }

    private _render(): void {
        this.container.innerHTML = '';
        this.container.appendChild(this._renderList(this._root.children, true));
    }

    private _renderList(nodes: TreeNode[], isRoot: boolean): HTMLUListElement {
        const ul = document.createElement('ul');
        ul.className = isRoot ? 'jqtree-tree' : 'jqtree_common';
        if (!isRoot) ul.style.display = 'none';
        for (const node of nodes) ul.appendChild(this._renderNode(node));
        return ul;
    }

    private _renderNode(node: TreeNode): HTMLLIElement {
        const li = document.createElement('li');
        li.className = 'jqtree_common';
        const div = document.createElement('div');
        div.className = 'jqtree-element';
        li.appendChild(div);
        node._el = li;
        if (node.children.length) li.appendChild(this._renderList(node.children, false));
        this.options.onCreateLi?.(node, li);
        return li;
    }

    getNodeById(id: string | number): TreeNode | null {
        return this._nodes[String(id)] ?? this._nodes[id] ?? null;
    }

    getState(): { open_nodes: (string | number)[] } {
        return { open_nodes: this._openNodes.slice() };
    }

    openNode(node: TreeNode): void {
        if (!node?._el) return;
        const childUl = node._el.querySelector<HTMLElement>(':scope > ul');
        if (childUl) childUl.style.display = '';
        if (!this._openNodes.includes(node.id)) this._openNodes.push(node.id);
        this._fire('tree.open', { node });
    }

    closeNode(node: TreeNode): void {
        if (!node?._el) return;
        const childUl = node._el.querySelector<HTMLElement>(':scope > ul');
        if (childUl) childUl.style.display = 'none';
        const idx = this._openNodes.indexOf(node.id);
        if (idx !== -1) this._openNodes.splice(idx, 1);
        this._fire('tree.close', { node });
    }

    appendNode(data: TreeNodeData, parent?: TreeNode | RootNode): TreeNode {
        const parentNode = parent ?? this._root;
        const node = new TreeNode(data, parentNode);
        parentNode.children.push(node);
        this._nodes[String(node.id)] = node;
        const targetUl = this._getOrCreateChildUl(parentNode);
        targetUl.appendChild(this._renderNode(node));
        return node;
    }

    prependNode(data: TreeNodeData, parent?: TreeNode | RootNode): TreeNode {
        const parentNode = parent ?? this._root;
        const node = new TreeNode(data, parentNode);
        parentNode.children.unshift(node);
        this._nodes[String(node.id)] = node;
        const targetUl = this._getOrCreateChildUl(parentNode);
        targetUl.insertBefore(this._renderNode(node), targetUl.firstChild);
        return node;
    }

    private _getOrCreateChildUl(parentNode: TreeNode | RootNode): HTMLUListElement {
        const parentLi = (parentNode as TreeNode)._el ?? null;
        let ul = (parentLi
            ? parentLi.querySelector<HTMLUListElement>(':scope > ul')
            : this.container.querySelector<HTMLUListElement>('.jqtree-tree'));
        if (!ul) {
            ul = this._renderList([], false);
            if (parentLi) parentLi.appendChild(ul);
        }
        return ul;
    }

    removeNode(node: TreeNode): void {
        if (!node) return;
        const parent = node.parent;
        if (parent) {
            const idx = parent.children.indexOf(node);
            if (idx !== -1) parent.children.splice(idx, 1);
        }
        delete this._nodes[String(node.id)];
        node._el?.parentNode?.removeChild(node._el);
    }

    updateNode(node: TreeNode, name: string): void {
        if (!node) return;
        node.name = name;
        if (this.options.onCreateLi && node._el) {
            const div = node._el.querySelector('.jqtree-element');
            if (div) div.innerHTML = '';
            this.options.onCreateLi(node, node._el);
        }
    }

    private _fire(eventName: string, detail: Record<string, unknown>): void {
        const ev = new CustomEvent(eventName, { bubbles: true, detail });
        Object.assign(ev, detail);
        this.container.dispatchEvent(ev);
    }

    private _getNodeForEl(li: HTMLElement): TreeNode | null {
        for (const id in this._nodes) {
            if (this._nodes[id]?._el === li) return this._nodes[id] ?? null;
        }
        return null;
    }

    private _initDnD(): void {
        let dragSrcNode: TreeNode | null = null;

        this.container.addEventListener('dragstart', (e: DragEvent) => {
            const el = (e.target as Element).closest<HTMLElement>('.jqtree-element');
            if (!el) return;
            dragSrcNode = this._getNodeForEl(el.parentElement as HTMLElement);
            if (!dragSrcNode) return;
            this.container.classList.add('dragging');
            e.dataTransfer!.effectAllowed = 'move';
            e.dataTransfer!.setData('text/plain', String(dragSrcNode.id));
        });

        this.container.addEventListener('dragover', (e: DragEvent) => {
            e.preventDefault();
            const el = (e.target as Element).closest<HTMLElement>('.jqtree-element');
            if (!el || !dragSrcNode) return;
            const targetNode = this._getNodeForEl(el.parentElement as HTMLElement);
            if (!targetNode || targetNode === dragSrcNode) return;
            const rect = el.getBoundingClientRect();
            const relY = e.clientY - rect.top;
            const pos: DropPosition = relY < rect.height * 0.25 ? 'before' : relY > rect.height * 0.75 ? 'after' : 'inside';
            el.setAttribute('data-drop-pos', pos);
        });

        this.container.addEventListener('dragleave', (e: DragEvent) => {
            (e.target as Element).closest<HTMLElement>('.jqtree-element')?.removeAttribute('data-drop-pos');
        });

        this.container.addEventListener('drop', (e: DragEvent) => {
            e.preventDefault();
            this.container.classList.remove('dragging');
            const el = (e.target as Element).closest<HTMLElement>('.jqtree-element');
            if (!el || !dragSrcNode) { dragSrcNode = null; return; }
            el.removeAttribute('data-drop-pos');
            const targetNode = this._getNodeForEl(el.parentElement as HTMLElement);
            if (!targetNode || targetNode === dragSrcNode) { dragSrcNode = null; return; }
            const rect = el.getBoundingClientRect();
            const relY = e.clientY - rect.top;
            const position: DropPosition = relY < rect.height * 0.25 ? 'before' : relY > rect.height * 0.75 ? 'after' : 'inside';
            const movedNode = dragSrcNode;
            const previousParent = movedNode.parent;
            dragSrcNode = null;

            const moveInfo: MoveInfo = {
                moved_node: movedNode,
                target_node: targetNode,
                previous_parent: previousParent,
                position,
                do_move: () => {
                    const oldParent = movedNode.parent;
                    const idx = oldParent.children.indexOf(movedNode);
                    if (idx !== -1) oldParent.children.splice(idx, 1);
                    movedNode._el?.parentNode?.removeChild(movedNode._el);

                    if (position === 'inside') {
                        movedNode.parent = targetNode;
                        targetNode.children.push(movedNode);
                        let ul = targetNode._el?.querySelector<HTMLUListElement>(':scope > ul') ?? null;
                        if (!ul) {
                            ul = document.createElement('ul');
                            ul.className = 'jqtree_common';
                            targetNode._el?.appendChild(ul);
                        }
                        ul.style.display = '';
                        ul.appendChild(movedNode._el!);
                    } else {
                        const newParent = targetNode.parent ?? this._root;
                        movedNode.parent = newParent;
                        const siblings = newParent.children;
                        const tIdx = siblings.indexOf(targetNode);
                        if (position === 'before') {
                            siblings.splice(tIdx, 0, movedNode);
                            targetNode._el!.parentNode!.insertBefore(movedNode._el!, targetNode._el);
                        } else {
                            siblings.splice(tIdx + 1, 0, movedNode);
                            targetNode._el!.parentNode!.insertBefore(movedNode._el!, targetNode._el!.nextSibling);
                        }
                    }
                },
            };
            this._fire('tree.move', { move_info: moveInfo });
        });

        this.container.addEventListener('dragend', () => {
            this.container.classList.remove('dragging');
            dragSrcNode = null;
        });
    }
}
