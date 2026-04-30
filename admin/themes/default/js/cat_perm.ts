import { GroupsCache, UsersCache } from './LocalStorageCache';
import { getPageData } from './page-data';

interface CatPermPageData {
    CACHE_KEYS: { groups: string; users: string; _hash: string };
    ROOT_URL: string;
    str_create: string;
}

const pageData = getPageData<CatPermPageData>('pwg-cat-perm-data');

// Initialize caches
const groupsCache = new GroupsCache({
    serverKey: pageData.CACHE_KEYS.groups,
    serverId: pageData.CACHE_KEYS._hash,
    rootUrl: pageData.ROOT_URL
});

const usersCache = new UsersCache({
    serverKey: pageData.CACHE_KEYS.users,
    serverId: pageData.CACHE_KEYS._hash,
    rootUrl: pageData.ROOT_URL
});

groupsCache?.selectize(document.querySelector('[data-selectize=groups]'));
usersCache?.selectize(document.querySelector('[data-selectize=users]'));

export {};
