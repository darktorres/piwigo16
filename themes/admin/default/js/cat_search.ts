export {};

// Consumer of album_selector.ts's own established shared-global set
// (docs/PLAN.md P46-C's full sweep): str_albums_found/str_result_limit,
// both read bare below -- already resolved by album_selector.ts's own
// real `const` declarations, no ambient binding needed (same reasoning
// as intro_tooltips.ts's own copy of this comment).
//
// Also reads `data`/`str_album_found` -- both real top-level `const`s in
// themes/admin/default/js/albums.ts (a real `dependsOn: ['albums']`
// registration -- AlbumsView.php) -- a genuine cross-file relationship
// the plan's own full 61-file sweep missed (both names are exactly the
// kind of generic/short identifier the sweep's own false-positive
// filtering excluded). Correction found by real strict typechecking
// (deferred to the end of the P46 conversion, per session instruction):
// unlike every other declarer-converts-later case, albums.ts *does*
// have `export {}` -- its own real declarations are module-private, not
// ambient, so a bare `data` read here is a genuine TS2304 "Cannot find
// name" compile error (`str_album_found` happened to typecheck anyway,
// coincidentally resolved by album_selector.ts's own independent,
// still-non-module `const str_album_found` -- a landmine for a future
// reader, not a real fix, left alone since it's harmless: same message
// text). Fixed by reading `data` through `window.data` explicitly --
// TS-valid via the shared `Window` interface, and behaviorally
// identical at runtime (a bare, undeclared-in-this-file identifier read
// already resolves through the global object the same way).

// Real shape of albums.ts's own `data` (window.data) tree nodes, as
// actually read here -- albums.ts's own `pwg_getPageData("album_data")`
// stays untyped until its own P47-B turn (a much larger file), but
// `window.data` is `any`-typed in the interim, so passing it here needs
// no cast either way.
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

$(function () {
  $(".limit-album-reached").hide();

  $("#cat_search_input").on("input", () => {
    updateSearch();
  });
});

// Update the page according to the search field
function updateSearch() {
  const string = String($(".search-input").val());
  $(".search-album-result").html("");
  $(".search-album-noresult").hide();
  $(".limit-album-reached").hide();
  if (string == "") {
    // help button unnecessary so do not show
    // $('.search-album-help').show();
    $(".search-album-ghost").show();
    $(".search-album-num-result").hide();
    hideSearchContainer();
  } else {
    $(".search-album-ghost").hide();
    $(".search-album-help").hide();
    $(".search-album-num-result").show();
    showSearchContainer();

    let nbResult = 0;

    nbResult = searchAlbumByName(window.data, string, nbResult);

    if (nbResult != 1) {
      if (nbResult >= RESULT_LIMIT) {
        $(".search-album-num-result").html(
          str_result_limit.replace("%d", String(nbResult)),
        );
      } else {
        $(".search-album-num-result").html(
          str_albums_found.replace("%d", String(nbResult)),
        );
      }
    } else {
      $(".search-album-num-result").html(str_album_found);
    }

    if (nbResult != 0) {
      resultAppear($(".search-album-result .search-album-elem").first());
    } else {
      $(".search-album-noresult").show();
    }
  }
}

function searchAlbumByName(
  categories: AlbumTreeNode[],
  search: string,
  nbResult: number,
  children?: boolean,
  name: string = "",
): number {
  for (const c of categories) {
    if (nbResult >= RESULT_LIMIT) {
      return nbResult;
    }

    const currentName =
      name + `<a href="${editLink + c.id}">${c.name}</a>` + " / ";

    if (c.name.toString().toLowerCase().includes(search.toLowerCase())) {
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
  const id = +cat.id;
  const template = $(".search-album-elem-template").html();
  const newCatNode = $(template);

  if (haveChildren) {
    newCatNode.find(".search-album-icon").addClass("icon-sitemap");
  } else {
    newCatNode.find(".search-album-icon").addClass("icon-folder-open");
  }

  const colorId = id % 5;
  newCatNode.find(".search-album-icon").addClass(colors[colorId]!);
  newCatNode.find(".search-album-name").html(name.slice(0, -2));

  const href = "admin.php?page=album-" + id;
  newCatNode.find(".search-album-edit").attr("href", href);

  $(".search-album-result").append(newCatNode);

  if (nbResult >= RESULT_LIMIT) {
    $(".limit-album-reached").show(1000);
    $(".limit-album-reached").html(
      str_result_limit.replace("%d", String(nbResult)),
    );
  }
}

// Make the results appear one after one [and limit results to 100]
function resultAppear(result: JQuery) {
  result.fadeIn();
  if (result.next().length != 0) {
    setTimeout(() => {
      resultAppear(result.next().first());
    }, 50);
  }
}

function showSearchContainer() {
  $(".tree").hide();
  $(".album-search-result-container").show();
}

function hideSearchContainer() {
  $(".album-search-result-container").hide();
  $(".tree").fadeIn();
}
