import { GDMasonry } from './masonry.ts';

export const GDThumb = {

    _initialized: false,

    setup(method: string, max_height: number, margin: number): void {
        if (GDThumb._initialized) return;
        GDThumb._initialized = true;

        GDThumb.merge();
        const ul = document.querySelector<HTMLElement>("ul#thumbnails");
        if (ul) ul.classList.add("thumbnails");
        GDMasonry.init(max_height, margin);

        document.querySelectorAll<HTMLElement>("ul.thumbnails").forEach(function (list) {
            list.addEventListener("click", function (e) {
                const leg = (e.target as Element).closest(".thumbLegend.overlay, .thumbLegend.overlay-ex");
                if (!leg) return;
                const a = leg.parentElement && leg.parentElement.querySelector<HTMLAnchorElement>("a");
                if (a) window.location.href = a.getAttribute("href") ?? '';
            });
        });
    },

    build(): void {
        GDMasonry.positionNew();
    },

    merge(): void {
        const albums = document.querySelector<HTMLElement>(".thumbnailCategories");
        const photos = document.querySelector<HTMLElement>("#content ul#thumbnails");

        if (albums && photos) {
            albums.insertAdjacentHTML('beforeend', photos.innerHTML);
            photos.remove();
            const loaders = document.querySelectorAll("div.loader");
            if (loaders.length > 1) loaders[1]!.remove();
        }

        if (albums) {
            albums.id = "thumbnails";
        }
    },
};
