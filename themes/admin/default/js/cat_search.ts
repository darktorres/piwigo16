// Real consumer of album_selector.ts's own top-level `str_albums_found`/
// `str_result_limit`/`str_album_found` (docs/PLAN.md P48 -- was a bare
// ambient-global read, coincidentally type-checking with no real
// runtime source at all: this page (albums.php/cat_list.php) never
// embedded album_selector.ts's script before this batch, a real
// pre-existing gap, fixed here by AlbumsView.php gaining a real
// registration for this file's own new dependency). `str_album_found`
// specifically is a real, genuinely coincidental duplicate of
// albums.ts's own identically-worded `const str_album_found` --
// imported from album_selector.ts here purely because that's where the
// bare read already (coincidentally) resolved before this batch, not
// because it's the semantically "correct" owner; see album_selector.ts's
// own leading comment.
import {
  str_albums_found,
  str_result_limit,
  str_album_found,
} from "./album_selector";
// Real consumer of albums.ts's own top-level `data` (docs/PLAN.md P48,
// albums.ts's own batch -- was a `window.data` read before that).
// albums.ts stays its own standalone Vite entry, unlike every other
// declarer this campaign folded away, so it is reachable from 2 real
// entries -- its own `albums` entry and this file's own `catSearch`
// entry -- and Rollup emits the shared part as a chunk both import.
import { data } from "./albums";
import {
  fadeIn,
  hide,
  parseHtml,
  ready,
  show,
} from "../../../default/js/vendor/dom";

export {};

// Narrower local shape than albums.ts's own real `AlbumTreeNode` --
// only the fields `searchAlbumByName()` below actually reads. TS's
// structural typing accepts the real (wider) `data` value here without
// a cast either way.
interface AlbumTreeNode {
  id: string | number;
  name: string;
  children?: AlbumTreeNode[];
}

const RESULT_LIMIT = 100;
const editLink = "admin.php?page=album-";
const colors = [
  "icon-red",
  "icon-blue",
  "icon-yellow",
  "icon-purple",
  "icon-green",
];

ready(function () {
  hide(document.querySelectorAll(".limit-album-reached"));

  document.getElementById("cat_search_input")?.addEventListener("input", () => {
    updateSearch();
  });
});

/** jQuery's `.html(value)` writes to every element of the set. */
function setHtml(selector: string, value: string): void {
  document.querySelectorAll(selector).forEach((element) => {
    element.innerHTML = value;
  });
}

// Update the page according to the search field
function updateSearch() {
  // `String($(".search-input").val())` on an empty set is the literal
  // "undefined", a non-empty string that would send this down the search
  // branch looking for albums named "undefined". Kept rather than tidied to
  // "" because it is unreachable either way: `.search-input` and
  // `#cat_search_input` are the same element on this page, and that
  // element's own listener is the only caller.
  const input = document.querySelector<HTMLInputElement>(".search-input");
  const string = input === null ? "undefined" : input.value;
  setHtml(".search-album-result", "");
  hide(document.querySelectorAll(".search-album-noresult"));
  hide(document.querySelectorAll(".limit-album-reached"));
  if (string == "") {
    // help button unnecessary so do not show
    // $('.search-album-help').show();
    show(document.querySelectorAll(".search-album-ghost"));
    hide(document.querySelectorAll(".search-album-num-result"));
    hideSearchContainer();
  } else {
    hide(document.querySelectorAll(".search-album-ghost"));
    hide(document.querySelectorAll(".search-album-help"));
    show(document.querySelectorAll(".search-album-num-result"));
    showSearchContainer();

    let nbResult = 0;

    nbResult = searchAlbumByName(data, string, nbResult);

    if (nbResult != 1) {
      if (nbResult >= RESULT_LIMIT) {
        setHtml(
          ".search-album-num-result",
          str_result_limit.replace("%d", String(nbResult)),
        );
      } else {
        setHtml(
          ".search-album-num-result",
          str_albums_found.replace("%d", String(nbResult)),
        );
      }
    } else {
      setHtml(".search-album-num-result", str_album_found);
    }

    if (nbResult != 0) {
      resultAppear(
        document.querySelector<HTMLElement>(
          ".search-album-result .search-album-elem",
        ),
      );
    } else {
      show(document.querySelectorAll(".search-album-noresult"));
    }
  }
}

function searchAlbumByName(
  categories: AlbumTreeNode[],
  search: string,
  initialNbResult: number,
  children?: boolean,
  name: string = "",
): number {
  let nbResult = initialNbResult;
  for (const c of categories) {
    if (nbResult >= RESULT_LIMIT) {
      return nbResult;
    }

    const currentName =
      name + `<a href="${editLink + String(c.id)}">${c.name}</a>` + " / ";

    if (c.name.toLowerCase().includes(search.toLowerCase())) {
      const haveChild = c.children && c.children.length ? true : false;
      nbResult++;
      addAlbumResult(c, nbResult, haveChild, currentName);
    }

    if (c.children && c.children.length) {
      nbResult = searchAlbumByName(
        c.children,
        search,
        nbResult,
        true,
        currentName,
      );
    }
  }
  return nbResult;
}

// Add an album as a result in the page
function addAlbumResult(
  cat: AlbumTreeNode,
  nbResult: number,
  haveChildren: boolean,
  name: string,
) {
  const id = Number(cat.id);
  const template = document.querySelector(".search-album-elem-template");
  const newCatNodes = parseHtml(template === null ? "" : template.innerHTML);

  for (const newCatNode of newCatNodes) {
    // `.find()` is every matching descendant, not the first.
    newCatNode.querySelectorAll(".search-album-icon").forEach((icon) => {
      icon.classList.add(haveChildren ? "icon-sitemap" : "icon-folder-open");
      icon.classList.add(colors[id % 5]!);
    });
    newCatNode.querySelectorAll(".search-album-name").forEach((label) => {
      label.innerHTML = name.slice(0, -2);
    });

    const href = "admin.php?page=album-" + String(id);
    newCatNode.querySelectorAll(".search-album-edit").forEach((edit) => {
      edit.setAttribute("href", href);
    });
  }

  // jQuery appends the nodes themselves to the first container of the set
  // and a clone to each one after it.
  document.querySelectorAll(".search-album-result").forEach((container, i) => {
    for (const newCatNode of newCatNodes) {
      container.appendChild(i === 0 ? newCatNode : newCatNode.cloneNode(true));
    }
  });

  if (nbResult >= RESULT_LIMIT) {
    // `.show(duration)` folds the whole box open -- height, width, opacity,
    // margins and padding together -- not a fade.
    show(document.querySelectorAll(".limit-album-reached"), 1000);
    setHtml(
      ".limit-album-reached",
      str_result_limit.replace("%d", String(nbResult)),
    );
  }
}

// Make the results appear one after one [and limit results to 100]
function resultAppear(result: HTMLElement | null) {
  if (result === null) {
    return;
  }

  fadeIn(result);
  // `.next()` is the next *element* sibling, skipping text nodes.
  const next = result.nextElementSibling;
  if (next !== null) {
    setTimeout(() => {
      resultAppear(next as HTMLElement);
    }, 50);
  }
}

function showSearchContainer() {
  hide(document.querySelectorAll(".tree"));
  show(document.querySelectorAll(".album-search-result-container"));
}

function hideSearchContainer() {
  hide(document.querySelectorAll(".album-search-result-container"));
  fadeIn(document.querySelectorAll(".tree"));
}
