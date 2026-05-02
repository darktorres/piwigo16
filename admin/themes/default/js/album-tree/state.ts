// Persistence of open-node ids in localStorage. Stored as a JSON array of
// stringified ids under a single key derived from the caller's saveStateKey.

import type { NodeId } from './types';

export class TreeStateStore {
    private storageKey: string;

    constructor(saveKey: string) {
        this.storageKey = `pwg_album_tree_open_${saveKey}`;
    }

    load(): Set<string> {
        try {
            const raw = window.localStorage?.getItem(this.storageKey);
            if (!raw) return new Set();
            const arr = JSON.parse(raw);
            return new Set(Array.isArray(arr) ? arr.map(String) : []);
        } catch {
            return new Set();
        }
    }

    save(open: Iterable<string | NodeId>): void {
        try {
            window.localStorage?.setItem(this.storageKey, JSON.stringify(Array.from(open, String)));
        } catch {
            // Quota exceeded or storage disabled — silently degrade.
        }
    }
}
