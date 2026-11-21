document.querySelectorAll('[data-store-address]').forEach(section => {
    const form = section.closest('form');
    const parts = Object.fromEntries([...section.querySelectorAll('[data-location-part]')].map(input => [input.dataset.locationPart, input]));
    const entry = section.querySelector('[data-location-entry]');
    const details = section.querySelector('[data-location-details]');
    const message = section.querySelector('[data-location-message]');
    const confirmation = section.querySelector('[data-location-confirmation]');
    const choices = section.querySelector('[data-location-choices]');
    const address = section.querySelector('[name=address]');
    const latitude = section.querySelector('[name=latitude]');
    const longitude = section.querySelector('[name=longitude]');
    const postcodeButton = section.querySelector('[data-location-postcode]');
    const locateButton = section.querySelector('[data-location-locate]');
    let existing = section.dataset.existing === '1', ready = Boolean(parts.street.value), revision = 0;
    let postcodeController, locationController, postcodeTimer, locationTimer, locationPromise, locatingKey;
    const values = () => Object.fromEntries(Object.entries(parts).map(([key, input]) => [key, input.value.trim()]));
    const format = data => `${data.street}, ${data.number} - ${data.neighborhood}, ${data.city} - ${data.state.toUpperCase()}, ${data.postcode.replace(/\D/g, '')}`;
    const hasPoint = () => address.value && latitude.value !== '' && longitude.value !== '' && latitude.validity.valid && longitude.validity.valid && Number.isFinite(Number(latitude.value)) && Number.isFinite(Number(longitude.value));
    const update = () => {
        entry.hidden = existing;
        details.hidden = !ready;
        Object.entries(parts).forEach(([key, input]) => {
            input.disabled = existing || (key !== 'postcode' && !ready);
            input.required = !input.disabled;
        });
    };
    const synchronizeAddress = () => { if (!existing) address.value = ready ? format(values()) : ''; };
    const invalidate = () => {
        revision++;
        locationController?.abort();
        clearTimeout(locationTimer);
        latitude.value = longitude.value = '';
        choices.replaceChildren();
        confirmation.textContent = 'Informe o número e confirme a localização da loja.';
    };
    const request = async (url, body, controller) => {
        const timeout = setTimeout(() => controller.abort(), 16000);
        try {
            const response = await fetch(url, {method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify(body)});
            if (response.redirected || [401,403,419].includes(response.status)) throw new Error('Seu acesso expirou ou foi alterado. Entre novamente para continuar.');
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || 'Não foi possível consultar o endereço. Tente novamente.');
            return data;
        } finally { clearTimeout(timeout); }
    };
    const showError = error => { message.textContent = error.name === 'AbortError' ? 'A consulta demorou. Tente novamente.' : error.message; };
    const locate = () => {
        if (existing || !ready || !Object.values(parts).every(input => input.value.trim() && input.validity.valid)) {
            message.textContent = 'Complete o CEP, a rua, o número, o bairro, a cidade e o estado.';
            return Promise.resolve(false);
        }
        const data = values(), key = JSON.stringify(data);
        if (locationPromise && locatingKey === key) return locationPromise;
        invalidate();
        synchronizeAddress();
        if (address.value.length > 240) { message.textContent = 'Use um endereço de até 240 caracteres.'; return Promise.resolve(false); }
        const version = revision, controller = new AbortController();
        locationController = controller; locatingKey = key;
        locateButton.disabled = true; message.textContent = 'Localizando sua loja…';
        const select = place => {
            if (version !== revision || JSON.stringify(values()) !== key) return;
            if (!Number.isFinite(Number(place.latitude)) || !Number.isFinite(Number(place.longitude)) || Math.abs(Number(place.latitude)) > 90 || Math.abs(Number(place.longitude)) > 180) throw new Error('A localização retornada é inválida. Consulte novamente.');
            latitude.value = place.latitude; longitude.value = place.longitude;
            synchronizeAddress(); choices.replaceChildren();
            confirmation.textContent = 'Localização preenchida automaticamente. Confira o endereço antes de salvar.';
            message.textContent = `Loja localizada: ${place.label}`;
        };
        locationPromise = (async () => {
            try {
                const result = await request(section.dataset.locateUrl, data, controller);
                if (version !== revision || JSON.stringify(values()) !== key) return false;
                if (!Array.isArray(result.choices) || !result.choices.length) throw new Error('Não encontramos o número da loja. Confira o endereço e tente novamente.');
                if (result.choices.length === 1) { select(result.choices[0]); return true; }
                message.textContent = 'Encontramos mais de um endereço. Escolha onde sua loja fica:';
                result.choices.forEach(place => {
                    const button = document.createElement('button'); button.type = 'button'; button.className = 'secondary address-choice'; button.textContent = place.label;
                    button.addEventListener('click', () => { try { select(place); } catch (error) { showError(error); } }); choices.append(button);
                });
                return false;
            } catch (error) { if (version === revision) showError(error); return false; }
            finally { if (locationController === controller) { locationPromise = null; locateButton.disabled = false; } }
        })();
        return locationPromise;
    };
    const lookupPostcode = async () => {
        clearTimeout(postcodeTimer);
        if (!parts.postcode.reportValidity()) return;
        postcodeController?.abort();
        invalidate(); ready = false; update(); synchronizeAddress();
        const version = revision, postcode = parts.postcode.value, controller = new AbortController();
        postcodeController = controller; postcodeButton.disabled = true; message.textContent = 'Buscando endereço pelo CEP…';
        try {
            const data = await request(section.dataset.postcodeUrl, {postcode}, controller);
            if (version !== revision || parts.postcode.value !== postcode) return;
            ['street','neighborhood','city','state'].forEach(key => { parts[key].value = data.address[key] || ''; });
            ready = true; update(); synchronizeAddress();
            message.textContent = data.address.street && data.address.neighborhood ? 'Endereço encontrado. Confira os dados e informe o número da loja.' : 'Este CEP não informa todos os dados. Complete os campos abaixo.';
            (Object.values(parts).find(input => !input.value) || parts.number).focus();
        } catch (error) { if (version === revision) showError(error); }
        finally { if (postcodeController === controller) postcodeButton.disabled = false; }
    };
    parts.postcode.addEventListener('input', () => {
        postcodeController?.abort(); clearTimeout(postcodeTimer); invalidate(); ready = false;
        Object.entries(parts).forEach(([key,input]) => { if (key !== 'postcode') input.value = ''; });
        message.textContent = ''; update(); synchronizeAddress();
        if (/^\d{5}-?\d{3}$/.test(parts.postcode.value)) postcodeTimer = setTimeout(lookupPostcode, 350);
    });
    Object.entries(parts).filter(([key]) => key !== 'postcode').forEach(([,input]) => {
        input.addEventListener('input', () => {
            invalidate(); synchronizeAddress(); message.textContent = '';
            if (Object.values(parts).every(field => field.value.trim() && field.validity.valid)) locationTimer = setTimeout(locate, 1000);
        });
        input.addEventListener('blur', () => { if (!hasPoint() && Object.values(parts).every(field => field.value.trim() && field.validity.valid)) { clearTimeout(locationTimer); locate(); } });
    });
    Object.values(parts).forEach(input => input.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); input === parts.postcode ? lookupPostcode() : locate(); } }));
    postcodeButton.addEventListener('click', lookupPostcode);
    locateButton.addEventListener('click', locate);
    section.querySelector('[data-location-manual]').addEventListener('click', () => {
        postcodeController?.abort(); clearTimeout(postcodeTimer); invalidate(); ready = true; update(); synchronizeAddress(); parts.street.focus();
        message.textContent = 'Preencha o endereço. Localizaremos a loja após informar o número.';
    });
    section.querySelector('[data-location-change]')?.addEventListener('click', () => { existing = false; invalidate(); update(); parts.postcode.focus(); });
    [latitude,longitude].forEach(input => input.addEventListener('input', () => { revision++; locationController?.abort(); clearTimeout(locationTimer); choices.replaceChildren(); confirmation.textContent = 'Localização ajustada manualmente. Confira os dados antes de salvar.'; }));
    form.addEventListener('submit', async event => {
        if (hasPoint()) return;
        event.preventDefault();
        if (!ready && !existing) { await lookupPostcode(); return; }
        const found = await locate();
        if (found && hasPoint()) form.requestSubmit(event.submitter);
    });
    update();
});

document.querySelectorAll('[data-store-create-form]').forEach(form => {
    const name = form.querySelector('[name=name]'), slug = form.querySelector('[name=slug]');
    let custom = Boolean(slug.value);
    slug.addEventListener('input', () => { custom = Boolean(slug.value); });
    name.addEventListener('input', () => { if (!custom) slug.value = name.value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'').slice(0,100); });
});
