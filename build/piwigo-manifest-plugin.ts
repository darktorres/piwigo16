import type { Plugin } from 'vite';
import { writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

interface PiwigoManifestEntry {
    file: string;
    imports: string[];
    css: string[];
}

interface ChunkInfo {
    name?: string;
    isEntry: boolean;
    imports: string[];
    cssDirect: string[];
}

export function piwigoManifestPlugin(): Plugin {
    // Indexed by emitted JS filename so we can resolve `chunk.imports` references.
    const chunkByFile = new Map<string, ChunkInfo>();

    return {
        name: 'piwigo-manifest',
        apply: 'build',

        generateBundle(_opts, bundle) {
            chunkByFile.clear();
            for (const [fileName, chunk] of Object.entries(bundle)) {
                if (chunk.type !== 'chunk') continue;
                const meta = (chunk as { viteMetadata?: { importedCss?: Set<string> } })
                    .viteMetadata;
                chunkByFile.set(fileName, {
                    name: chunk.isEntry ? chunk.name : undefined,
                    isEntry: chunk.isEntry,
                    imports: chunk.imports.filter((f) => !f.endsWith('.css')),
                    cssDirect: [...(meta?.importedCss ?? new Set<string>())],
                });
            }
        },

        async writeBundle({ dir }) {
            const outDir = dir ?? 'dist';
            const piwigoManifest: Record<string, PiwigoManifestEntry> = {};

            for (const [fileName, info] of chunkByFile) {
                if (!info.isEntry || info.name === undefined) continue;

                // Walk transitive imports so CSS attached to shared vendor
                // chunks (e.g. nouislider, tippy, tom-select) reaches the
                // entry's css[] — otherwise pages that import those side-effect
                // stylesheets ship with no styling.
                const css = new Set<string>();
                const visited = new Set<string>();
                const stack: string[] = [fileName];
                while (stack.length > 0) {
                    const cur = stack.pop()!;
                    if (visited.has(cur)) continue;
                    visited.add(cur);
                    const ci = chunkByFile.get(cur);
                    if (ci === undefined) continue;
                    for (const c of ci.cssDirect) css.add(c);
                    for (const imp of ci.imports) stack.push(imp);
                }

                piwigoManifest[info.name] = {
                    file: fileName,
                    imports: info.imports,
                    css: [...css],
                };
            }

            await writeFile(
                resolve(outDir, 'manifest.json'),
                JSON.stringify(piwigoManifest, null, 2)
            );
        },
    };
}
