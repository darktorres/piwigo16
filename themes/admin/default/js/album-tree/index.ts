// Vanilla TypeScript replacement for jqTree, scoped to Piwigo's album admin
// page. Models a tree of albums and renders DOM with the pwgtree- class
// prefix (pwgtree-tree / pwgtree-folder / pwgtree-element / pwgtree-open /
// pwgtree-closed / pwgtree-common); matching styles live in tree.css and
// the admin themes.
//
// Public API: getNodeById, openNode, closeNode, updateNode, removeNode,
// appendNode, prependNode, loadData. State persistence is mirrored to
// localStorage; drag-and-drop uses HTML5 native events.

import './tree.css';
import type { NodeData, NodeId, TreeNode, TreeOptions, Position } from './types';
import { TreeStateStore } from './state';
import { createNodeLi, createChildrenUl, isFolder } from './render';
import { attachDnd, makeDraggable } from './dnd';

class AlbumTreeImpl {
    private rootEl: HTMLElement;
    private rootUl: HTMLElement;
    private rootNodes: TreeNode[] = [];
    private nodesById: Map<string, TreeNode> = new Map();
    private liToNode: WeakMap<HTMLElement, TreeNode> = new WeakMap();
    private openIds: Set<string> = new Set();
    private store: TreeStateStore | null;
    private options: TreeOptions;
    private detachDnd: (() => void) | null = null;

    constructor(rootEl: HTMLElement, options: TreeOptions) {
        this.rootEl = rootEl;
        this.options = options;
        this.store = options.saveStateKey ? new TreeStateStore(options.saveStateKey) : null;
        if (this.store) this.openIds = this.store.load();

        this.rootEl.classList.add('pwgtree-tree');
        this.rootUl = document.createElement('ul');
        this.rootUl.classList.add('pwgtree-tree', 'pwgtree-common');
        // The mount point is itself a div; hold the UL inside it.
        this.rootEl.innerHTML = '';
        this.rootEl.appendChild(this.rootUl);

        this.loadInitial(options.data);

        if (options.dragAndDrop) {
            this.detachDnd = attachDnd({
                rootEl: this.rootEl,
                nodeForLi: (li) => this.liToNode.get(li) ?? null,
                onMove: options.onMove,
                moveNode: (moved, target, position) =>
                    this.moveNodeInternal(moved, target, position),
            });
        }
    }

    // ---------------------------------------------------------------------
    // Construction / rendering
    // ---------------------------------------------------------------------

    private loadInitial(data: NodeData[]): void {
        this.rootNodes = data.map((d) => this.buildNode(d, null));
        this.rebuildRootDom();
    }

    private buildNode(data: NodeData, parent: TreeNode | null): TreeNode {
        // The sibling walkers reach the tree's rootNodes for root-level nodes
        // (which have no .parent). They use arrow functions so `this` resolves
        // to the enclosing Tree instance, and close over `node` for self-reference.
        // Spread the data first so callers can read arbitrary fields off the
        // node directly, then attach the structural fields and methods.
        const node: TreeNode = {
            ...data,
            parent,
            children: [],
            element: null,
            getLevel(): number {
                let depth = 0;
                let p: TreeNode | null = this.parent;
                while (p) {
                    depth++;
                    p = p.parent;
                }
                return depth + 1;
            },
            getPreviousSibling: (): TreeNode | null => {
                const siblings = node.parent ? node.parent.children : this.rootNodes;
                const i = siblings.indexOf(node);
                return i > 0 ? siblings[i - 1]! : null;
            },
            getNextSibling: (): TreeNode | null => {
                const siblings = node.parent ? node.parent.children : this.rootNodes;
                const i = siblings.indexOf(node);
                return i >= 0 && i < siblings.length - 1 ? siblings[i + 1]! : null;
            },
        };

        if (Array.isArray(data.children)) {
            node.children = data.children.map((c) => this.buildNode(c, node));
        }

        this.nodesById.set(String(node.id), node);
        return node;
    }

    private rebuildRootDom(): void {
        this.rootUl.innerHTML = '';
        for (const n of this.rootNodes) {
            this.rootUl.appendChild(this.renderNodeRecursive(n));
        }
    }

    private renderNodeRecursive(node: TreeNode): HTMLElement {
        const open = this.openIds.has(String(node.id));
        const li = createNodeLi(node, open, this.options.onCreateLi);
        this.liToNode.set(li, node);
        if (this.options.dragAndDrop) makeDraggable(li);

        if (node.children.length > 0) {
            const ul = createChildrenUl();
            for (const child of node.children) {
                ul.appendChild(this.renderNodeRecursive(child));
            }
            li.appendChild(ul);
        }
        return li;
    }

