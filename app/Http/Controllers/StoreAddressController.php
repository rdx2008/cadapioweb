<?php

namespace App\Http\Controllers;

use App\Services\DeliveryCoverage;
use App\Services\PostcodeLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StoreAddressController extends Controller
{
    public function postcode(Request $request, PostcodeLookup $lookup): JsonResponse
    {
        $data = $request->validate(['postcode' => 'required|string|regex:/^[0-9]{5}-?[0-9]{3}$/']);

        return response()->json(['address' => $lookup->find($data['postcode'])])->header('Cache-Control', 'no-store');
    }

    public function locate(Request $request, DeliveryCoverage $coverage): JsonResponse
    {
        $data = $request->validate(['street' => 'required|string|max:100', 'number' => 'required|string|max:20', 'neighborhood' => 'required|string|max:60', 'city' => 'required|string|max:60', 'state' => 'required|string|regex:/^[A-Za-z]{2}$/', 'postcode' => 'required|string|regex:/^[0-9]{5}-?[0-9]{3}$/']);
        $data['state'] = strtoupper($data['state']);
        $data['postcode'] = str_replace('-', '', $data['postcode']);
        $address = $data['street'].', '.$data['number'].' - '.$data['neighborhood'].', '.$data['city'].' - '.$data['state'].', '.$data['postcode'];
        if (mb_strlen($address) > 240) {
            throw ValidationException::withMessages(['street' => 'Use um endereço de até 240 caracteres. Coloque referências no campo apropriado após cadastrar a loja.']);
        }

        return response()->json(['choices' => $coverage->search($address, $data)])->header('Cache-Control', 'no-store');
    }
}
