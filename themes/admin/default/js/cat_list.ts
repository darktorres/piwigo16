import "./common";
import {
  addClass,
  children,
  css,
  hasClass,
  hide,
  hover,
  is,
  off,
  on,
  ready,
  removeClass,
  show,
} from "../../../default/js/vendor/dom";

export {};

function setDisplayCompact(): void {
  removeIconDesc();

  css(document.querySelectorAll(".albumActions"), "display", "flex");
  removeHoverEffect(document.querySelectorAll(".categoryBox"));
  removeHoverEffect(
    children(
      children(document.querySelectorAll(".categoryBox"), ".albumActions"),
      "a",
    ),
  );

  hover(
    children(
      children(document.querySelectorAll(".categoryBox"), ".albumActions"),
      "a",
    ),
    function (event: Event) {
      css(event.currentTarget as Element, { color: "#000000" });
    },
    function (event: Event) {
      css(event.currentTarget as Element, { color: "#848484" });
    },
  );
  removeClass(document.querySelectorAll(".categoryBox"), "line_cat tile_cat");
  removeClass(document.querySelectorAll(".addAlbum"), "tile_add");
  css(document.querySelectorAll(".categoryBox"), {
    minWidth: "250px",
    maxWidth: "350px",
    flexDirection: "column",
    maxHeight: "180px",
    alignItems: "unset",
    margin: "15px",
  });

  css(document.querySelectorAll(".albumInfos"), {
    marginLeft: "0",
    flexDirection: "column",
  });

  // $(".albumIcon").css({
  //     height: "80px"
  // });

  // $(".albumIcon span").css({
  //     fontSize: "19px",
  //     width: "27px",
  //     padding: "10px"
  // });

  css(document.querySelectorAll(".albumIcon"), {
    height: "60px",
  });

  css(document.querySelectorAll(".albumIcon span"), {
    fontSize: "14px",
    width: "20px",
    padding: "8px",
  });

  css(document.querySelectorAll(".albumInfos p"), {
    margin: "0",
    textAlign: "center",
    whiteSpace: "normal",
  });
  css(document.querySelectorAll(".albumInfos p:last-child"), {
    width: "auto",
  });

  css(document.querySelectorAll(".albumTop"), {
    width: "auto",
    justifyContent: "center",
    flexDirection: "row",
    alignItems: "baseline",
    height: "65px",
  });

  css(document.querySelectorAll(".albumTitle"), "padding", "0 15px");

  css(document.querySelectorAll(".addAlbum"), {
    minWidth: "250px",
    maxWidth: "350px",
    flexDirection: "column",
    maxHeight: "180px",
    margin: "15px",
  });

  css(document.querySelectorAll(".addAlbum form label"), {
    display: "none",
  });

  css(document.querySelectorAll(".addAlbumHead"), {
    flexDirection: "column",
    transform: "translateY(55px)",
    alignItems: "center",
    marginTop: "-10px",
    transition: "0.4s ease",
    marginBottom: "0px",
  });

  css(document.querySelectorAll(".addAlbum form"), "flex-direction", "column");

  css(document.querySelectorAll(".addAlbum form"), {
    flexDirection: "column",
    marginTop: "0",
    marginBottom: "0",
    transitionDelay: "0s",
  });

  css(document.querySelectorAll(".addAlbum.input-mode form"), {
    transitionDelay: "0.4s",
  });

  css(
    document.querySelectorAll(".addAlbum form input"),
    "margin",
    "0px 10px 0px 10px",
  );
  css(
    document.querySelectorAll(".addAlbum form button"),
    "margin",
    "10px auto 0 auto",
  );
  css(document.querySelectorAll(".addAlbum p"), "margin-bottom", "0px");

  css(document.querySelectorAll(".addAlbumHead p"), "margin-left", "0");

  css(document.querySelectorAll(".addAlbumHead span"), {
    fontSize: "14px",
    width: "20px",
    height: "20px",
    padding: "8px",
  });

  css(document.querySelectorAll(".albumActions"), {
    flexDirection: "row",
    marginTop: "auto",
    width: "100%",
  });

  css(document.querySelectorAll(".albumActions a"), {
    minWidth: "0px",
  });

  css(
    document.querySelectorAll(".albumActions a:first-child"),
    "margin-left",
    "35px",
  );
  css(
    document.querySelectorAll(".albumActions a:last-child"),
    "margin-right",
    "35px",
  );
}

