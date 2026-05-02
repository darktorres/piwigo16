document.querySelectorAll<HTMLImageElement>('.std_pgs_mini_previews img').forEach((img) => {
    img.addEventListener('click', () => {
        document.querySelectorAll<HTMLImageElement>('.std_pgs_mini_previews img').forEach((i) => {
            i.classList.remove('selected');
        });
        img.classList.add('selected');

        const skinInput = document.querySelector<HTMLInputElement>(
            'input[name=std_pgs_selected_skin]'
        );
        if (skinInput) {
            skinInput.value = img.id;
        }

        const preview_light_path = 'themes/standard_pages/skins/light-' + img.id + '.jpg';
        const preview_dark_path = 'themes/standard_pages/skins/dark-' + img.id + '.jpg';

        const previewLight = document.querySelector<HTMLImageElement>(
            '.std_pgs_selected_preview img#preview-light'
        );
        const previewDark = document.querySelector<HTMLImageElement>(
            '.std_pgs_selected_preview img#preview-dark'
        );
        if (previewLight) {
            previewLight.setAttribute('src', preview_light_path);
        }
        if (previewDark) {
            previewDark.setAttribute('src', preview_dark_path);
        }
    });
});

document.querySelectorAll<HTMLInputElement>('input[name=std_pgs_display_logo]').forEach((radio) => {
    radio.addEventListener('click', () => {
        const customLogoPreviews = document.querySelectorAll<HTMLElement>('.custom_logo_preview');
        if (radio.value === 'custom_logo') {
            customLogoPreviews.forEach((el) => {
                el.classList.add('show');
                el.classList.remove('hide');
            });
        } else {
            customLogoPreviews.forEach((el) => {
                el.classList.add('hide');
                el.classList.remove('show');
            });
        }
    });
});

const miniPreviewsEl = document.querySelector<HTMLElement>('.std_pgs_mini_previews');
if (miniPreviewsEl) {
    const selectedMini = miniPreviewsEl.querySelector<HTMLElement>('.selected');
    if (selectedMini) {
        miniPreviewsEl.scrollTop = selectedMini.offsetTop - miniPreviewsEl.offsetTop;
    }
}

const changeLogo = document.getElementById('change_logo');
if (changeLogo) {
    changeLogo.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelectorAll<HTMLElement>('.use_existing_logo_container').forEach((el) => {
            el.style.display = '';
        });
        document.querySelectorAll<HTMLElement>('.change_logo_container').forEach((el) => {
            el.style.display = 'none';
        });
    });
}

const useExistingLogo = document.getElementById('use_existing_logo');
if (useExistingLogo) {
    useExistingLogo.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelectorAll<HTMLElement>('.change_logo_container').forEach((el) => {
            el.style.display = '';
        });
        document.querySelectorAll<HTMLElement>('.use_existing_logo_container').forEach((el) => {
            el.style.display = 'none';
        });
        const logoInput = document.getElementById('std_pgs_logo') as HTMLInputElement | null;
        if (logoInput) {
            logoInput.value = '';
        }
    });
}
