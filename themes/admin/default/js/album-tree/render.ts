// Builds the LI/UL DOM for a single node (children excluded — the tree
// orchestrates child rendering). Class names use the pwgtree- prefix to
// match the bundled tree.css and the matching rules in albums.tpl + the
// admin themes.

import type { TreeNode, RenderHook } from './types';

export function isFolder(node: TreeNode): boolean {
    if (node.children.length > 0) return true;
    if (Array.isArray(node.haveChildren)) return node.haveChildren.length > 0;
    return !!node.haveChildren;
}

export function createNodeLi(
    node: TreeNode,
    isOpen: boolean,
    onCreateLi?: RenderHook
): HTMLElement {
    const li = document.createElement('li');
    li.classList.add('pwgtree-common');

    if (isFolder(node)) {
        li.classList.add('pwgtree-folder');
        li.classList.add(isOpen ? 'pwgtree-open' : 'pwgtree-closed');
    }

    const elDiv = document.createElement('div');
    elDiv.classList.add('pwgtree-element', 'pwgtree-common');

    const titleSpan = document.createElement('span');
    titleSpan.classList.add('pwgtree-title');
    titleSpan.textContent = String(node.name);
    elDiv.appendChild(titleSpan);

    li.appendChild(elDiv);
    node.element = li;

    if (onCreateLi) onCreateLi(node, li);

    return li;
}

export function createChildrenUl(): HTMLElement {
    const ul = document.createElement('ul');
    ul.classList.add('pwgtree-common');
    return ul;
}