function setDisplayLine(): void {
  /*********** Hover stuff ***********/

  removeIconDesc();
  css(document.querySelectorAll(".albumActions"), "display", "flex");
  removeHoverEffect(document.querySelectorAll(".categoryBox"));

  hover(
    document.querySelectorAll(".categoryBox"),
    function (event: Event) {
      const box = event.currentTarget as Element;
      css(box, "background", "#ffd7ad");
      css(children(box, ".albumInfos"), {
        color: "#515151",
      });
      css(children(children(box, ".albumActions"), "a"), {
        color: "#515151",
      });

      addClass(
        children(children(children(box, ".albumTop"), ".albumIcon"), "span"),
        "albumIconLineHover",
      );
    },
    function (event: Event) {
      const box = event.currentTarget as Element;
      css(box, "background", "#fafafa");
      css(children(box, ".albumInfos"), {
        color: "#a9a9a9",
      });
      css(children(children(box, ".albumActions"), "a"), {
        color: "#848484",
      });

      removeClass(
        children(children(children(box, ".albumTop"), ".albumIcon"), "span"),
        "albumIconLineHover",
      );
    },
  );

  hover(
    children(
      children(document.querySelectorAll(".categoryBox"), ".albumActions"),
      "a",
    ),
    function (event: Event) {
      css(event.currentTarget as Element, { color: "#000000" });
    },
    function (event: Event) {
      css(event.currentTarget as Element, { color: "#515151" });
    },
  );

  /************************************/
  removeClass(document.querySelectorAll(".categoryBox"), "tile_cat");
  addClass(document.querySelectorAll(".categoryBox"), "line_cat");
  removeClass(document.querySelectorAll(".addAlbum"), "tile_add");
  css(document.querySelectorAll(".categoryBox"), {
    minWidth: "90%",
    maxWidth: "100%",
    flexDirection: "row",
    maxHeight: "60px",
    alignItems: "unset",
    margin: "5px 15px",
  });

  css(document.querySelectorAll(".albumIcon"), {
    height: "60px",
  });

  css(document.querySelectorAll(".albumIcon span"), {
    fontSize: "14px",
    width: "20px",
    padding: "8px",
  });

  css(document.querySelectorAll(".addAlbumHead span"), {
    fontSize: "14px",
    width: "20px",
    height: "20px",
    padding: "8px",
  });

  css(document.querySelectorAll(".albumInfos"), {
    marginLeft: "auto",
    flexDirection: "row",
    justifyContent: "space-around",
    width: "auto",
  });

  css(document.querySelectorAll(".albumInfos p"), {
    textAlign: "right",
    margin: "0",
    whiteSpace: "nowrap",
  });

  css(document.querySelectorAll(".albumInfos p:last-child"), {
    width: "270px",
  });

  css(document.querySelectorAll(".albumTop"), {
    width: "35%",
    justifyContent: "flex-start",
    flexDirection: "row",
    alignItems: "baseline",
    height: "75px",
  });

  css(document.querySelectorAll(".albumTitle"), "padding", "0 15px");

  css(document.querySelectorAll(".addAlbum"), {
    minWidth: "90%",
    maxWidth: "100%",
    flexDirection: "row",
    maxHeight: "60px",
    margin: "15px 15px 5px 15px",
  });

  css(document.querySelectorAll(".addAlbum form label"), {
    display: "none",
  });

  // Was a duplicate `transform` key here (translateY(0) then
  // translateX(200px)) -- a genuine pre-existing bug (JS object
  // literals silently keep only the last value for a duplicate key,
  // so the first was always dead), but ESLint's own no-dupe-keys rule
  // (part of its base recommended config) makes this a hard error,
  // not just a style nit. Removed the dead first value -- zero
  // observable behavior change, matching this campaign's "harmless
  // fix forced by strict tooling" precedent.
  css(document.querySelectorAll(".addAlbumHead"), {
    flexDirection: "row",
    alignItems: "center",
    marginTop: "0",
    transform: "translateX(200px)",
    marginBottom: "0",
  });

  css(document.querySelectorAll(".addAlbum form"), {
    flexDirection: "row",
    marginTop: "0",
    marginBottom: "0",
    transitionDelay: "0s",
  });

  css(document.querySelectorAll(".addAlbum.input-mode form"), {
    transitionDelay: "0s",
  });

  css(document.querySelectorAll(".addAlbum form"), "align-items", "center");
  css(
    document.querySelectorAll(".addAlbum form input"),
    "margin",
    "0px 10px 0px 10px",
  );
  css(document.querySelectorAll(".addAlbum form button"), "margin", "0px 20px");
  css(document.querySelectorAll(".addAlbum p"), "margin-bottom", "0px");

  css(document.querySelectorAll(".addAlbumHead p"), "margin-left", "15px");

  css(document.querySelectorAll(".albumActions"), {
    flexDirection: "row",
    margin: "auto 0px",
    width: "300px",
  });

  css(document.querySelectorAll(".albumActions a"), {
    minWidth: "30px",
  });

  css(
    document.querySelectorAll(".albumActions a:first-child"),
    "margin-left",
    "35px",
  );
  css(
    document.querySelectorAll(".albumActions a:last-child"),
    "margin-right",
    "35px",
  );
}

