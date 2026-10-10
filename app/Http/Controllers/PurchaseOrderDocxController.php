<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderApprovalStatus;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PurchaseOrderDocxController extends Controller
{
    public function __invoke(Request $request, PurchaseOrder $record, PurchaseOrderDocumentService $documents): BinaryFileResponse
    {
        abort_unless($request->user()?->can('View:PurchaseOrder'), 403);
        abort_unless($record->approval_status === PurchaseOrderApprovalStatus::Approved, 404);

        $path = $documents->docx($record);
        $filename = 'purchase-order-'.(Str::slug($record->po_number ?? 'draft')).'.docx';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }
}
