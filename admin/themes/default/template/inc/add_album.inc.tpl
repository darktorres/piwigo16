{combine_css path='node_modules/tom-select/dist/css/tom-select.default.css'}
{combine_css path="admin/themes/default/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}

<dialog id="addAlbumForm">
  <form>
    <div class="popinField addAlbumFormParent">
      <span class="popinFieldLabel">{'Parent album'|translate}</span>
      <select name="category_parent"></select>
    </div>

    <div class="popinField">
      <span class="popinFieldLabel">{'Album name'|translate}</span>
      <input name="category_name" type="text" maxlength="255">
      <span id="categoryNameError" style="color:red;">{'The name of an album must not be empty'|translate}</span>
    </div>

    <div class="popinButtons">
      <input type="submit" value="{'Create'|translate}" class="albumCreationButton">
      <span id="albumCreationLoading"><span class="icon-spin6 animate-spin"></span></span>
    </div>
  </form>
</dialog>