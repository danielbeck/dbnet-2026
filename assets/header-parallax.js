(() => {
    const header = document.querySelector('#mainmenu');
    const stage = document.querySelector('.footer-stage');
    const footer = stage?.querySelector('.site-footer');
    const logo = footer?.querySelector('.site-logo');
    if (!header && !footer) return;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let frame = null;

    const update = () => {
        frame = null;
        const scroll = Math.max(0, window.scrollY);
        if (header) {
            const offset = Math.min(scroll, header.offsetHeight / 0.6) * 0.4;
            header.style.translate = `0 ${offset}px`;
        }
        if (footer) {
            // Measure the untransformed wrapper so animation cannot change its own scroll range.
            const bounds = stage.getBoundingClientRect();
            const progress = Math.max(0, Math.min(1, (window.innerHeight - bounds.top) / Math.max(1, bounds.height)));
            footer.style.translate = `0 ${(1 - progress) * window.innerHeight * 0.2}px`;
            if (logo) {
                const logoProgress = Math.max(0, (progress - 0.35) / 0.65);
                logo.style.translate = `0 ${(1 - logoProgress) * window.innerHeight * 0.65}px`;
            }
        }
    };
    const schedule = () => {
        if (frame === null) frame = window.requestAnimationFrame(update);
    };
    const configure = () => {
        window.removeEventListener('scroll', schedule);
        window.removeEventListener('resize', schedule);
        if (frame !== null) window.cancelAnimationFrame(frame);
        frame = null;
        if (reducedMotion.matches) {
            for (const element of [header, footer, logo]) {
                element?.style.removeProperty('translate');
            }
            return;
        }
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule);
        schedule();
    };

    reducedMotion.addEventListener('change', configure);
    if (typeof ResizeObserver !== 'undefined') {
        const observer = new ResizeObserver(() => {
            if (!reducedMotion.matches) schedule();
        });
        if (header) observer.observe(header);
        if (stage) observer.observe(stage);
    }
    configure();
})();
