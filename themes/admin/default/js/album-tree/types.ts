// Public types for the vanilla album-tree module. The shape mirrors what
// albums.ts already consumes from the PHP-emitted page data and from jqtree's
// runtime nodes, so the swap is a name change rather than a model rewrite.

export type NodeId = string | number;

// Raw input shape. PHP emits anything-goes objects, so we keep an index
// signature for fields that albums.ts reads off the node directly.
export interface NodeData {
    id: NodeId;
    name: string;
    children?: NodeData[];
    haveChildren?: NodeData[] | boolean;
    load_on_demand?: boolean;
    nb_sub_photos?: number | string;
    nb_subcats?: number | string;
    nb_images?: number | string;
    status?: string;
    visible?: string;
    visble?: string;
    has_not_access?: boolean;
    isEmptyFolder?: boolean;
    [key: string]: unknown;
}

// Live tree node — what callers receive from getNodeById, etc.
export interface TreeNode extends NodeData {
    parent: TreeNode | null;
    children: TreeNode[];
    element: HTMLElement | null;
    getLevel(): number;
    getPreviousSibling(): TreeNode | null;
    getNextSibling(): TreeNode | null;
}

export type Position = 'before' | 'after' | 'inside';

export interface MoveInfo {
    moved_node: TreeNode;
    target_node: TreeNode;
    position: Position;
    previous_parent: TreeNode | null;
    do_move: () => void;
}

export interface MoveEvent {
    preventDefault: () => void;
    readonly defaultPrevented: boolean;
}

export type RenderHook = (node: TreeNode, li: HTMLElement) => void;
export type OpenHook = (node: TreeNode) => void;
export type CloseHook = (node: TreeNode) => void;
export type MoveHook = (info: MoveInfo, event: MoveEvent) => void;

export interface TreeOptions {
    data: NodeData[];
    onCreateLi?: RenderHook;
    onOpen?: OpenHook;
    onClose?: CloseHook;
    onMove?: MoveHook;
    dragAndDrop?: boolean;
    // Persistence key — when set, open/closed state is mirrored to localStorage.
    saveStateKey?: string;
}
