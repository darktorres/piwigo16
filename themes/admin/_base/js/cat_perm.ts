import { GroupsCache, UsersCache } from './LocalStorageCache';
import { getPageData } from './page-data';

interface CatPermPageData {
    CACHE_KEYS: { groups: string; users: string; _hash: string };
    ROOT_URL: string;
    str_create: string;
    has_indirect_perms: boolean;
}

const pageData = getPageData<CatPermPageData>('pwg-cat-perm-data');

const groupsCache = new GroupsCache({
    serverKey: pageData.CACHE_KEYS.groups,
    serverId: pageData.CACHE_KEYS._hash,
    rootUrl: pageData.ROOT_URL,
});

const usersCache = new UsersCache({
    serverKey: pageData.CACHE_KEYS.users,
    serverId: pageData.CACHE_KEYS._hash,
    rootUrl: pageData.ROOT_URL,
});

groupsCache.attach(document.querySelector('[data-ts=groups]'));
usersCache.attach(document.querySelector('[data-ts=users]'));

/*---- Status / private-options + indirect-perm details (from {footer_script}) ----*/

function checkStatusOptions(): void {
    const checkedStatus = document.querySelector<HTMLInputElement>('input[name=status]:checked');
    const privateOptions = document.getElementById('privateOptions');
    if (!privateOptions) return;
    privateOptions.style.display = checkedStatus?.value === 'private' ? '' : 'none';
}

checkStatusOptions();
document.getElementById('selectStatus')?.addEventListener('change', checkStatusOptions);

if (pageData.has_indirect_perms) {
    document.querySelectorAll<HTMLElement>('.toggle-indirectPermissions').forEach((el) => {
        el.addEventListener('click', (e) => {
            e.preventDefault();
            document.querySelectorAll<HTMLElement>('.toggle-indirectPermissions').forEach((t) => {
                t.style.display = t.style.display === 'none' ? '' : 'none';
            });
            const details = document.getElementById('indirectPermissionsDetails');
            if (details) details.style.display = details.style.display === 'none' ? '' : 'none';
        });
    });
}

export {};
