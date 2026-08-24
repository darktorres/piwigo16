export {};

jQuery.fn.pwgAddAlbum = function(this: JQuery, options: any) {
  options = options || {};

  // Genuine pre-existing bug, TS-forced fix (not just a type gap): a
  // missing comma here made TS's parser (matching how a real JS parser
  // treats this too, confirmed by the actual compile errors before
  // this fix) end the `var` statement after `$albumParent`, leaving
  // `$button`/`$target`/`cache` as bare, undeclared assignments --
  // sloppy-mode implicit globals at runtime (never intentional, and
  // confirmed via grep that nothing anywhere reads
  // `window.$button`/`window.$target`/`window.cache`). Restored the
  // clearly-intended single continuous `var` list.
  const $popup = jQuery('#addAlbumForm'),
      $albumParent = $popup.find('[name="category_parent"]'),
      $button = jQuery(this),
      $target = jQuery('[name="'+ $button.data('addAlbum') +'"]'),
      cache = $target.data('cache');

      console.log(cache);

  if ($target[0] && !($target[0] as any).selectize) {
    jQuery.error('pwgAddAlbum: target must use selectize');
  }
  if (!cache) {
    jQuery.error('pwgAddAlbum: missing categories cache');
  }

  function init() {
    $popup.data('init', true);

    cache.selectize($albumParent, {
      'default': 0,
      'filter': function(this: any, categories: any[]) {
        categories.push({
          id: 0,
          fullname: '------------',
          global_rank: 0
        });

        if (options.filter) {
          categories = options.filter.call(this, categories);
        }

        return categories;
      }
    });

    $popup.find('form').on('submit', function(e) {
      e.preventDefault();

      const parent_id = $albumParent.val(),
      name = $popup.find('[name=category_name]').val();

      if (!name) {
        jQuery('#categoryNameError').css('visibility', 'visible');
        return;
      }
      jQuery('#categoryNameError').css('visibility', 'hidden');

      jQuery.ajax({
        url: 'api/v1/categories',
        type: 'POST',
        contentType: 'application/json',
        headers: {'X-CSRF-Token': String(jQuery("input[name=pwg_token]").val())},
        dataType: 'json',
        data: JSON.stringify({
          parentId: Number(parent_id),
          name: name
        }),
        beforeSend: function() {
          jQuery('#albumCreationLoading').css('display', 'inline-block');
          jQuery('.albumCreationButton').hide();
        },
        success: function(data: any) {
          jQuery('#albumCreationLoading').hide();
          jQuery('.albumCreationButton').show();
          ($button as any).colorbox.close();

          const newAlbum: Record<string, any> = {
            id: data.id,
            name: name,
            fullname: name,
            global_rank: '0',
            dir: null,
            nb_images: 0,
            pos: 0
          };

          const parentSelectize = ($albumParent[0] as any).selectize;

          if (parent_id != 0) {
            const parent = parentSelectize.options[parent_id as any];
            newAlbum.fullname = parent.fullname + ' / ' + newAlbum.fullname;
            newAlbum.global_rank = parent.global_rank + '.1';
            newAlbum.pos = parent.pos + 1;
          }

          const targetSelectize = ($target[0] as any).selectize;
          targetSelectize.addOption(newAlbum);
          targetSelectize.setValue(newAlbum.id);

          parentSelectize.addOption(newAlbum);

          if (options.afterSelect) {
            options.afterSelect();
          }
        },
        error: function(XMLHttpRequest: any, textStatus: any, errorThrows: any) {
            jQuery('#albumCreationLoading').hide();
            alert(errorThrows);
        }
      });
    });
  }

  this.colorbox({
    inline: true,
    href: '#addAlbumForm',
    width: 650, height: 'auto',
    onComplete: function() {
      if (!$popup.data('init')) {
        init();
      }

      jQuery('#categoryNameError').css('visibility','hidden');
      $popup.find('[name=category_name]').val('').focus();
      ($albumParent[0] as any).selectize.setValue($target.val() || 0);
    }
  });

  return this;
};
