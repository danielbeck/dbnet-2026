if ('showPopover' in HTMLElement.prototype) {
    for (const list of document.querySelectorAll('.footnotes')) {
        const template = list.nextElementSibling;
        if (!(template instanceof HTMLTemplateElement) || !template.matches('[data-footnote-panel]')) continue;
        for (const note of list.children) {
            const reference = document.getElementById(note.id.replace('footnote-', 'footnote-ref-'));
            const content = note.querySelector('[data-footnote-content]');
            if (!reference || !content) continue;
            const panel = template.content.firstElementChild.cloneNode(true);
            panel.id = `${note.id}-popup`;
            panel.setAttribute('aria-label', `Footnote ${reference.textContent}`);
            panel.querySelector('[data-footnote-panel-content]').append(...content.childNodes);
            document.body.append(panel);
            reference.setAttribute('aria-controls', panel.id);
            reference.setAttribute('aria-haspopup', 'dialog');
            reference.setAttribute('aria-expanded', 'false');
            reference.setAttribute('role', 'button');
            const position = () => {
                if (!panel.hasAttribute('data-open')) return;
                const anchor = reference.getBoundingClientRect();
                const bounds = panel.getBoundingClientRect();
                const viewport = window.visualViewport;
                const left = viewport ? viewport.offsetLeft : 0;
                const top = viewport ? viewport.offsetTop : 0;
                const width = viewport ? viewport.width : window.innerWidth;
                const height = viewport ? viewport.height : window.innerHeight;
                const x = Math.max(left + 8, Math.min(anchor.left, left + width - bounds.width - 8));
                const below = anchor.bottom + 4;
                const y = below + bounds.height <= top + height - 8 ? below : anchor.top - bounds.height - 4;
                panel.style.left = `${x}px`;
                panel.style.top = `${Math.max(top + 8, Math.min(y, top + height - bounds.height - 8))}px`;
            };
            const stopPositioning = () => {
                window.removeEventListener('resize', position);
                document.removeEventListener('scroll', position, true);
                window.visualViewport?.removeEventListener('resize', position);
                window.visualViewport?.removeEventListener('scroll', position);
            };
            panel.addEventListener('beforetoggle', event => {
                const open = event.newState === 'open';
                panel.toggleAttribute('data-open', open);
                reference.setAttribute('aria-expanded', String(open));
                if (open) {
                    window.addEventListener('resize', position);
                    document.addEventListener('scroll', position, true);
                    window.visualViewport?.addEventListener('resize', position);
                    window.visualViewport?.addEventListener('scroll', position);
                } else {
                    stopPositioning();
                }
            });
            reference.addEventListener('click', event => {
                event.preventDefault();
                panel.togglePopover();
                position();
            });
            reference.addEventListener('keydown', event => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                reference.click();
            });
            const close = () => {
                panel.hidePopover();
                reference.focus({ preventScroll: true });
            };
            panel.querySelector('.footnote-close')?.addEventListener('click', close);
            panel.addEventListener('keydown', event => {
                if (event.key !== 'Escape') return;
                event.preventDefault();
                close();
            });
            note.hidden = true;
        }
        list.hidden = [...list.children].every(note => note.hidden);
    }
}
