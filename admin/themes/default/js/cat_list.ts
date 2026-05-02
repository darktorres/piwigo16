import Cookies from 'js-cookie';

const qsa = <T extends HTMLElement = HTMLElement>(sel: string, ctx: Element | Document = document) =>
    Array.from(ctx.querySelectorAll<T>(sel));

let hoverAbort: AbortController | null = null;

function resetHovers(): AbortController {
    hoverAbort?.abort();
    hoverAbort = new AbortController();
    return hoverAbort;
}

function hover(el: HTMLElement, enter: () => void, leave: () => void, signal: AbortSignal) {
    el.addEventListener('mouseenter', enter, { signal });
    el.addEventListener('mouseleave', leave, { signal });
}

function css(sel: string, styles: Partial<CSSStyleDeclaration>) {
    qsa(sel).forEach(el => Object.assign(el.style, styles));
}

function setDisplayCompact() {
    removeIconDesc();
    const { signal } = resetHovers();

    css('.albumActions', { display: 'flex' });
    qsa('.categoryBox > .albumActions > a').forEach(el => {
        hover(el,
            () => { el.style.color = '#000000'; },
            () => { el.style.color = '#848484'; },
            signal
        );
    });

    qsa('.categoryBox').forEach(el => {
        el.classList.remove('line_cat', 'tile_cat');
        Object.assign(el.style, { minWidth: '250px', maxWidth: '350px', flexDirection: 'column', maxHeight: '180px', alignItems: 'unset', margin: '15px' });
    });
    qsa('.addAlbum').forEach(el => el.classList.remove('tile_add'));
    css('.albumInfos', { marginLeft: '0', flexDirection: 'column' });
    css('.albumIcon', { height: '60px' });
    css('.albumIcon span', { fontSize: '14px', width: '20px', padding: '8px' });
    css('.albumInfos p', { margin: '0', textAlign: 'center', whiteSpace: 'normal' });
    css('.albumInfos p:last-child', { width: 'auto' });
    css('.albumTop', { width: 'auto', justifyContent: 'center', flexDirection: 'row', alignItems: 'baseline', height: '65px' });
    css('.albumTitle', { padding: '0 15px' });
    css('.addAlbum', { minWidth: '250px', maxWidth: '350px', flexDirection: 'column', maxHeight: '180px', margin: '15px' });
    css('.addAlbum form label', { display: 'none' });
    css('.addAlbumHead', { flexDirection: 'column', transform: 'translateY(55px)', alignItems: 'center', marginTop: '-10px', transition: '0.4s ease', marginBottom: '0px' });
    css('.addAlbum form', { flexDirection: 'column', marginTop: '0', marginBottom: '0', transitionDelay: '0s' });
    css('.addAlbum.input-mode form', { transitionDelay: '0.4s' });
    css('.addAlbum form input', { margin: '0px 10px 0px 10px' });
    css('.addAlbum form button', { margin: '10px auto 0 auto' });
    css('.addAlbum p', { marginBottom: '0px' });
    css('.addAlbumHead p', { marginLeft: '0' });
    css('.addAlbumHead span', { fontSize: '14px', width: '20px', height: '20px', padding: '8px' });
    css('.albumActions', { flexDirection: 'row', marginTop: 'auto', width: '100%' });
    css('.albumActions a', { minWidth: '0px' });
    qsa('.albumActions a:first-child').forEach(el => { el.style.marginLeft = '35px'; });
    qsa('.albumActions a:last-child').forEach(el => { el.style.marginRight = '35px'; });
}

