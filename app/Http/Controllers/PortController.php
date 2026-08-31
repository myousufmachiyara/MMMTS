<?php

namespace App\Http\Controllers;

use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PortController extends Controller
{
    public function index(Request $request)
    {
        $query = Port::query();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('is_active', $request->status === 'active');
        }

        $ports = $query->latest()->get();

        return view('ports.index', compact('ports'));
    }

    public function store(Request $request)
    {
        try {
            Log::info('[Port] Store called', ['user_id' => auth()->id()]);

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255', Rule::unique('ports')->whereNull('deleted_at')],
            ]);

            Port::create([
                'name'       => $validated['name'],
                'is_active'  => true,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            return redirect()->route('ports.index')
                ->with('success', 'Port added successfully.');

        } catch (\Throwable $e) {
            Log::error('[Port] Store error', [
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
        $port = Port::findOrFail($id);
        return response()->json($port);
    }

    public function update(Request $request, $id)
    {
        try {
            $port = Port::findOrFail($id);

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255', Rule::unique('ports')->ignore($id)->whereNull('deleted_at')],
            ]);

            $port->update([
                'name'       => $validated['name'],
                'updated_by' => auth()->id(),
            ]);

            Log::info('[Port] Updated', ['id' => $id, 'user' => auth()->id()]);

            return redirect()->route('ports.index')
                ->with('success', 'Port updated successfully.');

        } catch (\Throwable $e) {
            Log::error('[Port] Update error', ['message' => $e->getMessage()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $port = Port::findOrFail($id);
        return response()->json($port);
    }

    public function toggleActive($id)
    {
        $port = Port::findOrFail($id);
        $port->is_active = !$port->is_active;
        $port->updated_by = auth()->id();
        $port->save();

        $status = $port->is_active ? 'activated' : 'deactivated';

        return redirect()->back()->with('success', "Port {$status} successfully.");
    }

    public function destroy($id)
    {
        try {
            $port = Port::findOrFail($id);
            $port->delete();

            return redirect()->route('ports.index')->with('success', 'Port deleted successfully.');

        } catch (\Throwable $e) {
            Log::error('[Port] Destroy error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }
}
