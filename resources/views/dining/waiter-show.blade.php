@extends('pos.layout')
@section('content')
<div class="cash-page"><p data-dining-connection role="status">Atualiza automaticamente com a página aberta.</p>
<div data-dining-live data-audience="{{ $audience }}" data-url="{{ route('dining.tab-live',['id'=>$tab->id,'page'=>request('page',1)]) }}">@include('dining.waiter-content')</div></div>
@endsection
