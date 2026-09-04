import type { operations } from "../../../../openapi/client/schema";
import { ajax, AjaxError } from "../../../default/js/vendor/ajax";
import { closeColorbox, colorbox } from "../../../default/js/vendor/colorbox";
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
import { getSelectizeInstance } from "../../../default/js/vendor/selectize";

interface AlbumOptionData extends Record<string, unknown> {
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

export function pwgAddAlbum(trigger: Element, rawOptions?: PwgAddAlbumOptions) {
  const options = rawOptions ?? {};

  const popup = document.querySelector("#addAlbumForm")!;
  const albumParent = popup.querySelector<HTMLSelectElement>(
    '[name="category_parent"]',
  )!;
  const target = document.querySelector<HTMLSelectElement>(
    '[name="' + String(data(trigger, "addAlbum")) + '"]',
  );
  // LocalStorageCache.ts's own _selectize() stashes the owning Cache
  // instance via `setData(el, "cache", this)` (P49-B group 6) -- the
  // same native data() store this file already uses elsewhere.
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified above.
  const cache = (target === null ? undefined : data(target, "cache")) as {
    selectize(
      target: Element | ArrayLike<Element>,
      options?: Record<string, unknown>,
    ): void;
  };

  if (target && !getSelectizeInstance(target)) {
    throw new Error("pwgAddAlbum: target must use selectize");
  }
  // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition, @typescript-eslint/strict-boolean-expressions -- real runtime guard: the `as {...}` cast above forces this to look non-nullable to TS, but the ternary it casts really can produce undefined (target === null, or no cache ever stashed via setData).
  if (!cache) {
    throw new Error("pwgAddAlbum: missing categories cache");
  }

  function init() {
    setData(popup, "init", true);

    cache.selectize(albumParent, {
      default: 0,
      filter: function (this: unknown, categories: AlbumOptionData[]) {
        categories.push({
          id: 0,
          fullname: "------------",
          global_rank: 0,
        });

        if (options.filter) {
          return options.filter.call(this, categories);
        }

        return categories;
      },
    });

    on(popup.querySelectorAll("form"), "submit", function (event: Event): void {
      event.preventDefault();

      const parentId = val(albumParent);
      const nameInput = popup.querySelector<HTMLInputElement>(
        "[name=category_name]",
      )!;
      const name = val(nameInput);

      if (name === undefined || name === "") {
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

      void (async () => {
        css(
          document.querySelectorAll("#albumCreationLoading"),
          "display",
          "inline-block",
        );
        hide(document.querySelectorAll(".albumCreationButton"));

        try {
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
          const response = (await ajax({
            url: "api/v1/categories",
            type: "POST",
            json: {
              parentId: Number(parentId),
              name: name,
            },
            headers: {
              "X-CSRF-Token": String(
                val(document.querySelectorAll("input[name=pwg_token]")),
              ),
            },
            dataType: "json",
          })) as operations["categoryCreate"]["responses"][201]["content"]["application/json"];

          hide(document.querySelectorAll("#albumCreationLoading"));
          show(document.querySelectorAll(".albumCreationButton"));
          closeColorbox();

          const newAlbum: AlbumOptionData = {
            id: response.id,
            name: name,
            fullname: name,
            global_rank: "0",
            dir: null,
            nb_images: 0,
            pos: 0,
          };

          const parentSelectize = getSelectizeInstance<
            string | number,
            AlbumOptionData
          >(albumParent)!;

          // Was `parentId != 0` -- a loose comparison TS accepted when
          // .val() was jQuery's own loosely-typed return value. Our val()
          // is concretely `string | undefined`, over which TS correctly
          // refuses `!= number` as unrelated types; Number() makes the
          // exact same coercion explicit instead (Number(undefined) is
          // NaN, so the "no value selected" case still takes this branch).
          if (Number(parentId) !== 0) {
            const parent = parentSelectize.options[String(parentId)]!;
            newAlbum.fullname = parent.fullname + " / " + newAlbum.fullname;
            newAlbum.global_rank = String(parent.global_rank) + ".1";
            newAlbum.pos = (parent.pos ?? 0) + 1;
          }

          const targetSelectize = getSelectizeInstance<
            string | number,
            AlbumOptionData
          >(target!)!;
          targetSelectize.addOption(newAlbum);
          targetSelectize.setValue(newAlbum.id);

          parentSelectize.addOption(newAlbum);

          if (options.afterSelect) {
            options.afterSelect();
          }
        } catch (e) {
          hide(document.querySelectorAll("#albumCreationLoading"));
          // Was the ajax() error callback's own 3rd (`errorThrown`) param --
          // `response.statusText` for a real HTTP failure, or the literal
          // "Invalid JSON" for a parse failure -- exactly what AjaxError's
          // own `.message` is constructed from (see vendor/ajax.ts).
          alert(e instanceof AjaxError ? e.message : String(e));
        }
      })();
    });
  }

  colorbox(trigger, {
    inline: true,
    href: "#addAlbumForm",
    width: 650,
    height: "auto",
    onComplete: function () {
      if (data(popup, "init") !== true) {
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
      const targetValue = val(target ?? []);
      getSelectizeInstance(albumParent)?.setValue(
        targetValue === undefined || targetValue === "" ? 0 : targetValue,
      );
    },
  });
}
