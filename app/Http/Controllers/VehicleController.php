<?php

namespace App\Http\Controllers;

use App\Models\OurCompany;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    public function index(Request $request)
    {
        $query = Vehicle::with('companies');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('is_active', $request->status === 'active');
        }

        if ($request->filled('company_id') && $request->company_id !== 'all') {
            $query->whereHas('companies', fn ($q) => $q->where('our_companies.id', $request->company_id));
        }

        $vehicles = $query->latest()->get();
        $companies = OurCompany::where('is_active', true)->orderBy('name')->get();

        return view('vehicles.index', compact('vehicles', 'companies'));
    }

    public function store(Request $request)
    {
        try {
            Log::info('[Vehicle] Store called', ['user_id' => auth()->id()]);

            $validated = $request->validate([
                'company_ids'   => 'nullable|array',
                'company_ids.*' => 'exists:our_companies,id',
                'code'          => ['required', 'string', 'max:50', Rule::unique('vehicles')->whereNull('deleted_at')],
                'name'          => 'required|string|max:255',
                'vehicle_no'    => ['nullable', 'string', 'max:50', Rule::unique('vehicles')->whereNull('deleted_at')],
                'remarks'       => 'nullable|string|max:500',
            ]);

            $vehicle = Vehicle::create([
                'code'       => $validated['code'],
                'name'       => $validated['name'],
                'vehicle_no' => $validated['vehicle_no'] ?? null,
                'remarks'    => $validated['remarks'] ?? null,
                'is_active'  => true,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            $vehicle->companies()->sync($validated['company_ids'] ?? []);

            return redirect()->route('vehicles.index')
                ->with('success', 'Vehicle added successfully.');

        } catch (\Throwable $e) {
            Log::error('[Vehicle] Store error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    // Returns JSON for the edit modal AJAX call
    public function edit($id)
    {
        $vehicle = Vehicle::with('companies:id')->findOrFail($id);
        $data = $vehicle->toArray();
        $data['company_ids'] = $vehicle->companies->pluck('id');
        return response()->json($data);
    }

    public function update(Request $request, $id)
    {
        try {
            $vehicle = Vehicle::findOrFail($id);

            $validated = $request->validate([
                'company_ids'   => 'nullable|array',
                'company_ids.*' => 'exists:our_companies,id',
                'code'          => ['required', 'string', 'max:50', Rule::unique('vehicles')->ignore($id)->whereNull('deleted_at')],
                'name'          => 'required|string|max:255',
                'vehicle_no'    => ['nullable', 'string', 'max:50', Rule::unique('vehicles')->ignore($id)->whereNull('deleted_at')],
                'remarks'       => 'nullable|string|max:500',
            ]);

            $vehicle->update([
                'code'       => $validated['code'],
                'name'       => $validated['name'],
                'vehicle_no' => $validated['vehicle_no'] ?? null,
                'remarks'    => $validated['remarks'] ?? null,
                'updated_by' => auth()->id(),
            ]);

            $vehicle->companies()->sync($validated['company_ids'] ?? []);

            Log::info('[Vehicle] Updated', ['id' => $id, 'user' => auth()->id()]);

            return redirect()->route('vehicles.index')
                ->with('success', 'Vehicle updated successfully.');

        } catch (\Throwable $e) {
            Log::error('[Vehicle] Update error', ['message' => $e->getMessage()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $vehicle = Vehicle::with('companies')->findOrFail($id);
        return response()->json($vehicle);
    }

    public function toggleActive($id)
    {
        $vehicle = Vehicle::findOrFail($id);
        $vehicle->is_active = !$vehicle->is_active;
        $vehicle->updated_by = auth()->id();
        $vehicle->save();

        $status = $vehicle->is_active ? 'activated' : 'deactivated';

        return redirect()->back()->with('success', "Vehicle {$status} successfully.");
    }

    public function destroy($id)
    {
        try {
            $vehicle = Vehicle::findOrFail($id);
            $vehicle->delete();

            return redirect()->route('vehicles.index')->with('success', 'Vehicle deleted successfully.');

        } catch (\Throwable $e) {
            Log::error('[Vehicle] Destroy error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }
}
