import { CategoriesCache } from './LocalStorageCache';
import { getPageData } from './page-data';

interface RatingPageData {
    CACHE_KEYS: { categories: string; _hash: string };
    ROOT_URL: string;
    str_create: string;
}

const pageData = getPageData<RatingPageData>('pwg-rating-data');

// Initialize CategoriesCache
const categoriesCache = new CategoriesCache({
    serverKey: pageData.CACHE_KEYS.categories,
    serverId: pageData.CACHE_KEYS._hash,
    rootUrl: pageData.ROOT_URL
});

categoriesCache?.selectize(document.querySelector('[data-selectize=categories]'));

export {};
