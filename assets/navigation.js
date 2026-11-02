(() => {
    const rows = document.querySelectorAll('#mainmenu ul');
    const revealActiveTabs = () => {
        for (const row of rows) {
            const active = row.querySelector('li.active, li:has([aria-current="page"])');
            if (!active) continue;
            const bounds = row.getBoundingClientRect();
            const tab = active.getBoundingClientRect();
            if (tab.left < bounds.left) {
                row.scrollLeft += tab.left - bounds.left;
            } else if (tab.right > bounds.left + row.clientWidth) {
                row.scrollLeft += tab.right - bounds.left - row.clientWidth;
            }
        }
    };

    revealActiveTabs();
    document.fonts.ready.then(revealActiveTabs);
    window.addEventListener('pageshow', revealActiveTabs);
    window.addEventListener('pageshow', event => {
        const navigation = performance.getEntriesByType('navigation')[0];
        if (event.persisted || navigation?.type === 'back_forward') return;
        document.fonts.ready.then(() => {
            if (window.matchMedia('(max-width: 620px)').matches
                && !window.location.hash && window.scrollY === 0) {
                document.querySelector('#main')?.scrollIntoView({ behavior: 'instant', block: 'start' });
            }
        });
    });
    if (typeof ResizeObserver !== 'undefined') {
        const observer = new ResizeObserver(revealActiveTabs);
        for (const row of rows) observer.observe(row);
    } else {
        window.addEventListener('resize', revealActiveTabs);
    }
})();
