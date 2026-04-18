import { initModule } from './moduleInit.js';
import { GroupsCache, UsersCache } from './LocalStorageCache.js';

interface CatPermConfig {
    CACHE_KEYS?: { groups?: string; users?: string; _hash?: string };
    ROOT_URL?: string;
    nb_users_granted_indirect?: number;
}

export function init(cfg: CatPermConfig): void {
    const cacheKeys = cfg.CACHE_KEYS ?? {};
    const rootUrl = cfg.ROOT_URL ?? '';

    const groupsCache = new GroupsCache({ serverKey: cacheKeys.groups ?? '', serverId: cacheKeys._hash, rootUrl });
    groupsCache.selectize(document.querySelectorAll('[data-selectize=groups]'));

    const usersCache = new UsersCache({ serverKey: cacheKeys.users ?? '', serverId: cacheKeys._hash, rootUrl });
    usersCache.selectize(document.querySelectorAll('[data-selectize=users]'));

    function checkStatusOptions(): void {
        const checked = document.querySelector<HTMLInputElement>("input[name=status]:checked");
        const privateOptions = document.getElementById("privateOptions");
        if (privateOptions) {
            privateOptions.style.display = (checked && checked.value === "private") ? '' : 'none';
        }
    }

    checkStatusOptions();
    document.getElementById("selectStatus")?.addEventListener('change', checkStatusOptions);

    if (cfg.nb_users_granted_indirect && cfg.nb_users_granted_indirect > 0) {
        document.querySelectorAll<HTMLElement>(".toggle-indirectPermissions").forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelectorAll<HTMLElement>(".toggle-indirectPermissions").forEach(t => {
                    t.style.display = t.style.display === 'none' ? '' : 'none';
                });
                const details = document.getElementById("indirectPermissionsDetails");
                if (details) details.style.display = details.style.display === 'none' ? '' : 'none';
            });
        });
    }
}

initModule(init as (cfg: Record<string, unknown>) => void);
