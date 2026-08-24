export {};

// `GroupsCache`/`UsersCache` are only reachable via `window.` --
// LocalStorageCache.ts wraps them in its own real, pre-existing IIFE
// (same reasoning as every other GroupsCache/UsersCache/
// CategoriesCache/TagsCache consumer this session).
(function() {
	// <!-- GROUPS -->
	const groupsCache = new window.GroupsCache({
		serverKey: pwg_getPageData('cache_key_groups'),
		serverId: pwg_getPageData('cache_key_hash'),
		rootUrl: pwg_getPageData('root_url')
	});

	groupsCache.selectize(jQuery('[data-selectize=groups]'));

	// <!-- USERS -->
	const usersCache = new window.UsersCache({
		serverKey: pwg_getPageData('cache_key_users'),
		serverId: pwg_getPageData('cache_key_hash'),
		rootUrl: pwg_getPageData('root_url')
	});

	usersCache.selectize(jQuery('[data-selectize=users]'));

	// <!-- TOGGLES -->
	function checkStatusOptions() {
		if (jQuery("input[name=status]:checked").val() === "private") {
			jQuery("#privateOptions").show();
		}
		else {
			jQuery("#privateOptions").hide();
		}
	}

	checkStatusOptions();
	jQuery("#selectStatus").change(function() {
		checkStatusOptions();
	});

	jQuery(".toggle-indirectPermissions").click(function(e){
		jQuery(".toggle-indirectPermissions").toggle();
		jQuery("#indirectPermissionsDetails").toggle();
		e.preventDefault();
	});
}());
