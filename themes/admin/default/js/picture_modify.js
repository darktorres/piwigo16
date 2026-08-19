var related_categories_ids = pwg_getPageData('related_categories_ids');
var str_assoc_album_ab = pwg_getPageString('Associate to album');
var str_orphan = pwg_getPageString('This photo is an orphan');

(function(){
	// <!-- CATEGORIES -->
	var categoriesCache = new CategoriesCache({
		serverKey: pwg_getPageData('cache_key_categories'),
		serverId: pwg_getPageData('cache_key_hash'),
		rootUrl: pwg_getPageData('root_url')
	});

	categoriesCache.selectize(jQuery('[data-selectize=categories]'));

	// <!-- TAGS -->
	var tagsCache = new TagsCache({
		serverKey: pwg_getPageData('cache_key_tags'),
		serverId: pwg_getPageData('cache_key_hash'),
		rootUrl: pwg_getPageData('root_url')
	});

	tagsCache.selectize(jQuery('[data-selectize=tags]'), { lang: {
		'Add': pwg_getPageString('Create')
	}});

	// <!-- DATEPICKER -->
	jQuery(function(){ // onLoad needed to wait localization loads
		jQuery('[data-datepicker]').pwgDatepicker({
			showTimepicker: true,
			cancelButton: pwg_getPageString('Cancel')
		});
	});

	// <!-- THUMBNAILS -->
	jQuery("a.preview-box").colorbox({
		photo: true
	});

	var str_are_you_sure = pwg_getPageString('Are you sure?');
	var str_yes = pwg_getPageString('Yes, delete');
	var str_no = pwg_getPageString('No, I have changed my mind');
	var url_delete = pwg_getPageData('u_delete');

	$('#action-delete-picture').on('click', function() {
		$.confirm({
			title: str_are_you_sure,
			draggable: false,
			titleClass: "groupDeleteConfirm",
			theme: "modern",
			content: "",
			animation: "zoom",
			boxWidth: '30%',
			useBootstrap: false,
			type: 'red',
			animateFromElement: false,
			backgroundDismiss: true,
			typeAnimated: false,
			buttons: {
				confirm: {
					text: str_yes,
					btnClass: 'btn-red',
					action: function () {
						window.location.href = url_delete.replaceAll('amp;', '');
					}
				},
				cancel: {
					text: str_no
				}
			}
		});
	})

}());

$(document).ready(function () {
  const ab = new AlbumSelector({
    selectedCategoriesIds: related_categories_ids,
    selectAlbum: add_related_category,
    removeSelectedAlbum: remove_related_category,
    adminMode: true,
    modalTitle: str_assoc_album_ab,
  });

  $(".linked-albums.add-item").on("click", function () {
    ab.open();
  });

  $('.related-categories-container').on('click', (e) => {
    if (e.target.classList.contains("remove-item")) {
      ab.remove_selected_album($(e.target).attr('id'));
    }
  });

  // Unsaved settings message before leave this page
  let form_unsaved = false;
  let user_interacted = false;
  $('#pictureModify').find(':input').on('focus', function () {
    user_interacted = true;
  });
  $('#pictureModify').find(':input').on('change', function () {
    if (user_interacted) {
      form_unsaved = true;
      console.log($(this)[0].name, $(this));
    }
  });
  $(window).on('beforeunload', function () {
    if (form_unsaved) {
      return 'Somes changes are not registered';
    }
  });
  $('#pictureModify').on('submit', function () {
    form_unsaved = false;
  });
})

function remove_related_category({ id_album, getSelectedAlbum }) {
  $(".invisible-related-categories-select option[value="+ id_album +"]").remove();
  $(".invisible-related-categories-select").trigger('change');
  $("#" + id_album).parent().remove();
  check_related_categories(getSelectedAlbum());
}

function add_related_category({ album, addSelectedAlbum, getSelectedAlbum }) {
  if (!getSelectedAlbum().includes(album.id)) {
    $(".related-categories-container").append(
      `<div class="breadcrumb-item">
        <span class="link-path">${album.full_name_with_admin_links}</span><span id="${album.id}" class="icon-cancel-circled remove-item"></span>
      </div>`
    );

    $(".search-result-item #" + album.id).addClass("notClickable");
    $(".invisible-related-categories-select").append("<option selected value="+ album.id +"></option>").trigger('change');
    addSelectedAlbum();
  }

  check_related_categories(getSelectedAlbum());
}

function check_related_categories(selected_cat) {
  $(".linked-albums-badge").html(selected_cat.length);

  if (selected_cat.length == 0) {
    $(".linked-albums-badge").addClass("badge-red");
    $(".add-item").addClass("highlight");
    $(".orphan-photo").html(str_orphan).show();
  } else {
    $(".linked-albums-badge.badge-red").removeClass("badge-red");
    $(".add-item.highlight").removeClass("highlight");
    $(".orphan-photo").hide();
  }
}