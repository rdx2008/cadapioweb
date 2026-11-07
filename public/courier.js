document.querySelectorAll('[data-courier-workspace]').forEach(root => {
    const board = root.querySelector('[data-courier-board]');
    const connection = root.querySelector('[data-courier-connection]');
    const gps = root.querySelector('[data-courier-gps]');
    const alert = root.querySelector('[data-courier-alert]');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let renew = root.dataset.renew === '1', stopped = false, polling = false, locating = false;
    let signature = '', audio, serverOffset = Number(root.dataset.serverTime) - Date.now();
    let knownOffers = new Set(Array.from(board.querySelectorAll('[data-offer-id]')).map(item => item.dataset.offerId));
    const controllers = new Set();
    const request = async (url, options = {}) => {
        const controller = new AbortController();
        controllers.add(controller);
        const timer = setTimeout(() => controller.abort(), 10000);
        try {
            return await fetch(url, {credentials: 'same-origin', cache: 'no-store', ...options,
                headers: {Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...options.headers}, signal: controller.signal});
        } finally {clearTimeout(timer); controllers.delete(controller);}
    };
    const stop = () => {
        stopped = true; renew = false;
        controllers.forEach(controller => controller.abort());
        board.replaceChildren();
        connection.textContent = 'Seu acesso expirou ou foi alterado. Entre novamente.';
        gps.textContent = 'Atualização de localização interrompida.';
    };
    const applyPresence = state => {
        renew = state.renew;
        root.querySelector('[data-courier-presence]').textContent = state.label;
        root.querySelector('[data-courier-queue]').textContent = state.queue_position
            ? `${state.queue_position}º de ${state.queue_total} na fila` : 'Sem posição na fila';
        if (!renew) gps.textContent = 'Renovação automática suspensa. Confirme a disponibilidade para voltar.';
    };
    const countdown = () => {
        board.querySelectorAll('[data-offer-expires]').forEach(item => {
            const seconds = Math.max(0, Math.ceil((Number(item.dataset.offerExpires) - Date.now() - serverOffset) / 1000));
            item.textContent = seconds ? `Responda em ${seconds}s` : 'Oferta expirada';
            item.closest('.card').querySelectorAll('button').forEach(button => {button.disabled = seconds === 0;});
        });
    };
    root.querySelector('[data-courier-sound]').addEventListener('click', async event => {
        const button = event.currentTarget;
        try {
            const Audio = window.AudioContext || window.webkitAudioContext;
            audio = audio || new Audio(); await audio.resume();
            button.textContent = 'Aviso sonoro ativado';
        } catch {alert.textContent = 'O navegador não liberou o som. As ofertas continuam aparecendo no painel.';}
    });
    const poll = async () => {
        if (stopped) return;
        if (document.hidden || polling) {setTimeout(poll, 5000); return;}
        polling = true;
        try {
            const response = await request(root.dataset.liveUrl);
            if ([401,403,404,419].includes(response.status) || response.redirected) {stop(); return;}
            if (!response.ok) throw new Error();
            const data = await response.json();
            if (stopped || document.hidden) return;
            serverOffset = data.server_time - Date.now();
            applyPresence(data.presence);
            const editing = board.contains(document.activeElement) || board.querySelector('details[open]');
            if (!editing && signature !== data.signature) {
                board.innerHTML = data.html; signature = data.signature;
                const offers = new Set(Array.from(board.querySelectorAll('[data-offer-id]')).map(item => item.dataset.offerId));
                if (Array.from(offers).some(id => !knownOffers.has(id))) {
                    alert.textContent = 'Nova oferta de corrida disponível. Confira o prazo para responder.';
                    if (audio?.state === 'running') {
                        const oscillator = audio.createOscillator(), gain = audio.createGain();
                        oscillator.connect(gain); gain.connect(audio.destination); gain.gain.value = 0.08;
                        oscillator.frequency.value = 740; oscillator.start(); oscillator.stop(audio.currentTime + 0.25);
                    }
                }
                knownOffers = offers;
                if (!offers.size) alert.textContent = '';
            }
            countdown();
            connection.textContent = editing ? 'Conectado. Conclua a ação para atualizar a lista.' : 'Ofertas e fila atualizadas às ' + new Date().toLocaleTimeString('pt-BR');
        } catch {if (!stopped) connection.textContent = 'Sem conexão com a lista. Tentaremos novamente; o prazo das ofertas continua valendo.';}
        finally {polling = false; if (!stopped) setTimeout(poll, 5000);}
    };
    const heartbeat = async () => {
        if (stopped || document.hidden || !renew || locating) return;
        if (!navigator.geolocation) {gps.textContent = 'Localização indisponível neste navegador. A presença expirará sem novas atualizações.'; return;}
        locating = true;
        gps.textContent = 'Atualizando localização…';
        navigator.geolocation.getCurrentPosition(async position => {
            try {
                if (stopped || document.hidden || !renew) return;
                if (position.coords.accuracy > 50 || Date.now() - position.timestamp > 60000) {
                    gps.textContent = 'Localização imprecisa ou antiga. Tente em um local aberto; sem atualização válida, a presença expira.'; return;
                }
                const response = await request(root.dataset.heartbeatUrl, {method:'POST', headers:{'Content-Type':'application/json'},
                    body:JSON.stringify({latitude:position.coords.latitude, longitude:position.coords.longitude, accuracy:position.coords.accuracy, captured_at:(position.timestamp + serverOffset) / 1000})});
                if ([401,403,404,419].includes(response.status) || response.redirected) {stop(); return;}
                const data = await response.json();
                if (stopped) return;
                if (data.presence) applyPresence(data.presence);
                gps.textContent = response.ok ? 'Localização atualizada às ' + new Date().toLocaleTimeString('pt-BR')
                    : response.status === 409 ? 'Presença suspensa. Confirme a disponibilidade para voltar.' : 'Localização não aceita. Sem atualização válida, a presença expira.';
            } catch {if (!stopped) gps.textContent = 'Falha ao renovar a presença. Confira a conexão; sem atualização válida, ela expira.';}
            finally {locating = false;}
        }, error => {
            locating = false;
            gps.textContent = error.code === 1 ? 'Permissão de localização negada. Libere no navegador; sem atualização válida, a presença expira.'
                : 'Não foi possível obter localização. Tentaremos novamente com a página visível.';
        }, {enableHighAccuracy:true, maximumAge:0, timeout:15000});
    };
    root.querySelector('[data-courier-offline]')?.addEventListener('submit', () => {renew = false;});
    root.addEventListener('submit', () => {stopped = true; controllers.forEach(controller => controller.abort());});
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) gps.textContent = 'Página em segundo plano. A renovação está pausada e a presença pode expirar.';
    });
    window.addEventListener('pagehide', () => {stopped = true; controllers.forEach(controller => controller.abort());});
    window.addEventListener('pageshow', event => {if (event.persisted) window.location.reload();});
    setInterval(() => {if (!stopped && !document.hidden) countdown();}, 1000);
    setInterval(heartbeat, 30000);
    countdown(); setTimeout(poll, 5000); heartbeat();
});
