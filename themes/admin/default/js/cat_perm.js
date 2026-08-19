(function() {
	// <!-- GROUPS -->
	var groupsCache = new GroupsCache({
		serverKey: pwg_getPageData('cache_key_groups'),
		serverId: pwg_getPageData('cache_key_hash'),
		rootUrl: pwg_getPageData('root_url')
	});

	groupsCache.selectize(jQuery('[data-selectize=groups]'));

	// <!-- USERS -->
	var usersCache = new UsersCache({
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
