<?php

namespace App\Http\Controllers;

use App\Services\OrderPrinting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class OrderPrintController extends Controller
{
    public function prepare(Request $request, int $id, OrderPrinting $printing): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['owner', 'staff'], true), 403);
        $data = $request->validate(['request_key' => 'required|uuid', 'kind' => 'required|in:production,conference', 'paper' => 'required|in:58,80,a4']);
        $data['paper'] = (string) $data['paper'];
        $reference = $printing->prepare($request->user(), $id, $data);

        return redirect()->route('order-prints.show', $reference);
    }

    public function show(Request $request, string $reference, OrderPrinting $printing): Response
    {
        $print = DB::table('order_prints')->where('reference', $reference)->firstOrFail();
        $order = DB::table('orders')->where('id', $print->order_id)->firstOrFail();
        $printing->authorize($request->user(), $order, $print->kind);
        $snapshot = json_decode(Crypt::decryptString($print->payload), true, 512, JSON_THROW_ON_ERROR);
        $currentNotice = $printing->notice($order);

        return response()->view('orders.print', compact('print', 'snapshot', 'currentNotice'))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
