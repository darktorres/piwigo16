import type { Plugin } from 'vite';
import type { OutputChunk } from 'rolldown';
import { writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

interface PiwigoManifestEntry {
    file: string;
    imports: string[];
    css: string[];
}

export function piwigoManifestPlugin(): Plugin {
    const chunkMap = new Map<
        string,
        { name: string; file: string; imports: string[]; css: string[] }
    >();

    return {
        name: 'piwigo-manifest',
        apply: 'build',

        generateBundle(_opts, bundle) {
            chunkMap.clear();
            for (const [fileName, chunk] of Object.entries(bundle)) {
                if (chunk.type !== 'chunk' || !chunk.isEntry) continue;
                const cssFiles = [
                    ...((chunk as any).viteMetadata?.importedCss ?? new Set<string>()),
                ];
                chunkMap.set(fileName, {
                    name: chunk.name,
                    file: fileName,
                    imports: (chunk as OutputChunk).imports.filter((f) => !f.endsWith('.css')),
                    css: cssFiles,
                });
            }
        },

        async writeBundle({ dir }) {
            const outDir = dir ?? 'dist';
            const piwigoManifest: Record<string, PiwigoManifestEntry> = {};

            for (const [, entry] of chunkMap) {
                piwigoManifest[entry.name] = {
                    file: entry.file,
                    imports: entry.imports,
                    css: entry.css,
                };
            }

            await writeFile(
                resolve(outDir, 'manifest.json'),
                JSON.stringify(piwigoManifest, null, 2)
            );
        },
    };
}
