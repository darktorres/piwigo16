import '../fontello/css/fontello.css';
import '../fontello/css/animation.css';
import '../css/components/whats_new.css';

interface AccordionOptions {
    header: string;
    content: string;
    active: number;
}

const SLIDE_DURATION = 180;

function expand(el: HTMLElement): void {
    el.style.height = '0px';
    el.style.overflow = 'hidden';
    el.style.display = '';
    const target = el.scrollHeight;
    const anim = el.animate(
        [
            { height: '0px', opacity: 0 },
            { height: `${target}px`, opacity: 1 },
        ],
        { duration: SLIDE_DURATION, easing: 'ease-out', fill: 'forwards' }
    );
    anim.onfinish = () => {
        el.style.overflow = '';
        el.style.height = '';
        anim.cancel();
    };
}

function collapse(el: HTMLElement): void {
    if (el.style.display === 'none') return;
    const start = el.scrollHeight;
    el.style.overflow = 'hidden';
    const anim = el.animate(
        [
            { height: `${start}px`, opacity: 1 },
            { height: '0px', opacity: 0 },
        ],
        { duration: SLIDE_DURATION, easing: 'ease-out', fill: 'forwards' }
    );
    anim.onfinish = () => {
        el.style.display = 'none';
        el.style.overflow = '';
        el.style.height = '';
        anim.cancel();
    };
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
        const header =
            (e.target as HTMLElement | null)?.closest<HTMLElement>(settings.header) ?? null;
        if (!header) return;
        let next = header.nextElementSibling as HTMLElement | null;
        while (next && !next.matches(settings.content)) {
            next = next.nextElementSibling as HTMLElement | null;
        }
        if (!next) return;
        const target = next;
        contents.forEach((c) => {
            if (c === target) {
                if (c.style.display === 'none') expand(c);
            } else {
                collapse(c);
            }
        });
    });
}

const menubarEl = document.getElementById('menubar');
const activeAttr = menubarEl?.dataset.activeMenu;
const activeMenu = activeAttr !== undefined && activeAttr !== '' ? parseInt(activeAttr, 10) : 0;
lightAccordion(menubarEl, { active: activeMenu });

/* If we have several infos/errors/warnings, show them as bulleted list. */
const eiw = ['infos', 'erros', 'warnings', 'messages'];
for (const boxType of eiw) {
    const lis = document.querySelectorAll<HTMLElement>(`.${boxType} ul li`);
    if (lis.length > 1) {
        lis.forEach((li) => {
            li.style.listStyleType = 'square';
        });
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
