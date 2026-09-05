import type { operations } from "../../../../openapi/client/schema";
import { ajax, AjaxError } from "../../../default/js/vendor/utils/ajax";
import {
  closeColorbox,
  colorbox,
} from "../../../default/js/vendor/widgets/colorbox";
import {
  css,
  data,
  hide,
  on,
  setData,
  setVal,
  show,
  val,
} from "../../../default/js/vendor/utils/dom";
import { getSelectizeInstance } from "../../../default/js/vendor/widgets/selectize";

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

  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- the page's own "#addAlbumForm" popup is always real.
  const popup = document.querySelector("#addAlbumForm")!;
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- "#addAlbumForm" always renders its own real category_parent select.
  const albumParent = popup.querySelector<HTMLSelectElement>(
    '[name="category_parent"]',
  )!;
  const target = document.querySelector<HTMLSelectElement>(
    '[name="' + String(data(trigger, "addAlbum")) + '"]',
  );
  // LocalStorageCache.ts's own _selectize() stashes the owning Cache
  // instance via `setData(el, "cache", this)` (P49-B group 6) -- the
  // same native data() store this file already uses elsewhere.
  const cache =
    target === null
      ? undefined
      : data<{
          selectize(
            target: Element | ArrayLike<Element>,
            options?: Record<string, unknown>,
          ): void;
        }>(target, "cache");

  if (target && !getSelectizeInstance(target)) {
    throw new Error("pwgAddAlbum: target must use selectize");
  }
  // P51-Q's own data<T>() retype made this a real, TS-visible guard --
  // it used to need its own no-unnecessary-condition/strict-boolean-
  // expressions disable, back when the untyped `data()` result was
  // force-cast to look non-nullable here.
  if (target === null || !cache) {
    throw new Error("pwgAddAlbum: missing categories cache");
  }
  // Real invariant, not TS-provable from the checks above alone: `cache`
  // is only ever non-undefined when `target` is non-null (its own
  // ternary above), so this point is unreachable with a null target --
  // narrowed explicitly here so the closures below (which lose plain
  // CFA narrowing across a function boundary) can use it directly.
  const targetEl = target;
  const cacheEl = cache;

  function init() {
    setData(popup, "init", true);

    cacheEl.selectize(albumParent, {
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
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- "#addAlbumForm" always renders its own real category_name input.
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
          const response = await ajax<
            operations["categoryCreate"]["responses"][201]["content"]["application/json"]
          >({
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
          });

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

          // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- init()'s own cache.selectize(albumParent, ...) call above always initializes it.
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
            // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- parentId is always a value the user actually selected from parentSelectize's own options.
            const parent = parentSelectize.options[String(parentId)]!;
            newAlbum.fullname = parent.fullname + " / " + newAlbum.fullname;
            newAlbum.global_rank = String(parent.global_rank) + ".1";
            newAlbum.pos = (parent.pos ?? 0) + 1;
          }

          // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- target must use selectize was already verified above (targetEl's own guard).
          const targetSelectize = getSelectizeInstance<
            string | number,
            AlbumOptionData
          >(targetEl)!;
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
          // own `.message` is constructed from (see vendor/utils/ajax.ts).
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
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- "#addAlbumForm" always renders its own real category_name input.
      const nameInput = popup.querySelector<HTMLInputElement>(
        "[name=category_name]",
      )!;
      setVal(nameInput, "");
      nameInput.focus();
      const targetValue = val(targetEl);
      getSelectizeInstance(albumParent)?.setValue(
        targetValue === undefined || targetValue === "" ? 0 : targetValue,
      );
    },
  });
}