function setDisplayLine() {
    removeIconDesc();
    const { signal } = resetHovers();

    css('.albumActions', { display: 'flex' });

    qsa('.categoryBox').forEach(el => {
        hover(el,
            () => {
                el.style.background = '#ffd7ad';
                el.querySelector<HTMLElement>('.albumInfos')!.style.color = '#515151';
                qsa('.albumActions > a', el).forEach(a => { a.style.color = '#515151'; });
                qsa('.albumTop > .albumIcon > span', el).forEach(s => s.classList.add('albumIconLineHover'));
            },
            () => {
                el.style.background = '#fafafa';
                el.querySelector<HTMLElement>('.albumInfos')!.style.color = '#a9a9a9';
                qsa('.albumActions > a', el).forEach(a => { a.style.color = '#848484'; });
                qsa('.albumTop > .albumIcon > span', el).forEach(s => s.classList.remove('albumIconLineHover'));
            },
            signal
        );
    });

    qsa('.categoryBox > .albumActions > a').forEach(el => {
        hover(el,
            () => { el.style.color = '#000000'; },
            () => { el.style.color = '#515151'; },
            signal
        );
    });

    qsa('.categoryBox').forEach(el => {
        el.classList.add('line_cat');
        el.classList.remove('tile_cat');
        Object.assign(el.style, { minWidth: '90%', maxWidth: '100%', flexDirection: 'row', maxHeight: '60px', alignItems: 'unset', margin: '5px 15px' });
    });
    qsa('.addAlbum').forEach(el => el.classList.remove('tile_add'));
    css('.albumIcon', { height: '60px' });
    css('.albumIcon span', { fontSize: '14px', width: '20px', padding: '8px' });
    css('.addAlbumHead span', { fontSize: '14px', width: '20px', height: '20px', padding: '8px' });
    css('.albumInfos', { marginLeft: 'auto', flexDirection: 'row', justifyContent: 'space-around', width: 'auto' });
    css('.albumInfos p', { textAlign: 'right', margin: '0', whiteSpace: 'nowrap' });
    css('.albumInfos p:last-child', { width: '270px' });
    css('.albumTop', { width: '35%', justifyContent: 'flex-start', flexDirection: 'row', alignItems: 'baseline', height: '75px' });
    css('.albumTitle', { padding: '0 15px' });
    css('.addAlbum', { minWidth: '90%', maxWidth: '100%', flexDirection: 'row', maxHeight: '60px', margin: '15px 15px 5px 15px' });
    css('.addAlbum form label', { display: 'none' });
    css('.addAlbumHead', { flexDirection: 'row', transform: 'translateX(200px)', alignItems: 'center', marginTop: '0', marginBottom: '0' });
    css('.addAlbum form', { flexDirection: 'row', marginTop: '0', marginBottom: '0', transitionDelay: '0s' });
    css('.addAlbum.input-mode form', { transitionDelay: '0s' });
    css('.addAlbum form', { alignItems: 'center' });
    css('.addAlbum form input', { margin: '0px 10px 0px 10px' });
    css('.addAlbum form button', { margin: '0px 20px' });
    css('.addAlbum p', { marginBottom: '0px' });
    css('.addAlbumHead p', { marginLeft: '15px' });
    css('.albumActions', { flexDirection: 'row', margin: 'auto 0px', width: '300px' });
    css('.albumActions a', { minWidth: '30px' });
    qsa('.albumActions a:first-child').forEach(el => { el.style.marginLeft = '35px'; });
    qsa('.albumActions a:last-child').forEach(el => { el.style.marginRight = '35px'; });
}

function setDisplayTile() {
    ShowIconDesc();
    const { signal } = resetHovers();

    css('.albumActions', { display: 'flex' });

    qsa('.categoryBox > .albumActions > a').forEach(el => {
        hover(el,
            () => { el.style.color = '#FFA646'; },
            () => { el.style.color = '#848484'; },
            signal
        );
    });

    AddHoverOnAlbumActions(signal);

    css('.addAlbum.input-mode form', { transitionDelay: '0s' });
    qsa('.categoryBox').forEach(el => {
        el.classList.remove('line_cat');
        el.classList.add('tile_cat');
        Object.assign(el.style, { minWidth: '220px', maxWidth: '280px', flexDirection: 'column', maxHeight: '320px', alignItems: 'center', margin: '15px' });
    });
    qsa('.addAlbum').forEach(el => el.classList.add('tile_add'));
    css('.albumActions', { flexDirection: 'column', margin: 'auto', alignItems: 'flex-start', width: '75%' });
    css('.albumInfos', { marginLeft: '0', flexDirection: 'column' });
    css('.albumInfos p:last-child', { width: 'auto' });
    css('.albumInfos p', { margin: '0', textAlign: 'center', whiteSpace: 'normal' });
    css('.albumIcon', { height: '80px' });
    css('.albumIcon span', { fontSize: '19px', width: '27px', padding: '10px' });
    css('.albumTop', { width: '85%', flexDirection: 'column', alignItems: 'unset', height: '110px' });
    css('.albumTitle', { padding: '0' });
    css('.addAlbum', { minWidth: '220px', maxWidth: '280px', flexDirection: 'column', maxHeight: '320px', margin: '15px' });
    css('.addAlbumHead', { flexDirection: 'column', transform: 'translateY(75px)', alignItems: 'center', marginTop: '10px', transition: '0.4s ease', marginBottom: '0' });
    css('.addAlbum form', { flexDirection: 'column', marginTop: 'auto', marginBottom: '20px', transitionDelay: '0s' });
    css('.addAlbum form input', { margin: '0px 10px 10px 10px' });
    css('.addAlbum form button', { margin: '10px auto 0 auto' });
    css('.addAlbum p', { marginBottom: '20px' });
    css('.addAlbum form label', { display: 'flex', margin: '-25px 0 0 15px' });
    css('.addAlbumHead p', { marginLeft: '0' });
    css('.addAlbumHead span', { fontSize: '19px', width: '27px', height: '27px', padding: '10px' });
    css('.albumInfos p', { margin: '0' });
    css('.albumActions a', { minWidth: '0px' });
    qsa('.albumActions a:first-child').forEach(el => { el.style.marginLeft = '5px'; });
    qsa('.albumActions a:last-child').forEach(el => { el.style.marginLeft = '5px'; });
}

