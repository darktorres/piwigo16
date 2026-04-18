    <div id="infopanel" class="col-lg-8 col-md-10 col-12 mx-auto">
      <!-- Nav tabs -->
      <ul class="nav nav-tabs nav-justified flex-column flex-sm-row" role="tablist">
        {if $theme_config->picture_info == 'tabs' || (functions::get_device() != 'desktop' && $theme_config->picture_info != 'disabled')}
          <li class="nav-item"><a class="flex-sm-fill text-sm-center nav-link active" href="#tab_info"
              aria-controls="tab_info" role="tab" data-bs-toggle="tab">{'Information'|translate}</a></li>
          {if isset($metadata)}
            <li class="nav-item"><a class="flex-sm-fill text-sm-center nav-link" href="#tab_metadata"
                aria-controls="tab_metadata" role="tab" data-bs-toggle="tab">{'EXIF Metadata'|translate}</a></li>
          {/if}
        {/if}
        {if isset($comment_add) || !empty($COMMENT_COUNT)}
          <li
            class="nav-item{if $theme_config->picture_info == 'disabled' || ($theme_config->picture_info != 'tabs' && functions::get_device() == 'desktop')} active{/if}">
            <a class="flex-sm-fill text-sm-center nav-link" href="#tab_comments" aria-controls="tab_comments" role="tab"
              data-bs-toggle="tab">{'Comments'|translate} <span class="badge bg-secondary">{$COMMENT_COUNT}</span></a>
          </li>
        {/if}
      </ul>

      <!-- Tab panes -->
      <div class="tab-content d-flex justify-content-center">
        {if $theme_config->picture_info === 'tabs' || (functions::get_device() != 'desktop' && $theme_config->picture_info != 'disabled')}
          <div role="tabpanel" class="tab-pane active" id="tab_info">
            <div id="info-content" class="info">
              <div class="table-responsive">
                <table class="table table-sm">
                  <colgroup>
                    <col class="w-50">
                    <col class="w-50">
                  </colgroup>
                  <tbody>
                    {if $display_info.author and isset($INFO_AUTHOR)}
                      <tr>
                        <th scope="row">{'Author'|translate}</th>
                        <td>
                          <div id="Author" class="imageInfo">{$INFO_AUTHOR}</div>
                        </td>
                      </tr>
                    {/if}
                    {if isset($CR_INFO_NAME) && !empty($CR_INFO_NAME)}
                      <tr>
                        <th scope="row">{'Copyright'|translate}</th>
                        <td>
                          <div id="Copyright" class="imageInfo">{if isset($CR_INFO_URL)}<a
                              href="{$CR_INFO_URL}">{$CR_INFO_NAME}</a>{else}{$CR_INFO_NAME}
                            {/if}</div>
                        </td>
                      </tr>
                    {/if}
                    {if $display_info.rating_score and isset($rate_summary)}
                      <tr>
                        <th scope="row">{'Rating score'|translate}</th>
                        <td>
                          <div id="Average" class="imageInfo">
                            {if $rate_summary.count}
                              <span id="ratingScore">{$rate_summary.score}</span> <span
                                id="ratingCount">({$rate_summary.count|translate_dec:'%d rate':'%d rates'})</span>
                            {else}
                              <span id="ratingScore">{'no rate'|translate}</span> <span id="ratingCount"></span>
                            {/if}
                          </div>
                        </td>
                      </tr>
                    {/if}
                    {if isset($rating)}
                      <tr>
                        <th scope="row" id="updateRate">
                          {if isset($rating.USER_RATE)}{'Update your rating'|translate}{else}{'Rate this photo'|translate}{/if}
                        </th>
                        <td>
                          <div id="rating" class="imageInfo">
                            <form action="{$rating.F_ACTION}" method="post" id="rateForm" style="margin:0;">
                              <div>
                                {foreach $rating.marks as $mark}
                                  {if isset($rating.USER_RATE) && $mark==$rating.USER_RATE}
                                    <span class="rateButtonStarFull" data-value="{$mark}"></span>
                                  {else}
                                    <span class="rateButtonStarEmpty" data-value="{$mark}"></span>
                                  {/if}
                                {/foreach}
                                {combine_script id='core.scripts' path='themes/default/js/scripts.js' load='async'}
                                {footer_script}<script type="module">
                                  import { initRating, makeNiceRatingForm } from './themes/bootstrap_darkroom/js/rating.js';
                                  window._pwgRatingAutoQueue = window._pwgRatingAutoQueue || [];
                                  window._pwgRatingAutoQueue.push( { rootUrl: '{$ROOT_URL}', image_id: {$current.id},
                                  onSuccess: function(rating) {
                                  var e = document.getElementById("updateRate");
                                  if (e) e.innerHTML = "{'Update your rating'|translate|escape:'javascript'}";
                                  e = document.getElementById("ratingScore");
                                  if (e) e.innerHTML = rating.score;
                                  e = document.getElementById("ratingCount");
                                  if (e) {
                                    if (rating.count == 1) {
                                      e.innerHTML = "({'%d rate'|translate|escape:'javascript'})".replace( "%d", rating.count);
                                    } else {
                                      e.innerHTML = "({'%d rates'|translate|escape:'javascript'})".replace( "%d", rating.count);
                                    }
                                  }
                                  var averageRateEl = document.getElementById('averageRate');
                                  if (averageRateEl) {
                                    var spans = averageRateEl.querySelectorAll('span');
                                    spans.forEach(function(span) {
                                      var value = parseFloat(span.dataset.value);
                                      if (rating.average > value - 0.5) {
                                        span.classList.add('rateButtonStarFull');
                                        span.classList.remove('rateButtonStarEmpty');
                                      } else {
                                        span.classList.add('rateButtonStarEmpty');
                                        span.classList.remove('rateButtonStarFull');
                                      }
                                    });
                                  }
                                  }
                                  });
                                </script>{/footer_script}
                              </div>
                            </form>
                          </div>
                        </td>
                      </tr>
                    {/if}
                    {if $display_info.created_on and isset($INFO_CREATION_DATE)}
                      <tr>
                        <th scope="row">{'Created on'|translate}</th>
                        <td>
                          <div id="datecreate" class="imageInfo">{$INFO_CREATION_DATE}</div>
                        </td>
                      </tr>
                    {/if}
                    {if $display_info.posted_on}
                      <tr>
                        <th scope="row">{'Posted on'|translate}</th>
                        <td>
                          <div id="datepost" class="imageInfo">{$INFO_POSTED_DATE}</div>
                        </td>
                      </tr>
                    {/if}
                    {if $display_info.visits}
                      <tr>
                        <th scope="row">{'Visits'|translate}</th>
                        <td>
                          <div id="visits" class="imageInfo">{$INFO_VISITS}</div>
                        </td>
                      </tr>
                    {/if}
                    {if $display_info.dimensions and isset($INFO_DIMENSIONS)}
                      <tr>
                        <th scope="row">{'Dimensions'|translate}</th>
                        <td>
                          <div id="Dimensions" class="imageInfo">{$INFO_DIMENSIONS}</div>
                        </td>
                      </tr>
                    {/if}
                    {if $display_info.file}
                      <tr>
                        <th scope="row">{'File'|translate}</th>
                        <td>
                          <div id="File" class="imageInfo">{$INFO_FILE}</div>
                        </td>
                      </tr>
                    {/if}
                    {if $display_info.filesize and isset($INFO_FILESIZE)}
                      <tr>
                        <th scope="row">{'Filesize'|translate}</th>
                        <td>
                          <div id="Filesize" class="imageInfo">{$INFO_FILESIZE}</div>
                        </td>
                      </tr>
                    {/if}
                    {if $display_info.tags and isset($related_tags)}
                      <tr>
                        <th scope="row">{'Tags'|translate}</th>
                        <td>
                          <div id="Tags" class="imageInfo">
                            {foreach from=$related_tags item=tag name=tag_loop}
                              {if !$smarty.foreach.tag_loop.first},
                              {/if}<a href="{$tag.URL}">{$tag.name}</a>
                            {/foreach}
                          </div>
                        </td>
                      </tr>
                    {/if}
                    {if $display_info.categories and isset($related_categories)}
                      <tr>
                        <th scope="row">{'Albums'|translate}</th>
                        <td>
                          <div id="Categories" class="imageInfo">
                            {foreach from=$related_categories item=cat name=cat_loop}
                              {if !$smarty.foreach.cat_loop.first}<br />{/if}{$cat}
                            {/foreach}
                          </div>
                        </td>
                      </tr>
                    {/if}
                    {if $display_info.privacy_level and isset($available_permission_levels)}
                      {combine_script id='core.scripts' load='async' path='themes/default/js/scripts.js'}
                      {footer_script}<script>
                        function setPrivacyLevel(id, level, label) {
                          (new PwgWS('{$ROOT_URL}')).callService(
                          "pwg.images.setPrivacyLevel",
                          { image_id: id, level: level },
                          {
                            method: "POST",
                            onFailure: function(num, text) { alert(num + " " + text); },
                            onSuccess: function(result) {
                              var dropdown = document.getElementById('dropdownPermissions');
                              if (dropdown) dropdown.innerHTML = label;
                              var permLis = document.querySelectorAll('.permission-li');
                              permLis.forEach(function(li) {
                                li.classList.remove('active');
                              });
                              var permElement = document.getElementById('permission-' + level);
                              if (permElement) permElement.classList.add('active');
                            }
                          }
                        );
                        }
                      </script>{/footer_script}
                      <tr>
                        <th scope="row">{'Who can see this photo?'|translate}</th>
                        <td>
                          <div id="Privacy" class="imageInfo">
                            <div class="dropdown">
                              <button class="btn btn-secondary btn-raised dropdown-toggle ellipsis" type="button"
                                id="dropdownPermissions" data-bs-toggle="dropdown" aria-expanded="true">
                                {$available_permission_levels[$current.level]}
                              </button>
                              <div class="dropdown-menu" role="menu" aria-labelledby="dropdownPermissions">
                                {foreach $available_permission_levels as $level => $label}
                                  <a id="permission-{$level}"
                                    class="dropdown-item permission-li {if $current.level == $level} active{/if}"
                                    href="javascript:setPrivacyLevel({$current.id},{$level},'{$label}')">{$label}</a>
                                {/foreach}
                              </div>
                            </div>
                          </div>
                        </td>
                      </tr>
                    {/if}
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- metadata -->
          {if isset($metadata)}
            <div role="tabpanel" class="tab-pane" id="tab_metadata">
              <div id="metadata" class="info">
                <div class="table-responsive">
                  <table class="table table-sm">
                    <colgroup>
                      <col class="w-50">
                      <col class="w-50">
                    </colgroup>
                    <tbody>
                      {foreach $metadata as $meta}
                        {foreach $meta.lines as $label => $value}
                          <tr>
                            <th scope="row">{$label}</th>
                            <td>{$value}</td>
                          </tr>
                        {/foreach}
                      {/foreach}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          {/if}
        {/if}

        <!-- comments -->
        {if isset($comment_add) || !empty($COMMENT_COUNT)}
          <div role="tabpanel" class="tab-pane" id="tab_comments">
            {include file='picture_info_comments.tpl'}
          </div>
        {/if}
      </div>
</div>