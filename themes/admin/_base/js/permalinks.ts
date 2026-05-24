import '../css/pages/permalinks.css';
import { getPageData } from './page-data';

interface PermalinksPageData {
    nb_cats: number;
}

const { nb_cats } = getPageData<PermalinksPageData>();

const h1 = document.querySelector('h1');
if (h1) {
    const badge = document.createElement('span');
    badge.className = 'badge-number';
    badge.textContent = String(nb_cats);
    h1.appendChild(badge);
}

document.getElementById('addPermalinkOpen')?.addEventListener('click', (e) => {
    e.preventDefault();
    const addPermalink = document.getElementById('addPermalink');
    const showAddPermalink = document.getElementById('showAddPermalink');
    if (addPermalink) addPermalink.style.display = '';
    if (showAddPermalink) showAddPermalink.style.display = 'none';
});

document.getElementById('addPermalinkClose')?.addEventListener('click', (e) => {
    e.preventDefault();
    const addPermalink = document.getElementById('addPermalink');
    const showAddPermalink = document.getElementById('showAddPermalink');
    if (addPermalink) addPermalink.style.display = 'none';
    if (showAddPermalink) showAddPermalink.style.display = '';
});
