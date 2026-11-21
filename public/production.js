(() => {
    const clocks = new WeakMap();
    const refresh = () => {
        if (document.hidden) return;
        document.querySelectorAll('[data-production-timer]').forEach(node => {
            const d = node.dataset, local = performance.now();
            if (!clocks.has(node)) clocks.set(node, {server:Number(d.productionNow),local});
            const clock = clocks.get(node), now = clock.server + Math.max(0,local-clock.local)/1000;
            const start = Number(d.productionStart), finish = Number(d.productionFinish), target = Number(d.productionTarget), deadline = Number(d.productionDeadline);
            if (d.productionState === 'preparing' && start) {
                const minutes = Math.floor(Math.max(0,now-start)/60), late = deadline > 0 && now > deadline;
                node.textContent = `Em preparo há ${minutes} min` + (target ? ` · Meta ${target} min` : '') + (late ? ` · Atrasado ${Math.ceil((now-deadline)/60)} min` : '');
                node.classList.toggle('is-late',late);
            } else if (['ready','completed'].includes(d.productionState) && start && finish) {
                node.classList.toggle('is-late',false);
                node.textContent = `Preparo concluído em ${Math.floor(Math.max(0,finish-start)/60)} min`;
            } else if (d.productionState === 'accepted' && Number(d.productionAccepted)) {
                const prepareAfter = Number(d.productionPrepareAfter)||0;
                node.textContent = now < prepareAfter ? 'Agendado: aguarde o horário de preparo' : `Na fila há ${Math.floor(Math.max(0,now-Math.max(Number(d.productionAccepted),prepareAfter))/60)} min`;
            }
        });
    };
    refresh();
    let timer = setInterval(refresh,30000);
    document.addEventListener('visibilitychange',refresh);
    window.addEventListener('pagehide',()=>clearInterval(timer));
    window.addEventListener('pageshow',event=>{if(event.persisted){clearInterval(timer);timer=setInterval(refresh,30000);refresh();}});
})();
