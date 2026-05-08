// HTML5 native drag-and-drop for the album tree.
//
// Strategy: every LI is draggable. dragover on a target LI computes one of
// three positions (before / inside / after) based on the cursor's vertical
// position within the target. A "ghost" indicator is rendered to show the
// drop target. On drop, we synthesise a MoveInfo and call onMove(); if the
// caller doesn't preventDefault, the move is committed automatically.

import type { Position, MoveInfo, TreeNode } from './types';
import { isFolder } from './render';

export interface DndContext {
    rootEl: HTMLElement;
    nodeForLi(li: HTMLElement): TreeNode | null;
    moveNode(moved: TreeNode, target: TreeNode, position: Position): void;
    onMove?: (
        info: MoveInfo,
        ev: { preventDefault: () => void; readonly defaultPrevented: boolean }
    ) => void;
}

const GHOST_CLASS = 'pwgtree-ghost-indicator';

export function attachDnd(ctx: DndContext): () => void {
    let draggedNode: TreeNode | null = null;
    let ghost: HTMLElement | null = null;
    let lastTargetLi: HTMLElement | null = null;
    let lastPosition: Position | null = null;

    function clearGhost(): void {
        ghost?.remove();
        ghost = null;
        lastTargetLi = null;
        lastPosition = null;
        ctx.rootEl
            .querySelectorAll('.pwgtree-moving')
            .forEach((el) => el.classList.remove('pwgtree-moving'));
    }

    function placeGhost(targetLi: HTMLElement, position: Position): void {
        if (!ghost) {
            ghost = document.createElement('div');
            ghost.className = GHOST_CLASS;
            ghost.style.cssText =
                'position:absolute;left:0;right:0;height:2px;background:#0000ff;pointer-events:none;z-index:10;';
        }
        const rect = targetLi.getBoundingClientRect();
        const rootRect = ctx.rootEl.getBoundingClientRect();
        const relTop = rect.top - rootRect.top + ctx.rootEl.scrollTop;
        if (position === 'before') {
            ghost.style.top = `${relTop}px`;
            ghost.style.height = '2px';
            ghost.style.background = '#0000ff';
        } else if (position === 'after') {
            ghost.style.top = `${relTop + rect.height}px`;
            ghost.style.height = '2px';
            ghost.style.background = '#0000ff';
        } else {
            // 'inside' — outline the whole element div
            ghost.style.top = `${relTop}px`;
            ghost.style.height = `${rect.height}px`;
            ghost.style.background = 'rgba(0,0,255,0.15)';
        }
        if (ghost.parentNode !== ctx.rootEl) ctx.rootEl.appendChild(ghost);
    }

    function computePosition(
        targetLi: HTMLElement,
        clientY: number,
        allowInside: boolean
    ): Position {
        const elDiv = targetLi.querySelector<HTMLElement>(':scope > .pwgtree-element');
        const rect = (elDiv ?? targetLi).getBoundingClientRect();
        const rel = clientY - rect.top;
        const third = rect.height / 3;
        if (allowInside) {
            if (rel < third) return 'before';
            if (rel > rect.height - third) return 'after';
            return 'inside';
        }
        return rel < rect.height / 2 ? 'before' : 'after';
    }

    function isAncestor(node: TreeNode, candidate: TreeNode): boolean {
        let p: TreeNode | null = candidate;
        while (p) {
            if (p === node) return true;
            p = p.parent;
        }
        return false;
    }

    const onDragStart = (e: DragEvent): void => {
        const li = (e.target as HTMLElement | null)?.closest<HTMLElement>('li.pwgtree-common');
        if (!li || !ctx.rootEl.contains(li)) return;
        const node = ctx.nodeForLi(li);
        if (!node) return;
        draggedNode = node;
        li.classList.add('pwgtree-moving');
        ctx.rootEl.classList.add('pwgtree-dragging');
        try {
            e.dataTransfer?.setData('text/plain', String(node.id));
            if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
        } catch {
            // Some browsers reject setData under specific permissions.
        }
    };

    const onDragOver = (e: DragEvent): void => {
        if (!draggedNode) return;
        const li = (e.target as HTMLElement | null)?.closest<HTMLElement>('li.pwgtree-common');
        if (!li || !ctx.rootEl.contains(li)) return;
        const targetNode = ctx.nodeForLi(li);
        if (!targetNode || targetNode === draggedNode) return;
        // Forbid dropping onto own descendant (would create a cycle).
        if (isAncestor(draggedNode, targetNode)) return;

        const allowInside = isFolder(targetNode) || targetNode.children.length === 0;
        const position = computePosition(li, e.clientY, allowInside);

        e.preventDefault();
        if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';

        if (li !== lastTargetLi || position !== lastPosition) {
            placeGhost(li, position);
            lastTargetLi = li;
            lastPosition = position;
        }
    };

    const onDragLeave = (e: DragEvent): void => {
        // Only clear if we're leaving the root entirely.
        const related = e.relatedTarget as HTMLElement | null;
        if (related && ctx.rootEl.contains(related)) return;
        clearGhost();
    };

    const onDrop = (e: DragEvent): void => {
        if (!draggedNode || !lastTargetLi || !lastPosition) {
            clearGhost();
            draggedNode = null;
            ctx.rootEl.classList.remove('pwgtree-dragging');
            return;
        }
        const targetNode = ctx.nodeForLi(lastTargetLi);
        const moved = draggedNode;
        const position = lastPosition;
        const previous_parent = moved.parent;

        clearGhost();
        draggedNode = null;
        ctx.rootEl.classList.remove('pwgtree-dragging');

        if (!targetNode) return;
        e.preventDefault();

        let prevented: boolean = false;
        const event = {
            preventDefault: () => {
                prevented = true;
            },
            get defaultPrevented(): boolean {
                return prevented;
            },
        };
        const info: MoveInfo = {
            moved_node: moved,
            target_node: targetNode,
            position,
            previous_parent,
            do_move: () => ctx.moveNode(moved, targetNode, position),
        };

        if (ctx.onMove) ctx.onMove(info, event);
        if (!prevented) info.do_move();
    };

    const onDragEnd = (): void => {
        clearGhost();
        draggedNode = null;
        ctx.rootEl.classList.remove('pwgtree-dragging');
    };

    ctx.rootEl.addEventListener('dragstart', onDragStart);
    ctx.rootEl.addEventListener('dragover', onDragOver);
    ctx.rootEl.addEventListener('dragleave', onDragLeave);
    ctx.rootEl.addEventListener('drop', onDrop);
    ctx.rootEl.addEventListener('dragend', onDragEnd);

    return () => {
        ctx.rootEl.removeEventListener('dragstart', onDragStart);
        ctx.rootEl.removeEventListener('dragover', onDragOver);
        ctx.rootEl.removeEventListener('dragleave', onDragLeave);
        ctx.rootEl.removeEventListener('drop', onDrop);
        ctx.rootEl.removeEventListener('dragend', onDragEnd);
    };
}

export function makeDraggable(li: HTMLElement): void {
    li.setAttribute('draggable', 'true');
}