function ShowIconDesc() {
    qsa('.albumActions span.iconLegend').forEach(el => { el.style.display = ''; });
}

function removeIconDesc() {
    qsa('.albumActions span.iconLegend').forEach(el => { el.style.display = 'none'; });
}

function AddHoverOnAlbumActions(signal: AbortSignal) {
    qsa('.albumActions').forEach(el => { el.style.display = 'none'; });
    qsa('.categoryBox').forEach(el => {
        hover(el,
            () => { el.querySelector<HTMLElement>('.albumActions')!.style.display = 'flex'; },
            () => { el.querySelector<HTMLElement>('.albumActions')!.style.display = 'none'; },
            signal
        );
    });
}

document.addEventListener('DOMContentLoaded', () => {
    if (!Cookies.get('pwg_album_manager_view')) {
        Cookies.set('pwg_album_manager_view', 'tile');
    }

    document.querySelectorAll<HTMLElement>('.addAlbum').forEach(el => {
        el.addEventListener('click', (e) => {
            if ((e.target as HTMLElement).className !== 'cancelAddAlbum') {
                document.querySelectorAll<HTMLElement>('.addAlbum').forEach(a => a.classList.add('input-mode'));
                if (Cookies.get('pwg_album_manager_view') !== 'tile') {
                    document.querySelectorAll<HTMLElement>('.addAlbum p').forEach(p => { p.style.display = 'none'; });
                }
            }
        });
    });

    document.querySelectorAll<HTMLElement>('.cancelAddAlbum').forEach(el => {
        el.addEventListener('click', () => {
            document.querySelectorAll<HTMLElement>('.addAlbum').forEach(a => a.classList.remove('input-mode'));
            document.querySelectorAll<HTMLElement>('.addAlbum p').forEach(p => { p.style.display = ''; });
        });
    });

    const displayCompact = document.getElementById('displayCompact') as HTMLInputElement;
    const displayLine = document.getElementById('displayLine') as HTMLInputElement;
    const displayTile = document.getElementById('displayTile') as HTMLInputElement;

    if (displayCompact?.checked) setDisplayCompact();
    if (displayLine?.checked) setDisplayLine();
    if (displayTile?.checked) setDisplayTile();

    displayCompact?.addEventListener('change', () => {
        setDisplayCompact();
        if (document.querySelector('.addAlbum.input-mode')) {
            document.querySelectorAll<HTMLElement>('.addAlbum p').forEach(p => { p.style.display = 'none'; });
        }
        Cookies.set('pwg_album_manager_view', 'compact');
    });

    displayLine?.addEventListener('change', () => {
        setDisplayLine();
        if (document.querySelector('.addAlbum.input-mode')) {
            document.querySelectorAll<HTMLElement>('.addAlbum p').forEach(p => { p.style.display = 'none'; });
        }
        Cookies.set('pwg_album_manager_view', 'line');
    });

    displayTile?.addEventListener('change', () => {
        setDisplayTile();
        if (document.querySelector('.addAlbum.input-mode')) {
            document.querySelectorAll<HTMLElement>('.addAlbum p').forEach(p => { p.style.display = ''; });
        }
        Cookies.set('pwg_album_manager_view', 'tile');
    });
});

document.querySelector<HTMLElement>('.addAlbumHead')?.addEventListener('click', () => {
    document.querySelector<HTMLInputElement>('.addAlbum input[name=virtual_name]')?.focus();
});

export {};
