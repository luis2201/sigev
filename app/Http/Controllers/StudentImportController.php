<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportStudentsRequest;
use App\Models\AcademicPeriod;
use App\Models\StudentImport;
use App\Services\InstitutionalWorkbook;
use App\Services\StudentImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudentImportController extends Controller
{
    public function index(): View
    {
        return view('academic.imports.index', ['batches' => StudentImport::with(['period', 'requester'])->withCount('rows')->orderByDesc('id')->paginate(20)]);
    }

    public function create(): View
    {
        return view('academic.imports.create', ['periods' => AcademicPeriod::whereIn('status', ['planned', 'active'])->orderByDesc('starts_on')->get()]);
    }

    public function preview(ImportStudentsRequest $request, StudentImportService $service): RedirectResponse
    {
        $batch = $service->preview($request->user(), AcademicPeriod::findOrFail($request->validated('academic_period_id')), $request->file('file'));

        return redirect()->route('academic.imports.show', $batch);
    }

    public function show(StudentImport $batch, StudentImportService $service): View
    {
        $batch->load(['period', 'requester', 'file']);
        $preview = [];
        if ($batch->status === 'pending') {
            $preview = $service->inspect(Storage::disk('local')->path($batch->file->storage_key), $batch->period);
        }
        $totals = $batch->rows()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return view('academic.imports.show', ['batch' => $batch, 'preview' => $preview, 'totals' => $totals, 'rows' => $batch->rows()->with(['student.user', 'participation.career'])->orderBy('row_number')->paginate(30)]);
    }

    public function confirm(Request $request, StudentImport $batch, StudentImportService $service): RedirectResponse
    {
        $request->validate(['confirmation' => ['required', 'accepted']]);
        $service->confirm($request->user(), $batch);

        return redirect()->route('academic.imports.show', $batch)->with('success', 'Solicitud atendida. Revise el resultado de cada fila.');
    }

    public function cancel(Request $request, StudentImport $batch, StudentImportService $service): RedirectResponse
    {
        $service->cancel($request->user(), $batch);

        return redirect()->route('academic.imports.show', $batch)->with('success', 'Vista previa cancelada sin crear estudiantes.');
    }

    public function template(InstitutionalWorkbook $workbook): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'sigev-template-');
        $workbook->write($path);

        return response()->download($path, 'plantilla-estudiantes-itsup.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }
}
