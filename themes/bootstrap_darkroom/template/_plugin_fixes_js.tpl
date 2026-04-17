{if isset($loaded_plugins['piwigo-openstreetmap']) && ($BODY_ID == "thePicturePage" || $BODY_ID == "theCategoryPage")}
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const icon = document.querySelector('.pwg-icon-globe');
      if (icon) {
        icon.classList.remove('pwg-icon');
        const a = icon.closest('a');
        a.innerHTML = '<i class="fas fa-globe fa-fw" aria-hidden="true"></i>';
        a.classList.add('nav-link');
        a.classList.remove('pwg-state-default', 'pwg-button');
        const li = a.closest('li');
        li.classList.add('nav-item', 'osm-button');
        const title = li.querySelector('a').getAttribute('title');
        a.querySelector('i').insertAdjacentHTML('afterend', '<span class="d-lg-none ms-2">' + title + '</span>');
      }
    });
  </script>
{/if}

{if isset($loaded_plugins['rv_gmaps']) && $BODY_ID == "thePicturePage"}
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const icon = document.querySelector('.pwg-icon-map');
      if (icon) {
        icon.classList.remove('pwg-icon');
        const a = icon.closest('a');
        a.innerHTML = '<i class="fas fa-globe fa-fw" aria-hidden="true"></i>';
        a.classList.add('nav-link');
        a.classList.remove('pwg-state-default', 'pwg-button');
        const li = document.createElement('li');
        li.className = 'nav-item rvgmaps-button';
        a.parentNode.insertBefore(li, a);
        li.appendChild(a);
        const title = document.querySelector('.rvgmaps-button a').getAttribute('title');
        document.querySelector('.rvgmaps-button i').insertAdjacentHTML('afterend', '<span class="d-lg-none ms-2">' + title + '</span>');
      }
      const map = document.getElementById('map');
      if (map) {
        const mapContainer = document.createElement('div');
        mapContainer.id = 'mapContainer';
        mapContainer.className = 'container';
        map.parentNode.insertBefore(mapContainer, map);
        mapContainer.appendChild(map);
      }
      const mapPicture = document.getElementById('mapPicture');
      if (mapPicture && document.getElementById('mapContainer')) {
        const row = document.createElement('div');
        row.className = 'row justify-content-center';
        document.getElementById('mapContainer').appendChild(row);
        row.appendChild(mapPicture);
      }
    });
  </script>
{/if}

{if isset($loaded_plugins['rv_gmaps']) && $BODY_ID == "theCategoryPage"}
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const icon = document.querySelector('.pwg-icon-map');
      if (icon) {
        icon.classList.remove('pwg-icon');
        const a = icon.closest('a');
        a.innerHTML = '<i class="fas fa-globe fa-fw" aria-hidden="true"></i>';
        a.classList.add('nav-link');
        a.classList.remove('pwg-state-default', 'pwg-button');
        const li = a.closest('li');
        li.classList.add('nav-item', 'rvgmaps-button');
        const title = li.querySelector('a').getAttribute('title');
        li.querySelector('a > i').insertAdjacentHTML('afterend', '<span class="d-lg-none ms-2">' + title + '</span>');
      }
    });
  </script>
{/if}
{if isset($loaded_plugins['rv_gmaps']) && $BODY_ID == "theMapListPage"}
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const wrapper = document.querySelector('#theMapListPage #wrapper');
      if (wrapper) wrapper.classList.add('container');

      const catActions = document.querySelector('#theMapListPage ul.categoryActions');
      if (catActions) {
        catActions.classList.add('nav', 'flex-column');
        catActions.querySelectorAll('li').forEach(li => {
          li.classList.add('nav-item');
          const a = li.querySelector('a');
          if (a) a.classList.add('nav-link');
        });
      }

      document.querySelectorAll('#theMapListPage .pwg-icon-globe').forEach(el => {
        el.classList.add('fa', 'fa-globe');
        el.classList.remove('pwg-icon', 'pwg-icon-globe');
      });

      document.querySelectorAll('#theMapListPage .pwg-icon-home').forEach(el => {
        el.classList.add('fa', 'fa-home');
        el.classList.remove('pwg-icon', 'pwg-icon-home');
      });

      const thumbnails = document.querySelector('#theMapListPage #thumbnails');
      if (thumbnails) {
        thumbnails.classList.add('row');
        const div = document.createElement('div');
        while (thumbnails.firstChild) div.appendChild(thumbnails.firstChild);
        thumbnails.parentNode.replaceChild(div, thumbnails);
        div.querySelectorAll('.card-thumbnail').forEach(card => card.classList.add('mb-3'));
      }
    });
  </script>
{/if}

