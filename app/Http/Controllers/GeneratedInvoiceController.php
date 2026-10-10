<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceDocumentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class GeneratedInvoiceController extends Controller
{
    public function preview(string $token, InvoiceDocumentService $documents): Response
    {
        Gate::authorize('create', Invoice::class);
        $preview = Cache::get('invoice-preview:'.$token);
        abort_unless(is_array($preview) && $preview['user_id'] === auth('web')->id(), 404);

        return response($documents->pdf($preview['payload']), 200, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function show(Invoice $invoice, InvoiceDocumentService $documents): Response
    {
        Gate::authorize('view', $invoice);

        return response($documents->pdf($invoice->document_payload), 200, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function download(Invoice $invoice, string $format, InvoiceDocumentService $documents): Response|BinaryFileResponse
    {
        Gate::authorize('export', $invoice);
        abort_unless(in_array($format, ['pdf', 'docx'], true), 404);
        $payload = $invoice->document_payload;
        $filename = $documents->filename($payload).'.'.$format;
        if ($format === 'docx') {
            return response()->download($documents->docx($payload), $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Cache-Control' => 'private, no-store',
            ])->deleteFileAfterSend();
        }

        return response($documents->pdf($payload), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
