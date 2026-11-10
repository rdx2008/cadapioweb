@php($locked=$account&&($account->purchase_id||($account->has_settlements??false)))
@php($accountValue=fn($name,$fallback=null)=>($locked&&in_array($name,['direction','counterparty','amount']))?$fallback:(old('form_context')==='account'?old($name,$fallback):$fallback))
<input type="hidden" name="form_context" value="account">
@if($locked)<input type="hidden" name="direction" value="{{ $account->direction }}">@endif
<label>Tipo<select name="direction" @disabled($locked)><option value="payable" @selected($accountValue('direction',$account?->direction)==='payable')>Conta a pagar</option><option value="receivable" @selected($accountValue('direction',$account?->direction)==='receivable')>Conta a receber</option></select></label>
<label>Descrição<input name="description" value="{{ $accountValue('description',$account?->description) }}" required maxlength="160" placeholder="Ex.: aluguel de novembro"></label>
<label>Pessoa ou empresa<input name="counterparty" value="{{ $accountValue('counterparty',$account?->counterparty) }}" required maxlength="120" @readonly($locked) placeholder="Fornecedor ou cliente"></label>
<label>Categoria<select name="category">@foreach(App\Services\StoreFinance::CATEGORIES as $key=>$label)<option value="{{ $key }}" @selected($accountValue('category',$account?->category??'other')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Vencimento<input type="date" name="due_on" value="{{ $accountValue('due_on',$account?->due_on??$today->toDateString()) }}" required min="1970-01-01" max="2100-12-31"></label>
<label>Valor total (R$)<input name="amount" value="{{ $accountValue('amount',$account?number_format($account->amount_cents/100,2,',',''):'') }}" inputmode="decimal" @readonly($locked) required maxlength="16" placeholder="Ex.: 150,00"></label>
<label class="full">Observações<textarea name="notes" maxlength="500">{{ $accountValue('notes',$account?->notes) }}</textarea></label>
