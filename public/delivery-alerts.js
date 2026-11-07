document.querySelectorAll('[data-delivery-alerts]').forEach(panel => {
    const count = panel.querySelector('[data-delivery-alert-count]');
    const list = panel.querySelector('[data-delivery-alert-list]');
    const message = panel.querySelector('[data-delivery-alert-message]');
    const sound = panel.querySelector('[data-delivery-alert-sound]');
    let timer, running = false, stopped = false, latest = null, audio = null, enabled = false;
    sound.addEventListener('click', async () => {
        if (enabled) {
            enabled = false;
            sound.textContent = 'Ativar som';
            return;
        }
        try {
            const Audio = window.AudioContext || window.webkitAudioContext;
            if (!Audio) throw new Error('unavailable');
            audio ||= new Audio();
            await audio.resume();
            enabled = true;
            sound.textContent = 'Desativar som';
            message.textContent = 'Som ativo nesta página. Mantenha o painel aberto para receber os avisos.';
        } catch {
            message.textContent = 'Som indisponível neste navegador. Os avisos visuais continuam ativos.';
        }
    });
    const beep = () => {
        if (!enabled || !audio || audio.state !== 'running') return;
        try {
            const oscillator = audio.createOscillator(), gain = audio.createGain();
            oscillator.connect(gain);
            gain.connect(audio.destination);
            oscillator.frequency.value = 740;
            gain.gain.setValueAtTime(0.08, audio.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.25);
            oscillator.start();
            oscillator.stop(audio.currentTime + 0.25);
        } catch { /* Visual updates remain available if audio stops. */ }
    };
    const refresh = async () => {
        clearTimeout(timer);
        if (stopped || running || document.hidden) return;
        running = true;
        try {
            const response = await fetch(panel.dataset.url, {headers: {'Accept':'application/json'}, credentials:'same-origin', cache:'no-store'});
            if (stopped) return;
            if ([401,403,419].includes(response.status) || response.redirected) {
                stopped = true;
                enabled = false;
                sound.disabled = true;
                list.replaceChildren();
                count.textContent = 'Indisponível';
                message.textContent = 'Acesso encerrado. Entre novamente para consultar as ocorrências.';
                return;
            }
            if (!response.ok) throw new Error('unavailable');
            const data = await response.json();
            if (latest !== null && data.latest_id > latest && data.open_count > 0) beep();
            latest = Math.max(latest || 0, data.latest_id);
            count.textContent = String(data.open_count);
            list.replaceChildren();
            for (const item of data.items) {
                const li = document.createElement('li'), link = document.createElement('a');
                link.href = item.url;
                link.textContent = `Entrega #${item.ride_id} · ${item.label}`;
                li.append(link);
                list.append(li);
            }
            message.textContent = data.open_count ? 'Ocorrências aguardando solução. Abra uma entrega para conferir.' : 'Nenhuma ocorrência aberta.';
        } catch {
            message.textContent = 'Não foi possível atualizar. Os últimos avisos foram mantidos; tentaremos novamente.';
        } finally {
            running = false;
            if (!stopped && !document.hidden) timer = setTimeout(refresh, 15000);
        }
    };
    document.addEventListener('visibilitychange', () => {
        clearTimeout(timer);
        if (!document.hidden) refresh();
    });
    window.addEventListener('pagehide', () => {
        stopped = true;
        enabled = false;
        clearTimeout(timer);
        if (audio) audio.close().catch(() => {});
    });
    window.addEventListener('pageshow', event => {
        if (!event.persisted) return;
        stopped = false;
        audio = null;
        sound.textContent = 'Ativar som';
        refresh();
    });
    refresh();
});
