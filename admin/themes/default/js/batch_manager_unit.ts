import { initModule } from './moduleInit.js';
import { TagsCache } from './LocalStorageCache.js';
import { pwgDatepicker } from './datepicker.js';
import GLightbox from 'glightbox';

interface BatchManagerUnitConfig {
    tagsServerKey?: string;
    tagsCacheServerId?: string;
    rootUrl?: string;
    strCreate?: string;
    strCancel?: string;
}

export function init(cfg: BatchManagerUnitConfig): void {
    const { tagsServerKey = '', tagsCacheServerId = '', rootUrl = '', strCreate = 'Create', strCancel = 'Cancel' } = cfg;

    const tagsCache = new TagsCache({
        serverKey: tagsServerKey,
        serverId: tagsCacheServerId,
        rootUrl,
    });

    tagsCache.selectize(document.querySelectorAll('[data-selectize=tags]'), {
        lang: { Add: strCreate },
    });

    pwgDatepicker(document.querySelectorAll('[data-datepicker]'), {
        showTimepicker: true,
        cancelButton: strCancel,
    });

    GLightbox({ selector: 'a.preview-box' });
}

initModule(init as (cfg: Record<string, unknown>) => void);
