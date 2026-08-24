// Shared ambient types for vendored jQuery-plugin methods that
// @types/jquery doesn't cover (docs/PLAN.md P46). `JQueryStatic`/`JQuery`
// are already global interfaces (see node_modules/@types/jquery), so
// merging into them here needs no `declare global` wrapper -- this file
// has no top-level import/export of its own.
//
// Also the home for genuinely first-party (non-jQuery-plugin) shared
// ambient globals, when a real one turns up during conversion (P46-B
// found window.SwitchBox needs exactly this).
//
// Starts empty -- grows one method/global at a time as each conversion
// batch's real call sites need it, rather than guessing the full list
// up front.

interface Window {
	// `index.ts`/`picture.ts` push onto this before `switchbox.ts` (loaded
	// later, footer-positioned) replaces it with a live `{push: fn}`
	// handler -- same shape-shifting "queue array, then live handler"
	// pattern as `_pwgRatingAutoQueue` below. `any` rather than a modeled
	// union: both the array-push and live-handler-push call sites already
	// agree on "2 string/Element args," so a precise union buys no real
	// safety here.
	SwitchBox?: any;

	// `picture.ts` pushes a rating-options object onto this (queue array)
	// if `rating.ts` hasn't loaded yet; `rating.ts`'s own IIFE drains the
	// queue then replaces it with a live `{push: fn}` handler for any
	// later pusher. Same shape-shifting pattern as `SwitchBox` above.
	// Started as a bare (non-`window.`) global, matching the original
	// pre-P46 .js -- 2 real bugs found in sequence via VR against a real
	// browser before landing here: a bare undeclared read threw
	// ReferenceError whenever picture.ts ran first, and `var`-declaring
	// it "fixed" that but broke it a second way once every P46 entry got
	// wrapped in its own IIFE (see vite.config.ts's banner/footer
	// comment) -- a `var` inside that wrapper is scoped to the wrapper,
	// no longer a real global at all. `window.` property access (here,
	// like `SwitchBox` above) is the one form that's safe both ways.
	_pwgRatingAutoQueue?: any;

	// Explicit `window.` exposure of these 6 names is required for the
	// same reason documented at each assignment site (page-data.ts,
	// scripts.ts): Vite/Rollup bundles each P46 entry as its own isolated
	// module graph, and a top-level declaration with no call site *inside
	// its own file* looks like dead/private state to the tree-shaker and
	// minifier alike, even though sibling entries call it as a bare
	// global. Declared here (not inferred from the assignment) so every
	// consumer file's own bare reference -- e.g. `pwg_getPageData(...)`
	// in picture.ts, which never imports anything -- type-checks too.
	pwg_getPageData: (key: string) => any;
	pwg_getPageString: (key: string) => string;
	phpWGOpenWindow: (theURL: string, winName: string, features: string) => void;
	popuphelp: (url: string) => void;
	pwgAddEventListener: (elem: EventTarget, evt: string, fn: EventListenerOrEventListenerObject) => void;
	pwg_tryFocus: (id: string) => void;

	// search_filters.ts's own page-data-derived globals, all real bare
	// reads confirmed in (still-.js, not yet type-checked) mcs.js --
	// `any` throughout: these are page-data-JSON-derived or translated
	// strings, not first-party logic this phase re-derives real types
	// for (that's P48's job, not P46's).
	global_params: any;
	fullname_of_cat: any;
	search_id: any;
	str_word_widget_label: any;
	str_tags_widget_label: any;
	str_album_widget_label: any;
	str_author_widget_label: any;
	str_added_by_widget_label: any;
	str_filetypes_widget_label: any;
	str_rating_widget_label: any;
	str_no_rating: any;
	str_between_rating: any;
	str_filesize_widget_label: any;
	str_width_widget_label: any;
	str_height_widget_label: any;
	str_ratio_widget_label: any;
	str_ratios_label: any;
	str_expert_widget_label: any;
	str_empty_search_top_alt: any;
	str_empty_search_bot_alt: any;
	str_search_in_ab: any;
	prefix_icon: any;
	sliders: any;
	show_filter_ratings: any;
}

// These 4 (declared as real functions in page-data.ts/scripts.ts,
// exposed onto `window` there) are also called as *bare* identifiers by
// consumer files (picture.ts calls `phpWGOpenWindow`, rating.ts calls
// `pwgAddEventListener`, several call `pwg_getPageData`/
// `pwg_getPageString`) -- those files never write `window.` themselves,
// relying on the same "global script, no module scope" assumption every
// pre-P46 .js file already relied on. Declaring them as ambient
// `declare function` bindings (not just `Window` properties above) is
// what makes the bare reference type-check in every *consuming* file.
// Add the other 2 (`popuphelp`/`pwg_tryFocus`) here too the first time a
// converted .ts file actually calls one of them bare -- no real
// consumer yet, so no ambient binding needed for them yet either.
declare function pwg_getPageData(key: string): any;
declare function pwg_getPageString(key: string): string;
declare function phpWGOpenWindow(theURL: string, winName: string, features: string): void;
declare function pwgAddEventListener(elem: EventTarget, evt: string, fn: EventListenerOrEventListenerObject): void;

interface JQueryStatic {
	// jquery.ajaxmanager (vendored, never published to npm -- P46-0's own
	// CDN table) -- `thumbnails.loader.ts`'s own queued-thumbnail loader is
	// the one real first-party call site.
	manageAjax: {
		create(name: string, options: Record<string, unknown>): {
			add(options: Record<string, unknown>): void;
		};
	};
}
