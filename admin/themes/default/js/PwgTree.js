(function (window) {
    'use strict';

    function TreeNode(data, parent) {
        this.id = data.id;
        this.name = data.name;
        this.parent = parent;
        this.children = [];
        this.status = data.status || '';
        this.visible = data.visible !== undefined ? data.visible : true;
        this.nb_subcats = data.nb_subcats || 0;
        this.nb_images = data.nb_images || 0;
        this.nb_sub_photos = data.nb_sub_photos || 0;
        this.nb_images_associated = data.nb_images_associated || 0;
        this.has_not_access = data.has_not_access || false;
        this.isEmptyFolder = data.isEmptyFolder || false;
        this._el = null; // the <li> DOM element
    }

    TreeNode.prototype.getLevel = function () {
        var level = 0, p = this.parent;
        while (p && p.id !== undefined) { level++; p = p.parent; }
        return level;
    };

    TreeNode.prototype.getPreviousSibling = function () {
        if (!this.parent) return null;
        var siblings = this.parent.children;
        var idx = siblings.indexOf(this);
        return idx > 0 ? siblings[idx - 1] : null;
    };

    function PwgTree(container, options) {
        this.container = typeof container === 'string' ? document.querySelector(container) : container;
        this.options = options || {};
        this._nodes = {}; // id → TreeNode
        this._root = { id: undefined, children: [], parent: null, getLevel: function () { return -1; } };
        this._openNodes = [];
        this._dragSrc = null;

        this._buildTree(options.data || [], this._root);
        this._render();
        if (options.dragAndDrop) this._initDnD();
    }

    PwgTree.prototype._buildTree = function (data, parent) {
        var self = this;
        data.forEach(function (item) {
            var node = new TreeNode(item, parent);
            parent.children.push(node);
            self._nodes[node.id] = node;
            if (item.children && item.children.length) {
                self._buildTree(item.children, node);
            }
        });
    };

    PwgTree.prototype._render = function () {
        this.container.innerHTML = '';
        var ul = this._renderList(this._root.children, true);
        this.container.appendChild(ul);
    };

    PwgTree.prototype._renderList = function (nodes, isRoot) {
        var self = this;
        var ul = document.createElement('ul');
        ul.className = isRoot ? 'jqtree-tree' : 'jqtree_common';
        if (!isRoot) ul.style.display = 'none';
        nodes.forEach(function (node) {
            var li = self._renderNode(node);
            ul.appendChild(li);
        });
        return ul;
    };

    PwgTree.prototype._renderNode = function (node) {
        var self = this;
        var li = document.createElement('li');
        li.className = 'jqtree_common';

        var div = document.createElement('div');
        div.className = 'jqtree-element';

        li.appendChild(div);
        node._el = li;

        if (node.children.length) {
            var childUl = self._renderList(node.children, false);
            li.appendChild(childUl);
        }

        if (self.options.onCreateLi) {
            self.options.onCreateLi(node, li);
        }

        return li;
    };

    PwgTree.prototype.getNodeById = function (id) {
        return this._nodes[String(id)] || this._nodes[id] || null;
    };

    PwgTree.prototype.getState = function () {
        return { open_nodes: this._openNodes.slice() };
    };

    PwgTree.prototype.openNode = function (node, animate) {
        if (!node || !node._el) return;
        var childUl = node._el.querySelector(':scope > ul');
        if (childUl) childUl.style.display = '';
        if (!this._openNodes.includes(node.id)) this._openNodes.push(node.id);
        this._fire('tree.open', { node: node });
    };

    PwgTree.prototype.closeNode = function (node, animate) {
        if (!node || !node._el) return;
        var childUl = node._el.querySelector(':scope > ul');
        if (childUl) childUl.style.display = 'none';
        var idx = this._openNodes.indexOf(node.id);
        if (idx !== -1) this._openNodes.splice(idx, 1);
        this._fire('tree.close', { node: node });
    };

    PwgTree.prototype.appendNode = function (data, parent) {
        var parentNode = parent || this._root;
        var node = new TreeNode(data, parentNode);
        parentNode.children.push(node);
        this._nodes[node.id] = node;

        var parentLi = parentNode._el;
        var targetUl = parentLi
            ? parentLi.querySelector(':scope > ul')
            : this.container.querySelector('.jqtree-tree');
        if (!targetUl) {
            targetUl = this._renderList([], false);
            if (parentLi) parentLi.appendChild(targetUl);
        }
        var li = this._renderNode(node);
        targetUl.appendChild(li);
        return node;
    };

    PwgTree.prototype.prependNode = function (data, parent) {
        var parentNode = parent || this._root;
        var node = new TreeNode(data, parentNode);
        parentNode.children.unshift(node);
        this._nodes[node.id] = node;

        var parentLi = parentNode._el;
        var targetUl = parentLi
            ? parentLi.querySelector(':scope > ul')
            : this.container.querySelector('.jqtree-tree');
        if (!targetUl) {
            targetUl = this._renderList([], false);
            if (parentLi) parentLi.appendChild(targetUl);
        }
        var li = this._renderNode(node);
        targetUl.insertBefore(li, targetUl.firstChild);
        return node;
    };

    PwgTree.prototype.removeNode = function (node) {
        if (!node) return;
        var parent = node.parent;
        if (parent) {
            var idx = parent.children.indexOf(node);
            if (idx !== -1) parent.children.splice(idx, 1);
        }
        delete this._nodes[node.id];
        if (node._el && node._el.parentNode) node._el.parentNode.removeChild(node._el);
    };

    PwgTree.prototype.updateNode = function (node, name) {
        if (!node) return;
        node.name = name;
        if (this.options.onCreateLi && node._el) {
            // Clear and re-run onCreateLi to update the display
            var div = node._el.querySelector('.jqtree-element');
            if (div) div.innerHTML = '';
            this.options.onCreateLi(node, node._el);
        }
    };

    PwgTree.prototype._fire = function (eventName, detail) {
        var ev = new CustomEvent(eventName, { bubbles: true, detail: detail });
        // albums.js reads e.node and e.move_info directly off the event object
        Object.assign(ev, detail);
        this.container.dispatchEvent(ev);
    };

    PwgTree.prototype._initDnD = function () {
        var self = this;
        var dragSrcNode = null;

        this.container.addEventListener('dragstart', function (e) {
            var el = e.target.closest('.jqtree-element');
            if (!el) return;
            var li = el.parentElement;
            dragSrcNode = self._getNodeForEl(li);
            if (!dragSrcNode) return;
            self.container.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(dragSrcNode.id));
        });

        this.container.addEventListener('dragover', function (e) {
            e.preventDefault();
            var el = e.target.closest('.jqtree-element');
            if (!el || !dragSrcNode) return;
            var li = el.parentElement;
            var targetNode = self._getNodeForEl(li);
            if (!targetNode || targetNode === dragSrcNode) return;
            var rect = el.getBoundingClientRect();
            var relY = e.clientY - rect.top;
            var pos = relY < rect.height * 0.25 ? 'before' : relY > rect.height * 0.75 ? 'after' : 'inside';
            el.setAttribute('data-drop-pos', pos);
        });

        this.container.addEventListener('dragleave', function (e) {
            var el = e.target.closest('.jqtree-element');
            if (el) el.removeAttribute('data-drop-pos');
        });

        this.container.addEventListener('drop', function (e) {
            e.preventDefault();
            self.container.classList.remove('dragging');
            var el = e.target.closest('.jqtree-element');
            if (!el || !dragSrcNode) { dragSrcNode = null; return; }
            el.removeAttribute('data-drop-pos');
            var li = el.parentElement;
            var targetNode = self._getNodeForEl(li);
            if (!targetNode || targetNode === dragSrcNode) { dragSrcNode = null; return; }
            var rect = el.getBoundingClientRect();
            var relY = e.clientY - rect.top;
            var position = relY < rect.height * 0.25 ? 'before' : relY > rect.height * 0.75 ? 'after' : 'inside';
            var movedNode = dragSrcNode;
            var previousParent = movedNode.parent;
            dragSrcNode = null;

            self._fire('tree.move', {
                move_info: {
                    moved_node: movedNode,
                    target_node: targetNode,
                    previous_parent: previousParent,
                    position: position,
                    do_move: function () {
                        // Remove from old parent
                        var oldParent = movedNode.parent;
                        if (oldParent) {
                            var idx = oldParent.children.indexOf(movedNode);
                            if (idx !== -1) oldParent.children.splice(idx, 1);
                        }
                        if (movedNode._el && movedNode._el.parentNode) {
                            movedNode._el.parentNode.removeChild(movedNode._el);
                        }
                        // Insert in new position
                        if (position === 'inside') {
                            movedNode.parent = targetNode;
                            targetNode.children.push(movedNode);
                            var ul = targetNode._el ? targetNode._el.querySelector(':scope > ul') : null;
                            if (!ul) {
                                ul = document.createElement('ul');
                                ul.className = 'jqtree_common';
                                if (targetNode._el) targetNode._el.appendChild(ul);
                            }
                            ul.style.display = '';
                            ul.appendChild(movedNode._el);
                        } else {
                            var newParent = targetNode.parent || self._root;
                            movedNode.parent = newParent;
                            var siblings = newParent.children;
                            var tIdx = siblings.indexOf(targetNode);
                            if (position === 'before') {
                                siblings.splice(tIdx, 0, movedNode);
                                targetNode._el.parentNode.insertBefore(movedNode._el, targetNode._el);
                            } else {
                                siblings.splice(tIdx + 1, 0, movedNode);
                                targetNode._el.parentNode.insertBefore(movedNode._el, targetNode._el.nextSibling);
                            }
                        }
                    }
                }
            });
        });

        this.container.addEventListener('dragend', function () {
            self.container.classList.remove('dragging');
            dragSrcNode = null;
        });
    };

    PwgTree.prototype._getNodeForEl = function (li) {
        for (var id in this._nodes) {
            if (this._nodes[id]._el === li) return this._nodes[id];
        }
        return null;
    };

    window.PwgTree = PwgTree;
})(window);