function setDisplayTile(): void {
  ShowIconDesc();

  css(document.querySelectorAll(".albumActions"), "display", "flex");
  removeHoverEffect(document.querySelectorAll(".categoryBox"));
  removeHoverEffect(
    children(
      children(document.querySelectorAll(".categoryBox"), ".albumActions"),
      "a",
    ),
  );
  hover(
    children(
      children(document.querySelectorAll(".categoryBox"), ".albumActions"),
      "a",
    ),
    function (event: Event) {
      css(event.currentTarget as Element, { color: "#FFA646" });
    },
    function (event: Event) {
      css(event.currentTarget as Element, { color: "#848484" });
    },
  );

  AddHoverOnAlbumActions();

  css(document.querySelectorAll(".addAlbum.input-mode form"), {
    transitionDelay: "0s",
  });
  removeClass(document.querySelectorAll(".categoryBox"), "line_cat");
  addClass(document.querySelectorAll(".categoryBox"), "tile_cat");
  addClass(document.querySelectorAll(".addAlbum"), "tile_add");
  css(document.querySelectorAll(".categoryBox"), {
    minWidth: "220px",
    maxWidth: "280px",
    flexDirection: "column",
    maxHeight: "320px",
    alignItems: "center",
    margin: "15px",
  });

  css(document.querySelectorAll(".albumActions"), {
    flexDirection: "column",
    margin: "auto",
    alignItems: "flex-start",
    width: "75%",
  });

  css(document.querySelectorAll(".albumInfos"), {
    marginLeft: "0",
    flexDirection: "column",
  });

  css(document.querySelectorAll(".albumInfos p:last-child"), {
    width: "auto",
  });
  css(document.querySelectorAll(".albumInfos p"), {
    margin: "0",
    textAlign: "center",
    whiteSpace: "normal",
  });

  css(document.querySelectorAll(".albumIcon"), {
    height: "80px",
  });

  css(document.querySelectorAll(".albumIcon span"), {
    fontSize: "19px",
    width: "27px",
    padding: "10px",
  });

  css(document.querySelectorAll(".albumTop"), {
    width: "85%",
    flexDirection: "column",
    alignItems: "unset",
    height: "110px",
  });

  css(document.querySelectorAll(".albumTitle"), "padding", "0");

  css(document.querySelectorAll(".addAlbum"), {
    minWidth: "220px",
    maxWidth: "280px",
    flexDirection: "column",
    maxHeight: "320px",
    margin: "15px",
  });

  css(document.querySelectorAll(".addAlbumHead"), {
    flexDirection: "column",
    transform: "translateY(75px)",
    alignItems: "center",
    marginTop: "10px",
    transition: "0.4s ease",
    marginBottom: "0",
  });

  css(document.querySelectorAll(".addAlbum form"), {
    flexDirection: "column",
    marginTop: "auto",
    marginBottom: "20px",
    transitionDelay: "0s",
  });

  css(
    document.querySelectorAll(".addAlbum form input"),
    "margin",
    "0px 10px 10px 10px",
  );
  css(
    document.querySelectorAll(".addAlbum form button"),
    "margin",
    "10px auto 0 auto",
  );
  css(document.querySelectorAll(".addAlbum p"), "margin-bottom", "20px");

  css(document.querySelectorAll(".addAlbum form label"), {
    display: "flex",
    margin: "-25px 0 0 15px",
  });

  css(document.querySelectorAll(".addAlbumHead p"), "margin-left", "0");

  css(document.querySelectorAll(".addAlbumHead span"), {
    fontSize: "19px",
    width: "27px",
    height: "27px",
    padding: "10px",
  });

  css(document.querySelectorAll(".albumInfos p"), "margin", "0");

  css(document.querySelectorAll(".albumActions a"), {
    minWidth: "0px",
  });

  css(
    document.querySelectorAll(".albumActions a:first-child"),
    "margin-left",
    "5px",
  );
  css(
    document.querySelectorAll(".albumActions a:last-child"),
    "margin-left",
    "5px",
  );
}