{if isset($loaded_plugins['oAuth'])}
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const quickconnect = document.querySelector('#navbar-menubar > .navbar-nav > dd > #quickconnect');
      if (quickconnect) {
        quickconnect.id = 'oAuthQuickconnect';
        const legend = document.querySelector('#oAuthQuickconnect legend');
        if (legend) {
          legend.classList.add('dropdown-header');
          const li = document.createElement('li');
          li.appendChild(legend.cloneNode(true));
          const menu = document.querySelector('#identificationDropdown > .dropdown-menu');
          if (menu) menu.appendChild(li);
        }
        const dd = quickconnect.closest('dd');
        if (dd) {
          const li = document.createElement('li');
          const menu = document.querySelector('#identificationDropdown > .dropdown-menu');
          if (menu) {
            while (dd.firstChild) li.appendChild(dd.firstChild);
            menu.appendChild(li);
          }
        }
      }
    });
  </script>
{/if}

{if isset($loaded_plugins['ProtectedAlbums']) && $BODY_ID == 'theCategoryPage'}
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const form = document.querySelector('#content > form');
      if (form) {
        form.classList.add('d-flex', 'flex-wrap', 'align-items-center');
        const wrapper = document.createElement('div');
        wrapper.className = 'col-sm-12';
        form.parentNode.insertBefore(wrapper, form);
        wrapper.appendChild(form);

        const legend = form.querySelector('legend');
        if (legend) {
          const h4 = document.createElement('h4');
          h4.textContent = legend.textContent;
          legend.parentNode.replaceChild(h4, legend);
        }

        form.querySelectorAll('fieldset').forEach(fieldset => {
          const div = document.createElement('div');
          while (fieldset.firstChild) div.appendChild(fieldset.firstChild);
          fieldset.parentNode.replaceChild(div, fieldset);
        });

        form.querySelectorAll('div').forEach(div => div.classList.add('mb-3'));
        form.querySelectorAll('div > input[type="password"]').forEach(input => input.classList.add('form-control'));

        form.querySelectorAll('input[type="submit"]').forEach(input => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = input.className;
          button.setAttribute('type', 'submit');
          input.parentNode.replaceChild(button, input);
        });

        form.querySelectorAll('button').forEach(btn => {
          btn.classList.add('btn', 'btn-primary', 'btn-raised');
          btn.textContent = 'Login';
        });

        form.querySelectorAll('label').forEach(label => label.remove());
      }
    });
  </script>
{/if}

