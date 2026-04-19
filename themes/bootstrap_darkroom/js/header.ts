import { setColorOpacity } from './theme.ts';

export function initHeader(): void {
    const body = document.body;
    const quicksearchNavbar  = body.dataset['quicksearchNavbar']  === '1';
    const pageHeader         = body.dataset['pageHeader']         || '';
    const pageHeaderBothNavs = body.dataset['pageHeaderBothNavs'] === '1';
    const hasMenubar         = body.dataset['hasMenubar']         === '1';

    if (quicksearchNavbar) {
        const qsearchIcon = document.querySelector('#navbar-menubar>#quicksearch>.fa-search');
        const qsearchText = document.querySelector<HTMLInputElement>('#navbar-menubar>#quicksearch #qsearchInput');
        if (qsearchIcon) {
            qsearchIcon.addEventListener('click', function () {
                if (qsearchText) qsearchText.focus();
            });
        }
        document.addEventListener('DOMContentLoaded', function () {
            const quicksearch = document.querySelector<HTMLElement>('#navbar-menubar>#quicksearch');
            const navLink = document.querySelector('.nav-link');
            if (quicksearch && navLink) {
                quicksearch.style.color = window.getComputedStyle(navLink).color;
            }
        });
    }

    if (pageHeader === 'fancy') {
        let pageHeaderEl = document.querySelector<HTMLElement>('.page-header');
        let sfactor = (pageHeaderEl ? pageHeaderEl.offsetHeight : 0) - 50;
        const color = 'rgba(0, 0, 0, 1)';

        window.addEventListener('resize', function () {
            pageHeaderEl = document.querySelector<HTMLElement>('.page-header');
            sfactor = (pageHeaderEl ? pageHeaderEl.offsetHeight : 0) - 50;
        });

        function setNavbarOpacity(): void {
            const alpha = window.scrollY / sfactor;
            const phEl = document.querySelector<HTMLElement>('.page-header');
            if (phEl) phEl.style.backgroundColor = setColorOpacity(color, alpha) + ' !important';

            const contentCenter = document.querySelector<HTMLElement>('.page-header .content-center');
            if (contentCenter) contentCenter.style.opacity = String(1 - alpha * 2.5);

            const phImage = document.querySelector<HTMLElement>('.page-header .page-header-image');
            if (phImage) phImage.style.opacity = String(1 - alpha);

            const top_offset = window.scrollY;
            const pageHeaderH    = (document.querySelector<HTMLElement>('.page-header')        )?.offsetHeight ?? 0;
            const navbarMainH    = (document.querySelector<HTMLElement>('.navbar-main')        )?.offsetHeight ?? 0;
            const navbarContextH = (document.querySelector<HTMLElement>('.navbar-contextual')  )?.offsetHeight ?? 0;
            const p_size = pageHeaderH - navbarMainH - navbarContextH;
            const c_size = pageHeaderH - navbarContextH;

            const ctxCollapse = document.querySelector('.navbar-contextual .navbar-collapse');
            if (ctxCollapse && !ctxCollapse.classList.contains('show')) {
                const navCtxTransp = document.querySelector<HTMLElement>('.navbar-contextual.navbar-transparent');
                if (navCtxTransp) {
                    if (top_offset >= c_size) {
                        navCtxTransp.style.backgroundColor = setColorOpacity(color, 1) + ' !important';
                    } else {
                        navCtxTransp.removeAttribute('style');
                    }
                }
            }

            const navbarMain = document.querySelector<HTMLElement>('.navbar-main');
            if (top_offset >= p_size) {
                const dropdown = document.querySelector('.navbar-main .navbar-nav .nav-item.dropdown.show');
                if (!dropdown && navbarMain) navbarMain.style.top = (0 - (top_offset - p_size)) + 'px';
            } else {
                if (navbarMain) navbarMain.style.top = '0';
            }

            if (!document.querySelector('.page-header.page-header-small')) {
                const navCtxTransp2 = document.querySelector<HTMLElement>('.navbar-contextual.navbar-transparent');
                if (navCtxTransp2) {
                    if (top_offset > 5) {
                        navCtxTransp2.style.display = '';
                        navCtxTransp2.classList.add('d-flex');
                    } else {
                        navCtxTransp2.style.display = 'none';
                        navCtxTransp2.classList.remove('d-flex');
                    }
                }
            }
        }

        window.addEventListener('scroll', setNavbarOpacity);

        const navbarMainCollapse = document.querySelector('.navbar-main .navbar-collapse');
        if (navbarMainCollapse) {
            navbarMainCollapse.addEventListener('show.bs.collapse', function () {
                const nm = document.querySelector<HTMLElement>('.navbar-main');
                if (nm) nm.style.backgroundColor = 'rgba(0, 0, 0, 0.9) !important';
            });
            navbarMainCollapse.addEventListener('hidden.bs.collapse', function () {
                const nm = document.querySelector<HTMLElement>('.navbar-main');
                if (nm) nm.removeAttribute('style');
            });
        }

        if (pageHeaderBothNavs) {
            const ctxCollapseBothNavs = document.querySelector('.navbar-contextual .navbar-collapse');
            if (ctxCollapseBothNavs) {
                ctxCollapseBothNavs.addEventListener('show.bs.collapse', function () {
                    const nc = document.querySelector<HTMLElement>('.navbar-contextual');
                    if (nc) nc.style.backgroundColor = 'rgba(0, 0, 0, 0.9) !important';
                });
                ctxCollapseBothNavs.addEventListener('hidden.bs.collapse', function () {
                    const nc = document.querySelector<HTMLElement>('.navbar-contextual');
                    if (nc) nc.removeAttribute('style');
                    setNavbarOpacity();
                });
            }
        }
    }

    if (pageHeader === 'fancy' && pageHeaderBothNavs) {
        document.addEventListener('DOMContentLoaded', function () {
            const navbarContextual = document.querySelector<HTMLElement>('.navbar-contextual');
            if (hasMenubar) {
                const ph = document.querySelector<HTMLElement>('.page-header');
                if (ph) ph.classList.add('mb-5');
                if (navbarContextual) navbarContextual.classList.add('navbar-transparent', 'navbar-sm');
            } else {
                if (navbarContextual) {
                    navbarContextual.classList.remove('navbar-light');
                    navbarContextual.classList.add('navbar-dark', 'navbar-forced-sm');
                }
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', initHeader);