function ShowIconDesc(): void {
  show(document.querySelectorAll(".albumActions span.iconLegend"));
}

function removeIconDesc(): void {
  hide(document.querySelectorAll(".albumActions span.iconLegend"));
}

function removeHoverEffect(target: Element | ArrayLike<Element>): void {
  off(target, "mouseenter");
  off(target, "mouseleave");
}

function AddHoverOnAlbumActions(): void {
  css(document.querySelectorAll(".albumActions"), "display", "none");
  hover(
    document.querySelectorAll(".categoryBox"),
    function (event: Event) {
      css(
        children(event.currentTarget as Element, ".albumActions"),
        "display",
        "flex",
      );
    },
    function (event: Event) {
      css(
        children(event.currentTarget as Element, ".albumActions"),
        "display",
        "none",
      );
    },
  );
}

ready(function () {
  // Still jQuery: jquery.cookie is a library, ported in P49-B group 2.
  if (!$.cookie("pwg_album_manager_view")) {
    $.cookie("pwg_album_manager_view", "tile");
  }

  on(document.querySelectorAll(".addAlbum"), "click", function (e: Event) {
    if ((e.target as Element).className !== "cancelAddAlbum") {
      addClass(document.querySelectorAll(".addAlbum"), "input-mode");

      // Still jQuery: jquery.cookie is a library, ported in P49-B group 2.
      if ($.cookie("pwg_album_manager_view") !== "tile") {
        hide(document.querySelectorAll(".addAlbum p"), 300);
      }
    }
  });

  on(document.querySelectorAll(".cancelAddAlbum"), "click", function () {
    removeClass(document.querySelectorAll(".addAlbum"), "input-mode");
    show(document.querySelectorAll(".addAlbum p"), 800);
  });

  on(document.querySelectorAll(".addAlbumHead"), "click", function () {
    document
      .querySelector<HTMLElement>(".addAlbum input[name=virtual_name]")
      ?.focus();
  });

  if (is(document.querySelectorAll("#displayCompact"), ":checked")) {
    setDisplayCompact();
  }

  if (is(document.querySelectorAll("#displayLine"), ":checked")) {
    setDisplayLine();
  }

  if (is(document.querySelectorAll("#displayTile"), ":checked")) {
    setDisplayTile();
  }

  on(document.querySelectorAll("#displayCompact"), "change", function () {
    setDisplayCompact();

    if (hasClass(document.querySelectorAll(".addAlbum"), "input-mode")) {
      hide(document.querySelectorAll(".addAlbum p"));
    }

    // Still jQuery: jquery.cookie is a library, ported in P49-B group 2.
    $.cookie("pwg_album_manager_view", "compact");
  });

  on(document.querySelectorAll("#displayLine"), "change", function () {
    setDisplayLine();

    if (hasClass(document.querySelectorAll(".addAlbum"), "input-mode")) {
      hide(document.querySelectorAll(".addAlbum p"));
    }

    // Still jQuery: jquery.cookie is a library, ported in P49-B group 2.
    $.cookie("pwg_album_manager_view", "line");
  });

  on(document.querySelectorAll("#displayTile"), "change", function () {
    setDisplayTile();

    if (hasClass(document.querySelectorAll(".addAlbum"), "input-mode")) {
      show(document.querySelectorAll(".addAlbum p"));
    }

    // Still jQuery: jquery.cookie is a library, ported in P49-B group 2.
    $.cookie("pwg_album_manager_view", "tile");
  });
});
