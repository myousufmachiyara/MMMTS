<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Models\CustomerLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomerLocationController extends Controller
{
    public function index(Request $request)
    {
        $query = CustomerLocation::with('customer');

        if ($request->filled('customer_id') && $request->customer_id !== 'all') {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('is_active', $request->status === 'active');
        }

        $locations = $query->latest()->get();

        // Only Chart of Accounts rows flagged as customers populate the dropdown
        $customers = ChartOfAccounts::customers()->orderBy('name')->get();

        return view('customer_locations.index', compact('locations', 'customers'));
    }

    public function store(Request $request)
    {
        try {
            Log::info('[CustomerLocation] Store called', ['user_id' => auth()->id()]);

            $validated = $request->validate([
                'customer_id'   => 'required|exists:chart_of_accounts,id',
                'location_name' => 'required|string|max:255',
            ]);

            CustomerLocation::create([
                'customer_id'   => $validated['customer_id'],
                'location_name' => $validated['location_name'],
                'is_active'     => true,
                'created_by'    => auth()->id(),
                'updated_by'    => auth()->id(),
            ]);

            return redirect()->route('customer-locations.index')
                ->with('success', 'Customer location added successfully.');

        } catch (\Throwable $e) {
            Log::error('[CustomerLocation] Store error', [
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
        $location = CustomerLocation::findOrFail($id);
        return response()->json($location);
    }

    public function update(Request $request, $id)
    {
        try {
            $location = CustomerLocation::findOrFail($id);

            $validated = $request->validate([
                'customer_id'   => 'required|exists:chart_of_accounts,id',
                'location_name' => 'required|string|max:255',
            ]);

            $location->update([
                'customer_id'   => $validated['customer_id'],
                'location_name' => $validated['location_name'],
                'updated_by'    => auth()->id(),
            ]);

            Log::info('[CustomerLocation] Updated', ['id' => $id, 'user' => auth()->id()]);

            return redirect()->route('customer-locations.index')
                ->with('success', 'Customer location updated successfully.');

        } catch (\Throwable $e) {
            Log::error('[CustomerLocation] Update error', ['message' => $e->getMessage()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $location = CustomerLocation::with('customer')->findOrFail($id);
        return response()->json($location);
    }

    public function toggleActive($id)
    {
        $location = CustomerLocation::findOrFail($id);
        $location->is_active = !$location->is_active;
        $location->updated_by = auth()->id();
        $location->save();

        $status = $location->is_active ? 'activated' : 'deactivated';

        return redirect()->back()->with('success', "Customer location {$status} successfully.");
    }

    public function destroy($id)
    {
        try {
            $location = CustomerLocation::findOrFail($id);
            $location->delete();

            return redirect()->route('customer-locations.index')->with('success', 'Customer location deleted successfully.');

        } catch (\Throwable $e) {
            Log::error('[CustomerLocation] Destroy error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }
}
