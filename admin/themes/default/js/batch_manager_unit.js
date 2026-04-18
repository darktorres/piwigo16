import { initModule } from './moduleInit.js';
import { TagsCache } from './LocalStorageCache.js';
import { pwgDatepicker } from './datepicker.js';
import GLightbox from 'glightbox';

export function init(cfg) {
  const { tagsServerKey, tagsCacheServerId, rootUrl, strCreate, strCancel } = cfg;

  // Tags cache initialization
  const tagsCache = new TagsCache({
    serverKey: tagsServerKey || '',
    serverId: tagsCacheServerId || '',
    rootUrl: rootUrl || ''
  });

  tagsCache.selectize(document.querySelectorAll('[data-selectize=tags]'), {
    lang: {
      'Add': strCreate || 'Create'
    }
  });

  // Datepicker initialization
  pwgDatepicker(document.querySelectorAll('[data-datepicker]'), {
    showTimepicker: true,
    cancelButton: strCancel || 'Cancel'
  });

  // Lightbox initialization
  GLightbox({ selector: 'a.preview-box' });
}

initModule(init);