{if isset($loaded_plugins['user_custom_fields']) && ($BODY_ID == 'theProfilePage' || $BODY_ID == 'theRegisterPage')}
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const container = document.querySelector('{if $BODY_ID == 'theProfilePage'}#theProfilePage{else}#theRegisterPage{/if}');
      if (!container) return;

      container.querySelectorAll('fieldset > legend').forEach(legend => legend.remove());

      container.querySelectorAll('fieldset > ul > li').forEach(li => {
        const div = document.createElement('div');
        while (li.firstChild) div.appendChild(li.firstChild);
        li.parentNode.replaceChild(div, li);
      });

      container.querySelectorAll('fieldset > ul').forEach(ul => {
        const parent = ul.parentNode;
        while (ul.firstChild) parent.insertBefore(ul.firstChild, ul);
        ul.remove();
      });

      container.querySelectorAll('fieldset > div > .property > label').forEach(label => {
        label.classList.add('col-sm-2', 'control-label');
        const parent = label.parentNode;
        parent.parentNode.insertBefore(label, parent);
      });

      container.querySelectorAll('fieldset > .mb-3 > input').forEach(input => {
        input.classList.add('form-control');
        const wrapper = document.createElement('div');
        wrapper.className = 'col-sm-4';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);
      });

      document.querySelectorAll('#theProfilePage .property').forEach(prop => {
        const label = document.createElement('label');
        label.className = 'col-sm-2 control-label';
        label.textContent = prop.textContent;
        prop.parentNode.replaceChild(label, prop);
      });

      const profile = document.querySelector('#theProfilePage form#profile .mb-3');
      if (profile) {
        let textNode = null;
        for (let node of profile.childNodes) {
          if (node.nodeType === 3 && node.textContent.trim()) {
            textNode = node;
            break;
          }
        }
        if (textNode) {
          const wrapper = document.createElement('div');
          wrapper.className = 'col-sm-4';
          const p = document.createElement('p');
          p.className = 'form-control-static';
          p.appendChild(textNode.cloneNode(true));
          wrapper.appendChild(p);
          textNode.parentNode.insertBefore(wrapper, textNode);
        }
      }
    });
  </script>
{/if}

{if isset($loaded_plugins['BatchDownloader'])}
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const link = document.getElementById('batchDownloadLink');
      if (!link) return;

      const box = link.nextElementSibling;
      if (box && box.id === 'batchDownloadBox') {
        link.closest('li').classList.add('nav-item', 'dropdown');
        link.classList.add('nav-link', 'dropdown-toggle');
        link.classList.remove('pwg-state-default', 'pwg-button');
        link.setAttribute('data-bs-toggle', 'dropdown');
        link.setAttribute('href', '#');

        box.removeAttribute('style');
        box.setAttribute('role', 'menu');
        box.querySelectorAll('a').forEach(a => a.classList.add('dropdown-item'));
        box.querySelectorAll('.switchBoxTitle').forEach(title => {
          title.classList.add('dropdown-header');
          title.classList.remove('switchBoxTitle');
        });
        box.querySelectorAll('br').forEach(br => br.remove());
        box.classList.add('dropdown-menu', 'dropdown-menu-end');
        box.classList.remove('switchBox');

        const btnText = link.querySelector('.pwg-button-text');
        if (btnText) {
          btnText.classList.add('d-lg-none', 'ms-2');
          btnText.classList.remove('pwg-button-text');
        }
      } else {
        link.closest('li').classList.add('nav-item');
        link.classList.add('nav-link');
        link.classList.remove('pwg-state-default', 'pwg-button');
      }

      const icon = document.querySelector('.batch-downloader-icon');
      if (icon) {
        icon.classList.add('fas', 'fa-cloud-download-alt', 'fa-fw');
        icon.classList.remove('pwg-icon');
        const title = link.getAttribute('title') || '';
        icon.insertAdjacentHTML('afterend', '<span class="d-lg-none"> ' + title + '</span>');
      }
    });

    window.addEventListener('load', function() {
      const link = document.getElementById('batchDownloadLink');
      if (link && link.nextElementSibling && link.nextElementSibling.id === 'batchDownloadBox') {
        link.addEventListener('click', function() {
          bootstrap.Dropdown.getOrCreateInstance(link).toggle();
        });
      }
    });
  </script>
{/if}