    // ---------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------

    getNodeById(id: NodeId | null | undefined): TreeNode | null {
        if (id == null) return null;
        return this.nodesById.get(String(id)) ?? null;
    }

    getOpenNodeIds(): string[] {
        return Array.from(this.openIds);
    }

    openNode(node: TreeNode | null | undefined): void {
        if (!node) return;
        const key = String(node.id);
        if (this.openIds.has(key)) return;
        this.openIds.add(key);
        const li = node.element;
        if (li) {
            li.classList.remove('pwgtree-closed');
            li.classList.add('pwgtree-open');
        }
        this.persistState();
        this.options.onOpen?.(node);
    }

    closeNode(node: TreeNode | null | undefined): void {
        if (!node) return;
        const key = String(node.id);
        if (!this.openIds.has(key)) return;
        this.openIds.delete(key);
        const li = node.element;
        if (li) {
            li.classList.remove('pwgtree-open');
            li.classList.add('pwgtree-closed');
        }
        this.persistState();
        this.options.onClose?.(node);
    }

    isNodeOpen(node: TreeNode): boolean {
        return this.openIds.has(String(node.id));
    }

    updateNode(node: TreeNode | null | undefined, newName: string): void {
        if (!node) return;
        node.name = newName;
        const li = node.element;
        if (!li) return;
        // Re-render through the user hook by wiping the element and rebuilding
        // its inner content. Children UL is preserved.
        const childUl = li.querySelector<HTMLElement>(':scope > ul.pwgtree-common');
        const elDiv = li.querySelector<HTMLElement>(':scope > .pwgtree-element');
        if (elDiv) elDiv.remove();

        const newDiv = document.createElement('div');
        newDiv.classList.add('pwgtree-element', 'pwgtree-common');
        const titleSpan = document.createElement('span');
        titleSpan.classList.add('pwgtree-title');
        titleSpan.textContent = String(newName);
        newDiv.appendChild(titleSpan);
        // Insert before the children UL (if any) so DOM order stays consistent.
        if (childUl) li.insertBefore(newDiv, childUl);
        else li.appendChild(newDiv);

        this.options.onCreateLi?.(node, li);
    }

    removeNode(node: TreeNode | null | undefined): void {
        if (!node) return;
        // Detach from parent / root list.
        const siblings = node.parent ? node.parent.children : this.rootNodes;
        const i = siblings.indexOf(node);
        if (i >= 0) siblings.splice(i, 1);

        // Recursively unregister this subtree.
        const removeIds = (n: TreeNode): void => {
            this.nodesById.delete(String(n.id));
            this.openIds.delete(String(n.id));
            for (const c of n.children) removeIds(c);
        };
        removeIds(node);

        // Remove DOM. If the parent has no children left, also drop the empty UL
        // and the folder/open/closed classes so it stops looking like a folder.
        const parentEl = node.parent?.element ?? null;
        node.element?.remove();
        if (parentEl) {
            const ul = parentEl.querySelector<HTMLElement>(':scope > ul.pwgtree-common');
            if (ul && ul.children.length === 0) {
                ul.remove();
                parentEl.classList.remove('pwgtree-folder', 'pwgtree-open', 'pwgtree-closed');
            }
        }
        this.persistState();
    }

    appendNode(data: NodeData, parent: TreeNode | null | undefined): TreeNode {
        return this.insertNode(data, parent ?? null, 'append');
    }

    prependNode(data: NodeData, parent: TreeNode | null | undefined): TreeNode {
        return this.insertNode(data, parent ?? null, 'prepend');
    }

    private insertNode(
        data: NodeData,
        parent: TreeNode | null,
        where: 'append' | 'prepend'
    ): TreeNode {
        const node = this.buildNode(data, parent);
        const siblings = parent ? parent.children : this.rootNodes;
        if (where === 'append') siblings.push(node);
        else siblings.unshift(node);

        const li = this.renderNodeRecursive(node);

        if (parent) {
            const parentLi = parent.element;
            if (parentLi) {
                let ul = parentLi.querySelector<HTMLElement>(':scope > ul.pwgtree-common');
                if (!ul) {
                    ul = createChildrenUl();
                    parentLi.appendChild(ul);
                    parentLi.classList.add('pwgtree-folder');
                    if (!parentLi.classList.contains('pwgtree-open'))
                        parentLi.classList.add('pwgtree-closed');
                }
                if (where === 'append') ul.appendChild(li);
                else ul.insertBefore(li, ul.firstChild);
            }
        } else {
            if (where === 'append') this.rootUl.appendChild(li);
            else this.rootUl.insertBefore(li, this.rootUl.firstChild);
        }
        return node;
    }

