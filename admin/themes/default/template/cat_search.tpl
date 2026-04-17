{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}

{footer_script}<script>
  document.addEventListener('DOMContentLoaded', function() {
    var h1 = document.querySelector("h1");
    if (h1) h1.insertAdjacentHTML('beforeend', "<span class='badge-number'>"+{$nb_cats}+"</span>");
  });
  var data = {json_encode($data_cat)};
  /*
    Here data is an associative array id => category under this form
    [0] : name
    [1] : array of id, path to find this album (root to album)
    [2] : 1 = private or 0 = public
  */

  // Numeric array of all categories
  var categories = Object.values(data);

  const RESULT_LIMIT = 100;

  var str_albums_found = '{"<b>%d</b> albums found"|translate}';
  var str_album_found = '{"<b>1</b> album found"|translate}';
  var str_result_limit = '{"<b>%d+</b> albums found, try to refine the search"|translate|escape:javascript}';

  var editLink = "admin.php?page=album-";
  var colors = ["icon-red", "icon-blue", "icon-yellow", "icon-purple", "icon-green"];

  var limitReachedEl = document.querySelector(".limit-album-reached");
  if (limitReachedEl) limitReachedEl.style.display = 'none';

  var searchInput = document.querySelector('.search-input');
  if (searchInput) {
    searchInput.addEventListener('input', function() {
      updateSearch();
    });
  }

  // Update the page according to the search field
  function updateSearch() {
    var string = searchInput ? searchInput.value : '';
    var resultEl = document.querySelector('.search-album-result');
    var noresultEl = document.querySelector('.search-album-noresult');
    var limitEl = document.querySelector(".limit-album-reached");
    if (resultEl) resultEl.innerHTML = "";
    if (noresultEl) noresultEl.style.display = 'none';
    if (limitEl) limitEl.style.display = 'none';
    if (string == '') {
      // help button unnecessary so do not show
      // document.querySelector('.search-album-help').style.display = '';
      var ghostEl = document.querySelector('.search-album-ghost');
      if (ghostEl) ghostEl.style.display = '';
      var numResultEl = document.querySelector('.search-album-num-result');
      if (numResultEl) numResultEl.style.display = 'none';
    } else {
      var ghostEl = document.querySelector('.search-album-ghost');
      if (ghostEl) ghostEl.style.display = 'none';
      var helpEl = document.querySelector('.search-album-help');
      if (helpEl) helpEl.style.display = 'none';
      var numResultEl = document.querySelector('.search-album-num-result');
      if (numResultEl) numResultEl.style.display = '';

      nbResult = 0;
      categories.forEach((c) => {
        if (c[0].toString().toLowerCase().search(string.toLowerCase()) != -1 && nbResult < RESULT_LIMIT) {
          nbResult++;
          addAlbumResult(c, nbResult);
        }
      });

      if (numResultEl) {
        if (nbResult != 1) {
          if (nbResult >= RESULT_LIMIT) {
            numResultEl.innerHTML = str_result_limit.replace('%d', nbResult);
          } else {
            numResultEl.innerHTML = str_albums_found.replace('%d', nbResult);
          }
        } else {
          numResultEl.innerHTML = str_album_found;
        }
      }

      if (nbResult != 0) {
        resultAppear(document.querySelector('.search-album-result .search-album-elem'));
      } else {
        if (noresultEl) noresultEl.style.display = '';
      }
    }
  }

  // Add an album as a result in the page
  function addAlbumResult(cat, nbResult) {
    id = cat[1][cat[1].length - 1];
    var templateEl = document.querySelector('.search-album-elem-template');
    var template = templateEl ? templateEl.innerHTML.trim() : '';
    var tempDiv = document.createElement('div');
    tempDiv.innerHTML = template;
    var newCatNode = tempDiv.firstElementChild;
    if (!newCatNode) return;

    hasChildren = false;
    categories.forEach((c) => {
      for (let i = 0; i < c[1].length - 1; i++) {
        if (c[1][i] == id) {
          hasChildren = true;
        }
      }
    });

    var iconEl = newCatNode.querySelector('.search-album-icon');
    if (iconEl) {
      iconEl.classList.add(hasChildren ? 'icon-sitemap' : 'icon-folder-open');
      colorId = id % 5;
      iconEl.classList.add(colors[colorId]);
    }

    var nameEl = newCatNode.querySelector('.search-album-name');
    if (nameEl) nameEl.innerHTML = getHtmlPath(cat);

    href = "admin.php?page=album-" + id;
    var editEl = newCatNode.querySelector('.search-album-edit');
    if (editEl) editEl.setAttribute('href', href);

    var resultEl = document.querySelector('.search-album-result');
    if (resultEl) resultEl.appendChild(newCatNode);

    if (nbResult >= RESULT_LIMIT) {
      var limitEl = document.querySelector(".limit-album-reached");
      if (limitEl) {
        limitEl.style.display = '';
        limitEl.innerHTML = str_result_limit.replace('%d', nbResult);
      }
    }
  }

  // Get the path "PARENT / parent / album" with link to the edition of all albums
  function getHtmlPath(cat) {
    html = '';
    for (let i = 0; i < cat[1].length - 1; i++) {
      id_bis = cat[1][i];
      c = data[id_bis];
      html += '<a href="' + editLink + id_bis + '">' + c[0] + '</a> <b>/</b> '
    }
    html += '<a href="' + editLink + cat[1][cat[1].length - 1] + '">' + cat[0] + '</a>';

    return html
  }

  // Make the results appear one after one [and limit results to 100]
  function resultAppear(result) {
    if (!result) return;
    result.style.display = '';
    if (result.nextElementSibling !== null) {
      setTimeout(() => { resultAppear(result.nextElementSibling); }, 50);
    }
  }

  updateSearch();
  if (searchInput) searchInput.focus();
</script>{/footer_script}

<div class="search-album">
  <div class="search-album-cont">
    <div class="search-album-label">{'Search albums'|translate}</div>
    <div class="search-album-input-container" style="position:relative">
      <span class="icon-search search-icon"></span>
      <span class="icon-cancel search-cancel"></span>
      <input class='search-input' type="text" placeholder="{$placeholder|escape:html}">
    </div>
    <span class="search-album-help icon-help-circled" title="{'Enter a term to search for album'|translate}"></span>
    <span class="search-album-num-result"></span>
  </div>
</div>

<div class="search-album-ghost">
  <span>{'No research in progress'|translate}</span>
</div>

<div class="search-album-elem-template" style="display:none">
  <div class="search-album-elem" style="display:none">
    <span class='search-album-icon'></span>
    <p class='search-album-name'></p>
    <div class="search-album-action-cont">
      <div class="search-album-action">
        <a class="icon-pencil search-album-edit">{'Edit album'|translate}</a>
      </div>
    </div>
  </div>
</div>

<div class="search-album-result">

</div>
<div class="search-album-elem limit-album-reached"></div>

<div class="search-album-noresult">
  {'No albums found'|translate}
</div>

<style>
  .limit-album-reached {
    display: flex;
    justify-content: center;
    align-items: center;
  }
</style>