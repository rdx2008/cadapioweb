document.querySelectorAll('[data-store-settings]').forEach(root => {
    const sections = [...root.querySelectorAll('[data-settings-section]')];
    const links = [...root.querySelectorAll('[data-settings-link]')];
    const dirty = new Set();
    let scheduled = false;
    const mark = id => links.forEach(link => {
        if (link.hash === '#' + id) link.setAttribute('aria-current', 'location');
        else link.removeAttribute('aria-current');
    });
    const update = () => {
        scheduled = false;
        const offset = window.innerWidth <= 1100 ? 100 : 40;
        const current = sections.filter(section => section.getBoundingClientRect().top <= offset).pop() || sections[0];
        if (current) mark(current.id);
    };
    window.addEventListener('scroll', () => {
        if (!scheduled) { scheduled = true; requestAnimationFrame(update); }
    }, {passive:true});
    window.addEventListener('resize', update);
    links.forEach(link => link.addEventListener('click', () => mark(link.hash.slice(1))));
    root.querySelectorAll('form').forEach(form => {
        const section = form.closest('[data-settings-section]');
        const badge = links.find(link => link.hash === '#' + section.id)?.querySelector('[data-settings-dirty]');
        const status = form.querySelector('[data-settings-save-status]');
        const changed = () => {
            dirty.add(form);
            if (badge) badge.hidden = false;
            if (status) status.textContent = 'Alterações ainda não salvas.';
        };
        form.addEventListener('input', changed);
        form.addEventListener('change', changed);
        form.addEventListener('submit', event => {
            if (event.defaultPrevented) return;
            if ([...dirty].some(other => other !== form) && !window.confirm('Há alterações não salvas em outra seção. Salvar esta seção e sair sem salvar as demais?')) {
                event.preventDefault();
                return;
            }
            dirty.clear();
        });
    });
    window.addEventListener('beforeunload', event => {
        if (!dirty.size) return;
        event.preventDefault();
        event.returnValue = '';
    });
    let errors = [];
    try { errors = JSON.parse(root.dataset.settingsErrors || '[]'); } catch { /* Server errors remain visible above the form. */ }
    const invalid = [...root.querySelectorAll('input,select,textarea')].filter(field => {
        if (root.dataset.settingsOrigin && field.closest('[data-settings-section]')?.id !== root.dataset.settingsOrigin) return false;
        const key = field.name.replace(/\[([^\]]*)\]/g, '.$1');
        return errors.some(error => error === key || key.startsWith(error + '.'));
    });
    invalid.forEach(field => {
        field.setAttribute('aria-invalid','true');
        let parent = field.parentElement;
        while (parent && parent !== root) { if (parent.tagName === 'DETAILS') parent.open = true; parent = parent.parentElement; }
    });
    if (invalid[0]) {
        const section = invalid[0].closest('[data-settings-section]');
        section.scrollIntoView({block:'start'});
        mark(section.id);
    } else if (errors.length && root.dataset.settingsOrigin) {
        const section = sections.find(section => section.id === root.dataset.settingsOrigin);
        if (section) { section.scrollIntoView({block:'start'}); mark(section.id); }
    } else update();
});
