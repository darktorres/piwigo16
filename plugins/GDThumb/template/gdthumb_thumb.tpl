{if !empty($thumbnails)}
  {foreach $thumbnails as $thumbnail}
    {assign var=derivative value=$pwg->derivative($GDThumb_derivative_params, $thumbnail.src_image)}
    {assign var=deriv_size value=$derivative->get_size()}

    <li class="gdthumb {$GDThumb.method}">
      {if $GDThumb.thumb_mode_photo !== "hide" }
        <span class="thumbLegend {$GDThumb.thumb_mode_photo}">
          <span class="thumbName thumbTitle">
            {if $GDThumb.normalize_title == "desc" && $thumbnail.DESCRIPTION}
              {$thumbnail.DESCRIPTION}
            {elseif $GDThumb.normalize_title == "on"}
              {assign var="file_title" value=$thumbnail.NAME|cat:"."}
              {assign var="file_name" value=$thumbnail.file|replace:"_":" "}
              {if $file_name|strstr:$file_title}
                {$thumbnail.id}
              {else}
                {$thumbnail.NAME}
              {/if}
            {else}
              {$thumbnail.NAME}
            {/if}
            {if $GDThumb.thumb_mode_photo !== "overlay-ex"}
              {if !empty($thumbnail.icon_ts)}
                <img title="{$thumbnail.icon_ts.TITLE}" src="{$ROOT_URL}{$themeconf.icon_dir|default:null}/recent.png" alt="(!)">
              {/if}
            {/if}
          </span>
          {if $GDThumb.thumb_mode_photo == "overlay-ex"}
            <span class="thumbInfo">
              <span class="hit-num">{$thumbnail.hit}</span>
              <span class="fas fa-image"></span>
              {if !empty($thumbnail.icon_ts)}
                <span class="new-thumb fas fa-asterisk" title="{$thumbnail.icon_ts.TITLE}"></span>
              {/if}
              {if $thumbnail.rating_score > 0}
                <span class="rank-num"><i class="fas fa-star"></i>{$thumbnail.rating_score|string_format:"%d"}</span>
              {/if}
            </span>
          {elseif $GDThumb.thumb_metamode !== "hide"}
            {if isset($thumbnail.NB_COMMENTS)}
              <span
                class="{if 0==$thumbnail.NB_COMMENTS}zero {/if}nb-comments">{$thumbnail.NB_COMMENTS|translate_dec:'%d comment':'%d comments'}</span>
            {/if}
            {if isset($thumbnail.NB_COMMENTS) && isset($thumbnail.NB_HITS)} - {/if}
            {if isset($thumbnail.NB_HITS)}
              <span
                class="{if 0==$thumbnail.NB_HITS}zero {/if}nb-hits">{$thumbnail.NB_HITS|translate_dec:'%d visit':'%d visits'}</span>
            {elseif isset($thumbnail.hit)}
              <span
                class="{if 0==$thumbnail.hit}zero {/if}nb-hits">{$thumbnail.hit|translate_dec:'%d visit':'%d visits'}</span>
            {/if}
            {if isset($thumbnail.rating_score)}
              <span class="{if 0==$thumbnail.rating_score}zero {/if}rating">, {'Rating:'|translate}
                {$thumbnail.rating_score}</span>
            {/if}
          {/if}
        </span>
      {/if}
      {assign var=src_size value=$derivative->src_image->get_size()}
      {assign var=is_video value=(isset($thumbnail.path_ext) and ($thumbnail.path_ext == 'mp4' or $thumbnail.path_ext == 'm4v' or $thumbnail.path_ext == 'webm' or $thumbnail.path_ext == 'ogv' or $thumbnail.path_ext == 'mov' or $thumbnail.path_ext == 'mkv'))}
      {assign var=is_animated value=(isset($thumbnail.path_ext) and ($thumbnail.path_ext == 'webp' or $thumbnail.path_ext == 'gif'))}
      <a href="{$thumbnail.URL}" data-image-id="{$thumbnail.id}"
        {if $is_video}
          data-pswp-src="{$ROOT_URL}{$thumbnail.path}"
          data-pswp-width="{if $thumbnail.width}{$thumbnail.width}{else}1920{/if}"
          data-pswp-height="{if $thumbnail.height}{$thumbnail.height}{else}1080{/if}"
          data-pswp-type="video"
        {else}
          data-pswp-src="{$derivative->src_image->get_url()}"
          data-pswp-width="{$src_size.0}"
          data-pswp-height="{$src_size.1}"
        {/if}>
        {if $is_animated}
          <img class="thumbnail" src="{$ROOT_URL}{$thumbnail.path}"
            width="{$deriv_size.0}" height="{$deriv_size.1}"
            style="object-fit:cover;display:block"
            alt="{$thumbnail.TN_ALT}" title="{$thumbnail.TN_TITLE}">
        {else}
          <img class="thumbnail" src="{$derivative->get_url()}" {$derivative->get_size_htm()} loading="lazy" decoding="async"
            alt="{$thumbnail.TN_ALT}" title="{$thumbnail.TN_TITLE}">
        {/if}
      </a>
    </li>
  {/foreach}

  {combine_css path=$GDThumb.GDTHUMB_ROOT|cat:"/css/gdthumb.css" version=1}
  {combine_css path="node_modules/photoswipe/dist/photoswipe.css"}

  <script type="module">
    import { GDThumb } from './{$ROOT_URL}admin/themes/default/js/dist/{$vite_gdthumb}';
    {if !isset($has_cats)}
    GDThumb.setup('{$GDThumb.method}', {$GDThumb.height}, {$GDThumb.margin});
    {/if}
  </script>

  {footer_script}<script type="module">
    import PhotoSwipeLightbox from './node_modules/photoswipe/dist/photoswipe-lightbox.esm.js';

    if (!window._pswpInitialized) {
      window._pswpInitialized = true;
      const lightbox = new PhotoSwipeLightbox({
        gallery: '#thumbnails',
        children: 'a[data-pswp-src]',
        initialZoomLevel: (z) => z.pswp ? Math.min(z.pswp.viewportSize.x / z.elementSize.x, z.pswp.viewportSize.y / z.elementSize.y) : z.fit,
        secondaryZoomLevel: 1,
        maxZoomLevel: (z) => z.pswp ? Math.max(z.pswp.viewportSize.x / z.elementSize.x, z.pswp.viewportSize.y / z.elementSize.y, 2) : z.fit * 4,
        pswpModule: () => import('./node_modules/photoswipe/dist/photoswipe.esm.js')
      });

      lightbox.on('contentLoad', (e) => {
        const { content } = e;
        if (content.type === 'video') {
          e.preventDefault();
          const container = document.createElement('div');
          container.style.cssText = 'display:flex;align-items:center;justify-content:center;width:100vw;height:100vh';
          const src = content.data.src;
          const ext = src.split('.').pop().split('?')[0].toLowerCase();
          const mime = ext === 'webm' ? 'video/webm' : (ext === 'ogv' || ext === 'ogg' ? 'video/ogg' : 'video/mp4');
          const video = document.createElement('video');
          video.controls = true;
          video.autoplay = true;
          video.loop = true;
          video.playsInline = true;
          video.style.cssText = 'width:100%;height:100%;object-fit:contain;outline:none';
          const source = document.createElement('source');
          source.src = src;
          source.type = mime;
          video.appendChild(source);
          container.appendChild(video);
          content.element = container;
          content.state = 'loaded';
        }
      });

      lightbox.on('uiRegister', function() {
        lightbox.pswp.ui.registerElement({
          name: 'rate-button',
          order: 9,
          isButton: true,
          html: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>',
          onClick(e, el, pswp) {
            const imageId = pswp.currSlide.data.element.dataset.imageId;
            if (!imageId) return;
            const rated = el.classList.toggle('pswp__button--rated');
            fetch('{$ROOT_URL}ws.php?format=json', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: 'method=pwg.images.rate&image_id=' + encodeURIComponent(imageId) + '&rate=5'
            });
          }
        });
      });

      lightbox.on('change', function() {
        const btn = lightbox.pswp.element.querySelector('.pswp__button--rate-button');
        if (btn) btn.classList.remove('pswp__button--rated');
      });

      let lastWheelNav = 0;
      lightbox.on('wheel', (e) => {
        const pswp = lightbox.pswp;
        if (!pswp || !pswp.currSlide) return;
        const oe = e.originalEvent;
        if (oe.ctrlKey || pswp.options.wheelToZoom) return;
        if (pswp.currSlide.currZoomLevel > pswp.currSlide.zoomLevels.initial) return;
        if (Math.abs(oe.deltaY) <= Math.abs(oe.deltaX)) return;
        e.preventDefault();
        const now = Date.now();
        if (now - lastWheelNav < 300) return;
        lastWheelNav = now;
        if (oe.deltaY > 0) pswp.next(); else pswp.prev();
      });

      lightbox.on('contentDeactivate', ({ content }) => {
        if (content.type === 'video' && content.element) {
          const video = content.element.querySelector('video');
          if (video) video.pause();
        }
      });

      lightbox.on('contentDestroy', ({ content }) => {
        if (content.type === 'video' && content.element) {
          const video = content.element.querySelector('video');
          if (video) { video.pause(); video.src = ''; }
        }
      });

      lightbox.init();
    }
  </script>{/footer_script}
{/if}
