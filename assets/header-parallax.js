(() => {
    const header = document.querySelector('#mainmenu');
    if (!header) return;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let frame = null;

    const update = () => {
        frame = null;
        const scroll = Math.max(0, window.scrollY);
        const offset = Math.min(scroll, header.offsetHeight / 0.6) * 0.4;
        header.style.translate = `0 ${offset}px`;
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
            header.style.removeProperty('translate');
            return;
        }
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule);
        schedule();
    };

    reducedMotion.addEventListener('change', configure);
    configure();
})();
