import type { operations } from "../../../../openapi/client/schema";

export {};

interface AlbumOptionData {
  id: string | number;
  fullname: string;
  global_rank: string | number;
  // Only the real, fully-constructed `newAlbum` object below sets these
  // -- the "root album" sentinel option this file's own filter callback
  // injects doesn't (same shape it always had).
  name?: string;
  dir?: string | null;
  nb_images?: number;
  pos?: number;
}

interface PwgAddAlbumOptions {
  filter?: (categories: AlbumOptionData[]) => AlbumOptionData[];
  afterSelect?: () => void;
}

jQuery.fn.pwgAddAlbum = function (this: JQuery, options?: PwgAddAlbumOptions) {
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
  const $popup = jQuery("#addAlbumForm"),
    $albumParent = $popup.find('[name="category_parent"]'),
    $button = jQuery(this),
    $target = jQuery('[name="' + $button.data("addAlbum") + '"]'),
    cache = $target.data("cache") as {
      selectize(target: JQuery, options?: Record<string, unknown>): void;
    };

  console.log(cache);

  if ($target[0] && !$target[0].selectize) {
    jQuery.error("pwgAddAlbum: target must use selectize");
  }
  if (!cache) {
    jQuery.error("pwgAddAlbum: missing categories cache");
  }

  function init() {
    $popup.data("init", true);

    cache.selectize($albumParent, {
      default: 0,
      filter: function (this: unknown, categories: AlbumOptionData[]) {
        categories.push({
          id: 0,
          fullname: "------------",
          global_rank: 0,
        });

        if (options!.filter) {
          categories = options!.filter.call(this, categories);
        }

        return categories;
      },
    });

    $popup.find("form").on("submit", function (e) {
      e.preventDefault();

      const parent_id = $albumParent.val(),
        name = $popup.find("[name=category_name]").val();

      if (!name) {
        jQuery("#categoryNameError").css("visibility", "visible");
        return;
      }
      jQuery("#categoryNameError").css("visibility", "hidden");

      jQuery.ajax({
        url: "api/v1/categories",
        type: "POST",
        contentType: "application/json",
        headers: {
          "X-CSRF-Token": String(jQuery("input[name=pwg_token]").val()),
        },
        dataType: "json",
        data: JSON.stringify({
          parentId: Number(parent_id),
          name: name,
        }),
        beforeSend: function () {
          jQuery("#albumCreationLoading").css("display", "inline-block");
          jQuery(".albumCreationButton").hide();
        },
        success: function (
          data: operations["categoryCreate"]["responses"][201]["content"]["application/json"],
        ) {
          jQuery("#albumCreationLoading").hide();
          jQuery(".albumCreationButton").show();
          // Real Colorbox bug found via retyping: `.close()` only exists
          // as a *static* method on `jQuery.colorbox` itself, never as a
          // per-element property -- confirmed via @types/jquery.colorbox's
          // own ColorboxStatic interface. Fixed to the documented form.
          jQuery.colorbox.close();

          const newAlbum: AlbumOptionData = {
            id: data.id,
            name: String(name),
            fullname: String(name),
            global_rank: "0",
            dir: null,
            nb_images: 0,
            pos: 0,
          };

          const parentSelectize = $albumParent[0]!.selectize as Selectize.IApi<
            string | number,
            AlbumOptionData
          >;

          if (parent_id != 0) {
            const parent = parentSelectize.options[String(parent_id)]!;
            newAlbum.fullname = parent.fullname + " / " + newAlbum.fullname;
            newAlbum.global_rank = parent.global_rank + ".1";
            newAlbum.pos = (parent.pos ?? 0) + 1;
          }

          const targetSelectize = $target[0]!.selectize;
          targetSelectize.addOption(newAlbum);
          targetSelectize.setValue(newAlbum.id);

          parentSelectize.addOption(newAlbum);

          if (options!.afterSelect) {
            options!.afterSelect();
          }
        },
        error: function (
          XMLHttpRequest: JQuery.jqXHR,
          textStatus: string,
          errorThrows: string,
        ) {
          jQuery("#albumCreationLoading").hide();
          alert(errorThrows);
        },
      });
    });
  }

  this.colorbox({
    inline: true,
    href: "#addAlbumForm",
    width: 650,
    height: "auto",
    onComplete: function () {
      if (!$popup.data("init")) {
        init();
      }

      jQuery("#categoryNameError").css("visibility", "hidden");
      $popup.find("[name=category_name]").val("").focus();
      $albumParent[0]!.selectize.setValue($target.val() || 0);
    },
  });

  return this;
};
