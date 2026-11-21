<?php

namespace App\Http\Controllers;

use App\Services\CourierPresence;
use App\Services\DispatchService;
use App\Services\RideProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CourierController extends Controller
{
    public function __construct(private CourierPresence $presence) {}

    private function driver(): object
    {
        return $this->presence->driver(auth()->id());
    }

    public function index(): View
    {
        $driver = $this->driver();

        return view('courier', $this->presence->board($driver) + ['driver' => $driver, 'presence' => $this->presence->state($driver), 'stores' => $this->presence->stores()]);
    }

    public function live(): JsonResponse
    {
        $driver = $this->driver();
        $html = view('partials.courier-board', $this->presence->board($driver))->render();

        return response()->json(['html' => $html, 'signature' => hash('sha256', $html), 'presence' => $this->presence->state($driver), 'server_time' => now()->timestamp * 1000])->header('Cache-Control', 'no-store');
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'accuracy' => 'required|numeric|min:0|max:50', 'captured_at' => ['required', 'numeric', 'min:'.now()->subSeconds(60)->timestamp, 'max:'.now()->addSeconds(5)->timestamp]]);
        $accepted = $this->presence->heartbeat($this->driver(), $data);

        return response()->json(['presence' => $this->presence->state($this->driver()), 'message' => $accepted ? 'Localização atualizada.' : 'Disponibilidade suspensa. Confirme para voltar à fila.'], $accepted ? 200 : 409)->header('Cache-Control', 'no-store');
    }

    public function presence(Request $request): RedirectResponse
    {
        $data = $request->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'accuracy' => 'required|numeric|min:0|max:50', 'store_id' => 'nullable|integer']);
        $this->presence->confirm($this->driver(), $data);

        return back()->with('success', 'Presença confirmada. Mantenha esta página visível para renovar a localização automaticamente.');
    }

    public function offline(): RedirectResponse
    {
        $this->presence->offline($this->driver());

        return back();
    }

    public function accept(int $id, DispatchService $dispatch): RedirectResponse
    {
        $dispatch->accept($id, $this->driver()->id);

        return back()->with('success', 'Corrida atribuída a você.');
    }

    public function decline(int $id, DispatchService $dispatch): RedirectResponse
    {
        $dispatch->decline($id, $this->driver()->id);

        return back();
    }

    public function progress(Request $request, int $id, RideProgress $progress): RedirectResponse
    {
        $data = $request->validate(RideProgress::RULES);
        $progress->advance($this->driver(), $id, $data, $request->file('photo'));

        return back()->with('success', 'Etapa registrada.');
    }
}
