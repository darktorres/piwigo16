export {};

if (window.opener || window.name) {
	jQuery("#closeLink").show();
	jQuery("#homeLink").hide();
}
