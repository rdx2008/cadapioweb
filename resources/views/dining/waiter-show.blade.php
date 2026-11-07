@extends('pos.layout')
@section('content')
<div class="cash-page">@include('dining.sound')
<p data-dining-connection role="status">Atualiza automaticamente com a página aberta.</p>
<div data-dining-live data-call-ids="{{ $calls->pluck('id')->values()->toJson() }}" data-audience="{{ $audience }}" data-url="{{ route('dining.tab-live',['id'=>$tab->id,'page'=>request('page',1)]) }}">@include('dining.waiter-content')</div></div>
@endsection
