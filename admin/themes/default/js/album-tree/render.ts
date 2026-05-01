// Builds the LI/UL DOM for a single node (children excluded — the tree
// orchestrates child rendering). Class names mirror jqtree's so the existing
// CSS in albums.tpl and tree.css continues to apply unchanged.

import type { TreeNode, RenderHook } from './types';

export function isFolder(node: TreeNode): boolean {
    if (node.children.length > 0) return true;
    if (Array.isArray(node.haveChildren)) return node.haveChildren.length > 0;
    return !!node.haveChildren;
}

export function createNodeLi(
    node: TreeNode,
    isOpen: boolean,
    onCreateLi?: RenderHook,
): HTMLElement {
    const li = document.createElement('li');
    li.classList.add('jqtree_common');

    if (isFolder(node)) {
        li.classList.add('jqtree-folder');
        li.classList.add(isOpen ? 'jqtree-open' : 'jqtree-closed');
    }

    const elDiv = document.createElement('div');
    elDiv.classList.add('jqtree-element', 'jqtree_common');

    const titleSpan = document.createElement('span');
    titleSpan.classList.add('jqtree-title');
    titleSpan.textContent = String(node.name);
    elDiv.appendChild(titleSpan);

    li.appendChild(elDiv);
    node.element = li;

    if (onCreateLi) onCreateLi(node, li);

    return li;
}

export function createChildrenUl(): HTMLElement {
    const ul = document.createElement('ul');
    ul.classList.add('jqtree_common');
    return ul;
}
