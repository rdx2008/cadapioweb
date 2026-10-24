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
