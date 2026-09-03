<?php

namespace App\Http\Controllers;

use App\Models\OurCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OurCompanyController extends Controller
{
    public function index(Request $request)
    {
        $query = OurCompany::query();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('is_active', $request->status === 'active');
        }

        $companies = $query->latest()->get();

        return view('our_companies.index', compact('companies'));
    }

    // System-generated: C-0001, C-0002, ... (never user-entered)
    private function nextCode(): string
    {
        $last = OurCompany::withTrashed()
            ->where('code', 'like', 'C-%')
            ->pluck('code')
            ->map(fn ($c) => (int) substr($c, 2))
            ->sort()
            ->last();

        return 'C-' . str_pad(($last ?? 0) + 1, 4, '0', STR_PAD_LEFT);
    }

    public function store(Request $request)
    {
        try {
            Log::info('[OurCompany] Store called', ['user_id' => auth()->id()]);

            $validated = $request->validate([
                'name'       => 'required|string|max:255',
                'ntn'        => 'nullable|string|max:50',
                'logo'       => 'nullable|image|max:2048',
                'address'    => 'nullable|string|max:250',
                'contact_no' => 'nullable|string|max:250',
            ]);

            $logoPath = $request->hasFile('logo')
                ? $request->file('logo')->store('companies/logos', 'public')
                : null;

            OurCompany::create([
                'code'       => $this->nextCode(),
                'name'       => $validated['name'],
                'ntn'        => $validated['ntn'] ?? null,
                'logo'       => $logoPath,
                'address'    => $validated['address'] ?? null,
                'contact_no' => $validated['contact_no'] ?? null,
                'is_active'  => true,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            return redirect()->route('companies.index')
                ->with('success', 'Company added successfully.');

        } catch (\Throwable $e) {
            Log::error('[OurCompany] Store error', [
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
        $company = OurCompany::findOrFail($id);
        return response()->json($company);
    }

    public function update(Request $request, $id)
    {
        try {
            $company = OurCompany::findOrFail($id);

            $validated = $request->validate([
                'name'       => 'required|string|max:255',
                'ntn'        => 'nullable|string|max:50',
                'logo'       => 'nullable|image|max:2048',
                'address'    => 'nullable|string|max:250',
                'contact_no' => 'nullable|string|max:250',
            ]);

            $logoPath = $company->logo;
            if ($request->hasFile('logo')) {
                if ($company->logo && Storage::disk('public')->exists($company->logo)) {
                    Storage::disk('public')->delete($company->logo);
                }
                $logoPath = $request->file('logo')->store('companies/logos', 'public');
            }

            // code is system-generated and never changes after creation
            $company->update([
                'name'       => $validated['name'],
                'ntn'        => $validated['ntn'] ?? null,
                'logo'       => $logoPath,
                'address'    => $validated['address'] ?? null,
                'contact_no' => $validated['contact_no'] ?? null,
                'updated_by' => auth()->id(),
            ]);

            Log::info('[OurCompany] Updated', ['id' => $id, 'user' => auth()->id()]);

            return redirect()->route('companies.index')
                ->with('success', 'Company updated successfully.');

        } catch (\Throwable $e) {
            Log::error('[OurCompany] Update error', ['message' => $e->getMessage()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $company = OurCompany::findOrFail($id);
        return response()->json($company);
    }

    public function toggleActive($id)
    {
        $company = OurCompany::findOrFail($id);
        $company->is_active = !$company->is_active;
        $company->updated_by = auth()->id();
        $company->save();

        $status = $company->is_active ? 'activated' : 'deactivated';

        return redirect()->back()->with('success', "Company {$status} successfully.");
    }

    public function destroy($id)
    {
        try {
            $company = OurCompany::findOrFail($id);
            $company->delete();

            return redirect()->route('companies.index')->with('success', 'Company deleted successfully.');

        } catch (\Throwable $e) {
            Log::error('[OurCompany] Destroy error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }
}