interface AccordionOptions {
    header: string;
    content: string;
    active: number;
}

function lightAccordion(el: HTMLElement | null, options: Partial<AccordionOptions>): void {
    if (!el) return;
    const settings: AccordionOptions = {
        header: 'dt',
        content: 'dd',
        active: 0,
        ...options,
    };

    const contents = Array.from(el.querySelectorAll<HTMLElement>(settings.content));
    contents.forEach((c, idx) => {
        if (idx !== settings.active) {
            c.style.display = 'none';
        }
    });

    el.addEventListener('click', (e) => {
        let header = (e.target as HTMLElement | null)?.closest<HTMLElement>(settings.header) ?? null;
        if (!header) return;
        let content = header.nextElementSibling as HTMLElement | null;
        while (content && !content.matches(settings.content)) {
            content = content.nextElementSibling as HTMLElement | null;
        }
        if (!content) return;
        contents.forEach((c) => {
            c.style.display = c === content ? '' : 'none';
        });
    });
}

lightAccordion(document.getElementById('menubar'), { active: 0 });

/* If we have several infos/errors/warnings, show them as bulleted list. */
const eiw = ['infos', 'erros', 'warnings', 'messages'];
for (const boxType of eiw) {
    const lis = document.querySelectorAll<HTMLElement>(`.${boxType} ul li`);
    if (lis.length > 1) {
        lis.forEach((li) => { li.style.listStyleType = 'square'; });
        document.querySelectorAll<HTMLElement>(`.${boxType} .eiw-icon`).forEach((icon) => {
            icon.style.marginRight = '20px';
        });
    }
}

const h2 = document.querySelector('h2');
const h1 = document.querySelector('h1');
if (h2 && h1) {
    h1.innerHTML = h2.innerHTML;
}