{if isset($loaded_plugins['download_by_size'])}
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const sizeBox = document.getElementById('downloadSizeBox');
      if (!sizeBox) return;

      const switchLink = document.getElementById('downloadSwitchLink');
      if (!switchLink) return;

      const li = switchLink.closest('li');
      li.classList.add('dropdown');
      li.appendChild(sizeBox);

      switchLink.classList.add('dropdown-toggle');
      switchLink.classList.remove('pwg-state-default', 'pwg-button');
      switchLink.setAttribute('data-bs-toggle', 'dropdown');

      sizeBox.classList.add('dropdown-menu', 'dropdown-menu-end');
      sizeBox.classList.remove('switchBox');
      sizeBox.setAttribute('role', 'menu');
      sizeBox.removeAttribute('style');

      sizeBox.querySelectorAll('a').forEach(a => a.classList.add('dropdown-item'));
      sizeBox.querySelectorAll('.switchBoxTitle').forEach(title => {
        title.classList.add('dropdown-header');
        title.classList.remove('switchBoxTitle');
      });
      sizeBox.querySelectorAll('br').forEach(br => br.remove());
    });

    window.addEventListener('load', function() {
      const switchLink = document.getElementById('downloadSwitchLink');
      if (switchLink) {
        switchLink.addEventListener('click', function() {
          bootstrap.Dropdown.getOrCreateInstance(switchLink).toggle();
        });
      }
    });
  </script>
{/if}

{if isset($loaded_plugins['PWG_Stuffs'])}
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      let stuffAboveContent;
      {if $theme_config->page_header == 'jumbotron'}
        const jumbotron = document.querySelector('.jumbotron');
        stuffAboveContent = jumbotron ? jumbotron.nextElementSibling : null;
      {elseif $theme_config->page_header == 'fancy'}
        const header = document.querySelector('.page-header');
        stuffAboveContent = header ? header.nextElementSibling : null;
      {else}
        const navbar = document.querySelector('.navbar-main');
        stuffAboveContent = navbar ? navbar.nextElementSibling : null;
      {/if}

      if (stuffAboveContent && stuffAboveContent.classList.contains('pwgstuffs-container')) {
        const contextual = document.querySelector('.navbar-contextual');
        if (contextual) {
          contextual.parentNode.insertBefore(stuffAboveContent, contextual.nextSibling);
        }
      }
    });
  </script>
{/if}

{if isset($loaded_plugins['Piwecard']) && $BODY_ID == 'thePicturePage'}
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const btn = document.querySelector('.imageComment .createECardOpen');
      const target = document.getElementById('important-info');
      if (btn && target) {
        btn.classList.add('btn', 'btn-primary', 'mt-2');
        target.appendChild(btn);
      }
    });
  </script>
{/if}

{if isset($loaded_plugins['tag_groups']) && $BODY_ID == 'theTagsPage'}
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const table = document.querySelector('.container table');
      if (table) {
        table.classList.add('table', 'table-sm');
        table.id = 'tagGroupsTable';
      }
    });
  </script>
{/if}

{if isset($loaded_plugins['Contact1menu'])}
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const link = document.querySelector('#navbar-menubar a[title="{'Contact'|translate}"]');
      if (link) {
        link.classList.add('nav-link');
        const dt = link.closest('dt');
        if (dt) {
          dt.classList.add('nav-item');
          dt.id = 'Contact1menu';
          const li = document.createElement('li');
          li.id = dt.id;
          li.className = dt.className;
          while (dt.firstChild) li.appendChild(dt.firstChild);
          dt.parentNode.replaceChild(li, dt);
        }
      }
    });
  </script>
{/if}

{if isset($loaded_plugins['rv_tscroller'])}
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      if (window.RVTS && RVTS.start > 0) {
        const lastLink = document.querySelector('.navbar-contextual .navbar-brand a:last-child');
        const rvtsUp = document.getElementById('rvtsUp');
        if (lastLink && rvtsUp) {
          const href = lastLink.getAttribute('href');
          const html = lastLink.innerHTML;
          const msg = RVTS.prevMsg;
          rvtsUp.innerHTML = '<div id="rvtsUp" style="text-align:center;font-size:120%;margin:10px"><a href="' + href + '">' + html + '</a> | <a href="javascript:RVTS.loadUp()">' + msg + '</a></div>';
        }
      }
    });
  </script>
{/if}