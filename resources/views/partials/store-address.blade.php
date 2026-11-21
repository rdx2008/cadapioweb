@php($existingLocation=isset($addressStore)&&!old('location_postcode'))
<section class="full store-address" data-store-address data-existing="{{ $existingLocation?'1':'0' }}" data-postcode-url="{{ route('stores.address.postcode') }}" data-locate-url="{{ route('stores.address.locate') }}">
<h3>Onde fica a loja?</h3><p class="muted">Informe o CEP e o número. Vamos preencher o endereço e localizar sua loja para calcular as entregas.</p>
@if(isset($addressStore))<button type="button" class="secondary" data-location-change>Alterar endereço da loja</button>@endif
<div data-location-entry @if($existingLocation) hidden @endif>
<div class="fields"><label>CEP da loja<input name="location_postcode" data-location-part="postcode" value="{{ old('location_postcode') }}" inputmode="numeric" autocomplete="postal-code" maxlength="9" pattern="[0-9]{5}-?[0-9]{3}" placeholder="00000-000"><small>Ao completar o CEP, buscamos o endereço.</small></label><div class="actions"><button type="button" class="secondary" data-location-postcode>Buscar CEP</button><button type="button" class="secondary" data-location-manual>Preencher endereço manualmente</button></div></div>
<div data-location-details hidden><div class="fields">
<label class="full">Rua / avenida<input name="location_street" data-location-part="street" value="{{ old('location_street') }}" autocomplete="address-line1" maxlength="100"></label>
<label>Número da loja<input name="location_number" data-location-part="number" value="{{ old('location_number') }}" autocomplete="off" maxlength="20" placeholder="Ex.: 72"></label>
<label>Bairro<input name="location_neighborhood" data-location-part="neighborhood" value="{{ old('location_neighborhood') }}" maxlength="60"></label>
<label>Cidade<input name="location_city" data-location-part="city" value="{{ old('location_city') }}" autocomplete="address-level2" maxlength="60"></label>
<label>Estado (UF)<input name="location_state" data-location-part="state" value="{{ old('location_state') }}" autocomplete="address-level1" maxlength="2" pattern="[A-Za-z]{2}" placeholder="Ex.: PR"></label>
</div><button type="button" class="secondary" data-location-locate>Localizar minha loja</button></div>
</div>
<p data-location-message role="status" aria-live="polite"></p><div class="actions" data-location-choices></div>
<label>Endereço da loja<input name="address" data-location-address value="{{ old('address',$addressStore->address??'') }}" maxlength="240" readonly><small data-location-confirmation>{{ $existingLocation?'Localização cadastrada. Altere somente se a loja mudou de endereço.':'A localização será preenchida automaticamente após informar o número.' }}</small></label>
<details data-location-advanced><summary>Ajuste avançado da localização</summary><p class="muted">Normalmente você não precisa preencher estes campos. Use somente para corrigir um ponto conhecido.</p><div class="fields"><label>Latitude<input name="latitude" type="number" step="any" min="-90" max="90" value="{{ old('latitude',$addressStore->latitude??'') }}"></label><label>Longitude<input name="longitude" type="number" step="any" min="-180" max="180" value="{{ old('longitude',$addressStore->longitude??'') }}"></label></div></details>
<small>Powered by <a href="https://www.geoapify.com/" target="_blank" rel="noopener">Geoapify</a> · <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">© OpenStreetMap contributors</a></small>
<noscript><p>Ative o JavaScript do navegador para buscar o endereço e localizar a loja.</p></noscript>
</section>
