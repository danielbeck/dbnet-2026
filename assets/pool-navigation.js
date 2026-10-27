const poolItem = document.querySelector('.pool-item');
if (poolItem) {
    const blocked = target => document.querySelector('dialog[open], .footnote-popup[data-open]')
        || (target instanceof Element && target.closest('a, button, input, textarea, select, [contenteditable]:not([contenteditable="false"]), pre, code'));
    const navigate = direction => {
        const link = poolItem.querySelector(`.item-navigation a[rel="${direction}"]`);
        if (!link) return false;
        window.location.assign(link.href);
        return true;
    };

    document.addEventListener('keydown', event => {
        if (event.defaultPrevented || event.repeat || event.isComposing
            || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey
            || (document.activeElement !== document.body && document.activeElement !== document.documentElement)
            || blocked(event.target)) return;
        const direction = event.key === 'ArrowLeft' ? 'prev' : event.key === 'ArrowRight' ? 'next' : null;
        if (direction && navigate(direction)) event.preventDefault();
    });

    let start = null;
    poolItem.addEventListener('touchstart', event => {
        start = null;
        if (event.touches.length !== 1 || blocked(event.target)) return;
        const touch = event.touches[0];
        start = { id: touch.identifier, x: touch.clientX, y: touch.clientY };
    }, { passive: true });
    poolItem.addEventListener('touchcancel', () => { start = null; }, { passive: true });
    poolItem.addEventListener('touchend', event => {
        const origin = start;
        start = null;
        if (!origin || event.defaultPrevented || event.touches.length || blocked(event.target)) return;
        const touch = [...event.changedTouches].find(candidate => candidate.identifier === origin.id);
        if (!touch) return;
        const dx = touch.clientX - origin.x;
        const dy = touch.clientY - origin.y;
        if (Math.abs(dx) > 100 && Math.abs(dx) > Math.abs(dy)) {
            navigate(dx > 0 ? 'prev' : 'next');
        }
    }, { passive: true });
}
