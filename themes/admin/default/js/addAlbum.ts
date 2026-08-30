import type { operations } from "../../../../openapi/client/schema";
import { ajax } from "../../../default/js/vendor/ajax";
import {
  css,
  data,
  hide,
  on,
  setData,
  setVal,
  show,
  val,
} from "../../../default/js/vendor/dom";

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

  const popup = document.querySelector("#addAlbumForm")!;
  const albumParent = popup.querySelector<HTMLSelectElement>(
    '[name="category_parent"]',
  )!;
  const buttonEl = this[0]!;
  const target = document.querySelector<HTMLSelectElement>(
    '[name="' + String(data(buttonEl, "addAlbum")) + '"]',
  );
  // Still jQuery: LocalStorageCache.ts's own _selectize() stashes this via
  // jQuery's OWN internal data cache ($target.data("cache", this)), which
  // is a completely separate store from our native data()/setData() --
  // confirmed live: reading it through data() came back undefined even
  // though LocalStorageCache.ts had already set it, because a jQuery
  // .data(key, value) write never touches the DOM attribute our helper
  // reads and never reaches our own WeakMap-backed store either. Reading
  // it back through jQuery is what LocalStorageCache.ts itself requires
  // until it ports (P49-B group 6).
  const cache = (
    target === null ? undefined : jQuery(target).data("cache")
  ) as {
    selectize(target: JQuery, options?: Record<string, unknown>): void;
  };

  console.log(cache);

  if (target && !target.selectize) {
    jQuery.error("pwgAddAlbum: target must use selectize");
  }
  if (!cache) {
    jQuery.error("pwgAddAlbum: missing categories cache");
  }

  function init() {
    setData(popup, "init", true);

    // Still jQuery: cache.selectize() (LocalStorageCache.ts's own
    // AbstractSelectizer) takes a JQuery target internally -- ported
    // alongside selectize itself in P49-B group 6.
    cache.selectize(jQuery(albumParent), {
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

    on(popup.querySelectorAll("form"), "submit", function (event: Event): void {
      event.preventDefault();

      const parent_id = val(albumParent);
      const nameInput = popup.querySelector<HTMLInputElement>(
        "[name=category_name]",
      )!;
      const name = val(nameInput);

      if (!name) {
        css(
          document.querySelectorAll("#categoryNameError"),
          "visibility",
          "visible",
        );

        return;
      }
      css(
        document.querySelectorAll("#categoryNameError"),
        "visibility",
        "hidden",
      );

      void ajax({
        url: "api/v1/categories",
        type: "POST",
        contentType: "application/json",
        headers: {
          "X-CSRF-Token": String(
            val(document.querySelectorAll("input[name=pwg_token]")),
          ),
        },
        dataType: "json",
        data: JSON.stringify({
          parentId: Number(parent_id),
          name: name,
        }),
        beforeSend: function () {
          css(
            document.querySelectorAll("#albumCreationLoading"),
            "display",
            "inline-block",
          );
          hide(document.querySelectorAll(".albumCreationButton"));
        },
        success: function (
          data: operations["categoryCreate"]["responses"][201]["content"]["application/json"],
        ) {
          hide(document.querySelectorAll("#albumCreationLoading"));
          show(document.querySelectorAll(".albumCreationButton"));
          // Real Colorbox bug found via retyping: `.close()` only exists
          // as a *static* method on `jQuery.colorbox` itself, never as a
          // per-element property -- confirmed via @types/jquery.colorbox's
          // own ColorboxStatic interface. Fixed to the documented form.
          jQuery.colorbox.close();

          const newAlbum: AlbumOptionData = {
            id: data.id,
            name: name,
            fullname: name,
            global_rank: "0",
            dir: null,
            nb_images: 0,
            pos: 0,
          };

          const parentSelectize = albumParent.selectize as Selectize.IApi<
            string | number,
            AlbumOptionData
          >;

          // Was `parent_id != 0` -- a loose comparison TS accepted when
          // .val() was jQuery's own loosely-typed return value. Our val()
          // is concretely `string | undefined`, over which TS correctly
          // refuses `!= number` as unrelated types; Number() makes the
          // exact same coercion explicit instead (Number(undefined) is
          // NaN, so the "no value selected" case still takes this branch).
          if (Number(parent_id) !== 0) {
            const parent = parentSelectize.options[String(parent_id)]!;
            newAlbum.fullname = parent.fullname + " / " + newAlbum.fullname;
            newAlbum.global_rank = parent.global_rank + ".1";
            newAlbum.pos = (parent.pos ?? 0) + 1;
          }

          const targetSelectize = target!.selectize;
          targetSelectize.addOption(newAlbum);
          targetSelectize.setValue(newAlbum.id);

          parentSelectize.addOption(newAlbum);

          if (options!.afterSelect) {
            options!.afterSelect();
          }
        },
        error: function (
          XMLHttpRequest,
          textStatus: string,
          errorThrows: string,
        ) {
          hide(document.querySelectorAll("#albumCreationLoading"));
          alert(errorThrows);
        },
      });
    });
  }

  // Still jQuery: colorbox is a library, ported in P49-B group 3. `this`
  // must stay a real JQuery object for it -- colorbox is $.fn.colorbox.
  this.colorbox({
    inline: true,
    href: "#addAlbumForm",
    width: 650,
    height: "auto",
    onComplete: function () {
      if (!data(popup, "init")) {
        init();
      }

      css(
        document.querySelectorAll("#categoryNameError"),
        "visibility",
        "hidden",
      );
      const nameInput = popup.querySelector<HTMLInputElement>(
        "[name=category_name]",
      )!;
      setVal(nameInput, "");
      nameInput.focus();
      albumParent.selectize.setValue(val(target ?? []) || 0);
    },
  });

  return this;
};
