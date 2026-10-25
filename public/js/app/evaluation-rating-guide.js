(() => {
    const tables = [...document.querySelectorAll('[data-rating-guide]')];
    if (!tables.length) return;
    const guide = document.createElement('div');
    guide.className = 'evaluation-rating-guide';
    guide.setAttribute('aria-hidden', 'true');
    guide.hidden = true;
    document.body.append(guide);
    let active = null;
    let frame = null;
    let dirty = true;

    function update() {
        frame = null;
        const table = tables.find(table => {
            const head = table.tHead?.getBoundingClientRect();
            return head && head.top <= 44 && table.getBoundingClientRect().bottom > 0;
        });
        if (!table) { guide.hidden = true; active = null; return; }
        const scroller = table.parentElement;
        const bounds = scroller.getBoundingClientRect();
        const left = Math.max(0, bounds.left);
        guide.style.left = `${left}px`;
        guide.style.width = `${Math.max(0, Math.min(innerWidth, bounds.right) - left)}px`;
        if (active !== table || dirty) {
            const title = document.createElement('div');
            title.className = 'evaluation-rating-guide-title';
            title.textContent = table.closest('[data-rating-section]').querySelector('[data-rating-section-title]').textContent;
            const copy = document.createElement('table');
            copy.className = table.className;
            copy.style.width = `${table.getBoundingClientRect().width}px`;
            const columns = document.createElement('colgroup');
            for (const cell of table.tHead.rows[0].cells) {
                const column = document.createElement('col');
                column.style.width = `${cell.getBoundingClientRect().width}px`;
                columns.append(column);
            }
            copy.append(columns, table.tHead.cloneNode(true));
            guide.replaceChildren(title, copy);
            active = table;
            dirty = false;
        }
        guide.lastElementChild.style.transform = `translateX(${-scroller.scrollLeft + bounds.left - left}px)`;
        guide.hidden = false;
        guide.style.transform = `translateY(${Math.min(0, table.getBoundingClientRect().bottom - guide.offsetHeight)}px)`;
        document.documentElement.style.setProperty('--rating-guide-height', `${guide.offsetHeight + 16}px`);
    }
    function schedule() { if (frame === null) frame = requestAnimationFrame(update); }
    document.addEventListener('scroll', schedule, {capture: true, passive: true});
    window.addEventListener('resize', () => { dirty = true; schedule(); }, {passive: true});
    const observer = new ResizeObserver(() => { dirty = true; schedule(); });
    tables.forEach(table => observer.observe(table));
    document.fonts?.ready.then(() => { dirty = true; schedule(); });
    schedule();
})();