    // Replace `parent`'s children with the given data (used for lazy load).
    loadData(data: NodeData[], parent: TreeNode | null | undefined): void {
        if (parent) {
            // Drop existing children from the index.
            const drop = (n: TreeNode): void => {
                this.nodesById.delete(String(n.id));
                this.openIds.delete(String(n.id));
                for (const c of n.children) drop(c);
            };
            for (const c of parent.children) drop(c);
            parent.children = data.map((d) => this.buildNode(d, parent));

            const parentLi = parent.element;
            if (parentLi) {
                let ul = parentLi.querySelector<HTMLElement>(':scope > ul.pwgtree-common');
                if (ul) ul.innerHTML = '';
                else {
                    ul = createChildrenUl();
                    parentLi.appendChild(ul);
                }
                if (parent.children.length > 0) {
                    parentLi.classList.add('pwgtree-folder');
                    if (!parentLi.classList.contains('pwgtree-open'))
                        parentLi.classList.add('pwgtree-closed');
                    for (const child of parent.children)
                        ul.appendChild(this.renderNodeRecursive(child));
                } else {
                    ul.remove();
                    parentLi.classList.remove('pwgtree-folder', 'pwgtree-open', 'pwgtree-closed');
                }
            }
        } else {
            // Wholesale reload.
            this.nodesById.clear();
            this.rootNodes = data.map((d) => this.buildNode(d, null));
            this.rebuildRootDom();
        }
        this.persistState();
    }

    // ---------------------------------------------------------------------
    // Internal mutators (used by drag-and-drop)
    // ---------------------------------------------------------------------

    private moveNodeInternal(moved: TreeNode, target: TreeNode, position: Position): void {
        const oldParent = moved.parent;
        const oldSiblings = oldParent ? oldParent.children : this.rootNodes;
        const oi = oldSiblings.indexOf(moved);
        if (oi >= 0) oldSiblings.splice(oi, 1);

        let newParent: TreeNode | null;
        let newSiblings: TreeNode[];
        let insertIndex: number;
        if (position === 'inside') {
            newParent = target;
            newSiblings = target.children;
            insertIndex = newSiblings.length;
        } else {
            newParent = target.parent;
            newSiblings = newParent ? newParent.children : this.rootNodes;
            const ti = newSiblings.indexOf(target);
            insertIndex = position === 'before' ? ti : ti + 1;
        }
        moved.parent = newParent;
        newSiblings.splice(insertIndex, 0, moved);

        const movedLi = moved.element;
        if (!movedLi) return;
        movedLi.remove();

        if (position === 'inside') {
            const targetLi = target.element;
            if (!targetLi) return;
            let ul = targetLi.querySelector<HTMLElement>(':scope > ul.pwgtree-common');
            if (!ul) {
                ul = createChildrenUl();
                targetLi.appendChild(ul);
                targetLi.classList.add('pwgtree-folder');
                if (!targetLi.classList.contains('pwgtree-open'))
                    targetLi.classList.add('pwgtree-closed');
            }
            ul.appendChild(movedLi);
        } else {
            const targetLi = target.element;
            if (!targetLi || !targetLi.parentNode) return;
            if (position === 'before') targetLi.parentNode.insertBefore(movedLi, targetLi);
            else targetLi.parentNode.insertBefore(movedLi, targetLi.nextSibling);
        }

        // Old parent went childless — drop its UL and folder classes so it no
        // longer paints as expandable.
        if (oldParent && oldParent !== newParent && oldSiblings.length === 0 && oldParent.element) {
            const ul = oldParent.element.querySelector<HTMLElement>(':scope > ul.pwgtree-common');
            if (ul) {
                ul.remove();
                oldParent.element.classList.remove(
                    'pwgtree-folder',
                    'pwgtree-open',
                    'pwgtree-closed'
                );
            }
        }
    }

    // ---------------------------------------------------------------------

    private persistState(): void {
        this.store?.save(this.openIds);
    }

    destroy(): void {
        this.detachDnd?.();
        this.rootEl.innerHTML = '';
        this.nodesById.clear();
        this.rootNodes = [];
    }
}

export type AlbumTree = AlbumTreeImpl;

export function mount(rootEl: HTMLElement, options: TreeOptions): AlbumTree {
    return new AlbumTreeImpl(rootEl, options);
}

export type { NodeData, NodeId, TreeNode, TreeOptions, MoveInfo, Position } from './types';
