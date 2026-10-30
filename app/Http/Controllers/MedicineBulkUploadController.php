<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\MedicineImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class MedicineBulkUploadController extends Controller
{
    public function template()
    {
        abort_if(Gate::none(['medicine_add', 'admin_medicine_add']), Response::HTTP_FORBIDDEN, '403 Forbidden');

        return response(MedicineImportService::templateCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="products_template.csv"',
        ]);
    }

    /**
     * Pharmacy panel: import into the logged-in pharmacy.
     */
    public function pharmacyImport(Request $request, MedicineImportService $importer)
    {
        abort_if(Gate::denies('medicine_add'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $pharmacy = Pharmacy::where('user_id', auth()->id())->firstOrFail();

        return $this->import($request, $importer, $pharmacy->id);
    }

    /**
     * Admin panel: import into a chosen pharmacy.
     */
    public function adminImport(Request $request, MedicineImportService $importer, $pharmacy_id)
    {
        abort_if(Gate::denies('admin_medicine_add'), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $pharmacy = Pharmacy::findOrFail($pharmacy_id);

        return $this->import($request, $importer, $pharmacy->id);
    }

    private function import(Request $request, MedicineImportService $importer, int $pharmacyId)
    {
        $request->validate(
            ['file' => 'bail|required|file|max:2048|extensions:csv,txt|mimes:csv,txt'],
            ['file.extensions' => __('Please upload a .csv file (in Excel use "Save As > CSV").'), 'file.mimes' => __('Please upload a .csv file (in Excel use "Save As > CSV").')]
        );
        $result = $importer->import($request->file('file'), $pharmacyId);

        return redirect()->back()->withStatus(__('Bulk upload complete: :created new product(s) added, :updated existing product(s) updated.', $result));
    }
}
