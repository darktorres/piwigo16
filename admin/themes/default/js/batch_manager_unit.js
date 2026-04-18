import { TagsCache } from './LocalStorageCache.js';
import { pwgDatepicker } from './datepicker.js';
import GLightbox from 'glightbox';

const _docReady = function(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); };

_docReady(function() {
  // Tags cache initialization
  const tagsCache = new TagsCache({
    serverKey: window.tagsServerKey || '',
    serverId: window.tagsCacheServerId || '',
    rootUrl: window.rootUrl || ''
  });

  tagsCache.selectize(document.querySelectorAll('[data-selectize=tags]'), {
    lang: {
      'Add': window.str_create || 'Create'
    }
  });

  // Datepicker initialization
  pwgDatepicker(document.querySelectorAll('[data-datepicker]'), {
    showTimepicker: true,
    cancelButton: window.str_cancel || 'Cancel'
  });

  // Lightbox initialization
  GLightbox({ selector: 'a.preview-box' });
});
