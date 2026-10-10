<?php

use App\Enums\PurchaseOrderApprovalStatus;
use App\Enums\PurchaseOrderCloseApprovalStatus;
use App\Enums\PurchaseOrderPaymentStatus;
use App\Enums\PurchaseOrderReceivedStatus;
use App\Enums\RecordStatus;
use App\Filament\Resources\PurchaseInvoices\Schemas\PurchaseInvoiceForm;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderForm;
use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('vendor options remain available after selecting a purchase request with items', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($actor);
    $request = PurchaseRequest::factory()->create();
    PurchaseRequestItem::factory()->create(['purchase_request_id' => $request->id]);
    $vendor = Vendor::factory()->create();

    $page = Livewire::test(CreatePurchaseOrder::class)
        ->set('data.purchase_request_id', $request->id)
        ->assertSuccessful();

    expect($page->instance()->getSchema('form')->getComponentByStatePath('vendor_id')->getOptions())
        ->toHaveKey($vendor->id, $vendor->name);
});

test('purchase_orders use the baseline lifecycle', function (): void {
    $record = PurchaseOrder::factory()->create();

    expect($record->id)->toBeString()
        ->and($record->record_status)->toBe(RecordStatus::Saved)
        ->and($record->markAsDraft())->toBeTrue()
        ->and($record->fresh()->record_status)->toBe(RecordStatus::Draft);
});

test('a purchase request can be split across purchase orders up to its remaining quantity', function (): void {
    $purchaseRequest = PurchaseRequest::factory()->create([
        'record_status' => RecordStatus::Saved,
        'approval_status' => 'approved',
    ]);
    $requestItem = PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'qty_order' => 10,
    ]);

    $firstOrder = PurchaseOrder::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'warehouse_id' => $purchaseRequest->warehouse_id,
    ]);
    $firstOrder->items()->create([
        'purchase_request_item_id' => $requestItem->id,
        'item_id' => $requestItem->item_id,
        'qty_requested' => 10,
        'qty_order' => 4,
        'unit_code' => 'gram',
        'unit_price' => 1000,
        'total_price' => 4000,
        'sort_order' => 0,
    ]);

    $remaining = PurchaseOrderForm::candidateRows($purchaseRequest->id);

    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]['qty_requested'])->toBe('10.000')
        ->and($remaining[0]['remaining_quantity'])->toBe('6.000')
        ->and($remaining[0]['qty_order'])->toBe('6.000');

    $secondOrder = PurchaseOrder::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'warehouse_id' => $purchaseRequest->warehouse_id,
    ]);
    $secondOrder->items()->create([
        'purchase_request_item_id' => $requestItem->id,
        'item_id' => $requestItem->item_id,
        'qty_requested' => 10,
        'qty_order' => 6,
        'unit_code' => 'gram',
        'unit_price' => 1000,
        'total_price' => 6000,
        'sort_order' => 0,
    ]);

    expect(PurchaseOrderForm::candidateRows($purchaseRequest->id))->toBe([]);
});

test('closing an unpaid unreceived purchase order marks it not received closed', function (): void {
    $purchaseOrder = PurchaseOrder::factory()->create([
        'approval_status' => PurchaseOrderApprovalStatus::Approved,
        'payment_basis' => 'goods_receipt',
        'total_amount' => 10000,
    ]);
    $purchaseOrder->closeRequests()->create([
        'approval_status' => PurchaseOrderCloseApprovalStatus::Approved,
        'approval_level' => 0,
    ]);

    $purchaseOrder->refresh();

    expect($purchaseOrder->closing_status)->toBe('closed')
        ->and($purchaseOrder->received_status)->toBe(PurchaseOrderReceivedStatus::NotReceivedClosed)
        ->and($purchaseOrder->payment_status)->toBe(PurchaseOrderPaymentStatus::Unpaid)
        ->and($purchaseOrder->payment_total)->toBe('0.00');
});

test('purchase invoice quantity is limited to approved goods receipt accepted quantity', function (): void {
    $purchaseRequest = PurchaseRequest::factory()->create();
    $requestItem = PurchaseRequestItem::factory()->create(['purchase_request_id' => $purchaseRequest->id]);
    $purchaseOrder = PurchaseOrder::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'warehouse_id' => $purchaseRequest->warehouse_id,
    ]);
    $orderItem = $purchaseOrder->items()->create([
        'purchase_request_item_id' => $requestItem->id,
        'item_id' => $requestItem->item_id,
        'qty_requested' => 10,
        'qty_order' => 10,
        'unit_code' => 'gram',
        'unit_price' => 1000,
        'total_price' => 10000,
        'sort_order' => 0,
    ]);
    $receipt = GoodsReceipt::factory()->create(['purchase_order_id' => $purchaseOrder->id]);
    $receipt->items()->create([
        'purchase_order_item_id' => $orderItem->id,
        'quantity_received' => 7,
        'accepted_quantity' => 5,
    ]);

    $rows = PurchaseInvoiceForm::candidateRows($purchaseOrder->fresh('items.item.unit'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['po_quantity'])->toBe('10.000')
        ->and($rows[0]['grn_quantity'])->toBe('5.000')
        ->and($rows[0]['invoice_quantity'])->toBe('5.000');

    $invoice = PurchaseInvoice::factory()->create(['purchase_order_id' => $purchaseOrder->id]);
    $invoice->items()->create([
        'purchase_order_item_id' => $orderItem->id,
        'invoice_quantity' => 3,
        'final_unit_price' => 1000,
        'line_total' => 3000,
    ]);

    expect(PurchaseInvoiceForm::candidateRows($purchaseOrder->fresh('items.item.unit'))[0]['invoice_quantity'])->toBe('2.000');
});
