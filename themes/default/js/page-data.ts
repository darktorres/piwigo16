interface PwgPageDataPayload {
	data: Record<string, unknown>;
	strings: Record<string, string>;
}

let pwg_pageDataPayload: PwgPageDataPayload | null = null;

function pwg_getPageDataPayload(): PwgPageDataPayload
{
	if (pwg_pageDataPayload === null)
	{
		const el = document.getElementById('page-data');
		pwg_pageDataPayload = el ? JSON.parse(el.textContent ?? '{}') : { data: {}, strings: {} };
	}
	return pwg_pageDataPayload!;
}

function pwg_getPageData(key: string): any
{
	return pwg_getPageDataPayload().data[key];
}

function pwg_getPageString(key: string): string
{
	return pwg_getPageDataPayload().strings[key]!;
}

// Explicit `window.` exposure -- required, not decorative. Vite/Rollup
// bundles each converted entry as its own isolated module graph; a
// top-level function with no call site *inside this same file* looks
// like dead (or at least renamable-with-impunity) private state to
// Rollup's tree-shaker/minifier, even though every other page script
// calls it as a bare global. Found only by actually running `vite
// build` and inspecting dist/ output -- `bun run typecheck` has no way
// to see this, since it type-checks the whole themes/**/*.ts program
// as one unit and never notices per-entry bundling boundaries. Every
// later P46 batch must check for this the same way (grep every
// candidate file's declared names against the rest of themes/**/*.js
// for cross-file bare reads) rather than assume a rename+entry alone
// is enough.
window.pwg_getPageData = pwg_getPageData;
window.pwg_getPageString = pwg_getPageString;
