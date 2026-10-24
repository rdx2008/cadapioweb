document.querySelectorAll("[data-location]").forEach((button) =>
    button.addEventListener("click", () => {
        const form = button.closest("form"),
            message = form.querySelector("[data-location-message]");
        if (!navigator.geolocation) {
            message.textContent = "Localização indisponível neste navegador.";
            return;
        }
        button.disabled = true;
        message.textContent = "Obtendo localização…";
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                for (const [name, value] of Object.entries({
                    latitude: pos.coords.latitude,
                    longitude: pos.coords.longitude,
                    accuracy: pos.coords.accuracy,
                }))
                    form.querySelector("[name=" + name + "]").value = value;
                form.requestSubmit();
            },
            () => {
                message.textContent =
                    "Autorize a localização e tente novamente.";
                button.disabled = false;
            },
            { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 },
        );
    }),
);

// Web sales
document.querySelectorAll("[data-cart]").forEach((form) => {
    const money = (value) =>
        new Intl.NumberFormat("pt-BR", {
            style: "currency",
            currency: "BRL",
        }).format(value / 100);
    const update = () => {
        let total = 0;
        form.querySelectorAll("[data-cart-product]").forEach((product) => {
            let unit = Number(product.dataset.price);
            product
                .querySelectorAll("[data-option-price]:checked")
                .forEach((option) => {
                    unit += Number(option.dataset.optionPrice);
                });
            total +=
                unit *
                Math.max(
                    0,
                    Number(product.querySelector("[data-quantity]").value) || 0,
                );
        });
        const delivery = form.querySelector("[data-mode]").value === "delivery";
        const zone = form.querySelector("[data-zone]");
        zone.required = delivery;
        zone.disabled = !delivery;
        form.querySelector("[data-delivery-address]").required = delivery;
        if (delivery) {
            total += Number(zone.selectedOptions[0]?.dataset.fee || 0);
        }
        form.querySelector("[data-cart-total]").textContent = money(total);
    };
    form.addEventListener("input", update);
    form.addEventListener("change", update);
    update();
});
async function readLiveJson(url) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
        const response = await fetch(url, {
            headers: { Accept: "application/json" },
            credentials: "same-origin",
            cache: "no-store",
            signal: controller.signal,
        });
        if (
            !response.ok ||
            !response.headers.get("content-type")?.includes("application/json")
        ) {
            throw new Error(
                response.status === 401 || response.redirected
                    ? "Sua sessão expirou. Entre novamente."
                    : "Atualização indisponível. Tentaremos novamente.",
            );
        }
        return await response.json();
    } finally {
        clearTimeout(timeout);
    }
}
document.querySelectorAll("[data-order-board]").forEach((board) => {
    const status = document.querySelector("[data-board-connection]");
    const alert = document.querySelector("[data-order-alert]");
    const sound = document.querySelector("[data-enable-sound]");
    let latest = Number(board.dataset.latestId),
        signature = "",
        timer,
        running = false,
        audio;
    sound?.addEventListener("click", async () => {
        try {
            const Audio = window.AudioContext || window.webkitAudioContext;
            audio = audio || new Audio();
            await audio.resume();
            sound.textContent = "Som ativado";
        } catch {
            sound.textContent = "Som indisponível neste navegador";
        }
    });
    const beep = () => {
        if (!audio || audio.state !== "running") {
            return;
        }
        const tone = audio.createOscillator(),
            gain = audio.createGain();
        tone.frequency.value = 740;
        gain.gain.value = 0.04;
        tone.connect(gain);
        gain.connect(audio.destination);
        tone.start();
        tone.stop(audio.currentTime + 0.25);
    };
    const refresh = async () => {
        clearTimeout(timer);
        if (running) {
            return;
        }
        if (document.hidden) {
            timer = setTimeout(refresh, 5000);
            return;
        }
        running = true;
        try {
            const data = await readLiveJson(board.dataset.url);
            if (data.latest_id > latest) {
                beep();
                alert.textContent =
                    "Novo pedido recebido. Confira a fila e os filtros.";
            }
            latest = Math.max(latest, data.latest_id);
            const editing =
                board.contains(document.activeElement) ||
                board.querySelector("details[open]");
            if (!editing && signature !== data.signature) {
                board.innerHTML = data.html;
                signature = data.signature;
            }
            status.textContent = editing
                ? "Conectado · aguardando terminar a edição para atualizar a fila."
                : "Atualizado às " + new Date().toLocaleTimeString("pt-BR");
        } catch (error) {
            status.textContent =
                error.name === "AbortError"
                    ? "Conexão lenta. Tentaremos novamente."
                    : error.message;
        } finally {
            running = false;
            timer = setTimeout(refresh, 5000);
        }
    };
    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) {
            refresh();
        }
    });
    timer = setTimeout(refresh, 5000);
});
document.querySelectorAll("[data-tracking-url]").forEach((panel) => {
    const labels = {
        pending: "Aguardando aceite",
        accepted: "Aceito",
        preparing: "Em preparo",
        ready: "Pronto",
        completed: "Concluído",
        cancelled: "Cancelado",
    };
    const rideLabels = {
        searching: "Buscando entregador",
        offered: "Oferecida na fila",
        broadcast: "Buscando na região",
        unassigned: "Aguardando entregador",
        assigned: "Entregador a caminho da loja",
        collected: "Pedido em entrega",
        completed: "Entregue",
        cancelled: "Corrida cancelada",
    };
    let timer,
        running = false;
    const refresh = async () => {
        clearTimeout(timer);
        if (running) {
            return;
        }
        if (document.hidden) {
            timer = setTimeout(refresh, 5000);
            return;
        }
        running = true;
        try {
            const data = await readLiveJson(panel.dataset.trackingUrl);
            panel.querySelector("[data-tracking-status]").textContent =
                labels[data.status] || data.status;
            panel.querySelector("[data-tracking-payment]").textContent =
                data.payment_status === "paid"
                    ? "Recebido"
                    : "Na entrega ou retirada";
            panel.querySelector("[data-tracking-ride]").textContent =
                rideLabels[data.ride_status] ||
                "Aguardando despacho ou retirada";
            panel.querySelector("[data-cancel-reason]").textContent =
                data.cancellation_reason || "";
            panel.querySelector("[data-tracking-connection]").textContent =
                "Atualizado às " + new Date().toLocaleTimeString("pt-BR");
        } catch {
            panel.querySelector("[data-tracking-connection]").textContent =
                "Não foi possível atualizar agora. Tentaremos novamente.";
        } finally {
            running = false;
            timer = setTimeout(refresh, 5000);
        }
    };
    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) {
            refresh();
        }
    });
    timer = setTimeout(refresh, 5000);
});
