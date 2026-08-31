<?php

namespace App\Http\Controllers;

use App\Models\VehicleRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class VehicleRouteController extends Controller
{
    public function index(Request $request)
    {
        $query = VehicleRoute::query();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('is_active', $request->status === 'active');
        }

        $routes = $query->latest()->get();

        return view('vehicle_routes.index', compact('routes'));
    }

    public function store(Request $request)
    {
        try {
            Log::info('[VehicleRoute] Store called', ['user_id' => auth()->id()]);

            $validated = $request->validate([
                'code'             => ['required', 'string', 'max:50', Rule::unique('vehicle_routes')->whereNull('deleted_at')],
                'name'             => 'required|string|max:255',
                'dimension'        => 'nullable|string|max:50',
                'union_rent'       => 'nullable|numeric|min:0',
                'day_detention'    => 'nullable|numeric|min:0',
                'night_detention'  => 'nullable|numeric|min:0',
                'labour_charges'   => 'nullable|numeric|min:0',
                'maripur_charges'  => 'nullable|numeric|min:0',
                'h_bay_charges'    => 'nullable|numeric|min:0',
                'extra_northern'   => 'nullable|numeric|min:0',
            ]);

            VehicleRoute::create(array_merge($validated, [
                'is_active'  => true,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]));

            return redirect()->route('vehicle-routes.index')
                ->with('success', 'Vehicle route added successfully.');

        } catch (\Throwable $e) {
            Log::error('[VehicleRoute] Store error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    // Returns JSON for the edit modal AJAX call
    public function edit($id)
    {
        $route = VehicleRoute::findOrFail($id);
        return response()->json($route);
    }

    public function update(Request $request, $id)
    {
        try {
            $route = VehicleRoute::findOrFail($id);

            $validated = $request->validate([
                'code'             => ['required', 'string', 'max:50', Rule::unique('vehicle_routes')->ignore($id)->whereNull('deleted_at')],
                'name'             => 'required|string|max:255',
                'dimension'        => 'nullable|string|max:50',
                'union_rent'       => 'nullable|numeric|min:0',
                'day_detention'    => 'nullable|numeric|min:0',
                'night_detention'  => 'nullable|numeric|min:0',
                'labour_charges'   => 'nullable|numeric|min:0',
                'maripur_charges'  => 'nullable|numeric|min:0',
                'h_bay_charges'    => 'nullable|numeric|min:0',
                'extra_northern'   => 'nullable|numeric|min:0',
            ]);

            $route->update(array_merge($validated, ['updated_by' => auth()->id()]));

            Log::info('[VehicleRoute] Updated', ['id' => $id, 'user' => auth()->id()]);

            return redirect()->route('vehicle-routes.index')
                ->with('success', 'Vehicle route updated successfully.');

        } catch (\Throwable $e) {
            Log::error('[VehicleRoute] Update error', ['message' => $e->getMessage()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $route = VehicleRoute::findOrFail($id);
        return response()->json($route);
    }

    public function toggleActive($id)
    {
        $route = VehicleRoute::findOrFail($id);
        $route->is_active = !$route->is_active;
        $route->updated_by = auth()->id();
        $route->save();

        $status = $route->is_active ? 'activated' : 'deactivated';

        return redirect()->back()->with('success', "Vehicle route {$status} successfully.");
    }

    public function destroy($id)
    {
        try {
            $route = VehicleRoute::findOrFail($id);
            $route->delete();

            return redirect()->route('vehicle-routes.index')->with('success', 'Vehicle route deleted successfully.');

        } catch (\Throwable $e) {
            Log::error('[VehicleRoute] Destroy error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }
}
