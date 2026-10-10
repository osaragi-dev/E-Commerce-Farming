<?php

use App\Filament\Resources\Repacks\RepackResource;
use App\Http\Controllers\Auth\GoogleCallbackController;
use App\Http\Controllers\Auth\GoogleRedirectController;
use App\Http\Controllers\GeneratedInvoiceController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\OrderPrintController;
use App\Http\Controllers\OrderReceiptController;
use App\Http\Controllers\PaymentPrintController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\PurchaseOrderDocxController;
use App\Http\Middleware\EnsureAdminPasswordChanged;
use App\Http\Middleware\EnsureBackOfficeAccess;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Public\AboutUs;
use App\Livewire\Public\Cart;
use App\Livewire\Public\Catalog;
use App\Livewire\Public\Checkout;
use App\Livewire\Public\HelpCenter;
use App\Livewire\Public\Home;
use App\Livewire\Public\MyAccount;
use App\Livewire\Public\OrderConfirmation;
use App\Livewire\Public\OrderHistory;
use App\Livewire\Public\ProductDetail;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/register', Register::class)->name('register');
    Route::get('/login', Login::class)->name('login');
    Route::get('/forgot-password', ForgotPassword::class)->name('password.request');

    Route::get('/auth/google/redirect', GoogleRedirectController::class)->name('auth.google.redirect');
    Route::get('/auth/google/callback', GoogleCallbackController::class)->name('auth.google.callback');
});

Route::post('/logout', LogoutController::class)->name('logout');

Route::get('/admin/repacks', fn (Request $request) => redirect()->to(
    RepackResource::getUrl('index', $request->query(), panel: 'admin'),
))->name('admin.repacks.redirect');

Route::get('/email/verify/{id}/{hash}', function (string $id, string $hash) {
    $customer = Customer::query()->findOrFail($id);

    abort_unless(hash_equals($hash, sha1($customer->getEmailForVerification())), 403);

    if (! $customer->hasVerifiedEmail()) {
        $customer->markEmailAsVerified();
    }

    if (auth('customer')->check()) {
        return redirect()->route('home');
    }

    return redirect()->route('login')->with('status', 'Email successfully verified. Please log in.');
})->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

Route::middleware('auth:customer')->group(function (): void {
    Route::get('/email/verify', fn () => view('layouts.auth', [
        'slot' => view('auth.verify-email'),
    ]))->name('verification.notice');

    Route::post('/email/verification-notification', function (Request $request) {
        $request->user('customer')->sendEmailVerificationNotification();

        return back()->with('status', 'A new verification link has been sent to your email address.');
    })->middleware('throttle:6,1')->name('verification.send');
});

Route::get('/', Home::class)->name('home');
Route::get('/produk', Catalog::class)->name('catalog');
Route::get('/produk/{product:slug}', ProductDetail::class)->name('products.show');
Route::get('/tentang-kami', AboutUs::class)->name('company-profile.show');
Route::get('/help', HelpCenter::class)->name('help');
Route::get('/cart', Cart::class)->middleware(['auth:customer', 'verified'])->name('cart');
Route::get('/checkout', Checkout::class)->middleware(['auth:customer', 'verified'])->name('checkout');
Route::get('/pesanan/{order:order_number}', OrderConfirmation::class)->middleware(['auth:customer', 'verified'])->name('orders.show');
Route::get('/akun', MyAccount::class)->middleware(['auth:customer', 'verified'])->name('account');
Route::get('/akun/pesanan', OrderHistory::class)->middleware(['auth:customer', 'verified'])->name('orders.history');
Route::get('/pesanan/{order:order_number}/invoice', [InvoiceController::class, 'show'])->middleware(['auth:customer', 'verified'])->name('orders.invoice');

Route::middleware([EnsureBackOfficeAccess::class, EnsureAdminPasswordChanged::class])->get('/admin/orders/{order}/receipt', OrderReceiptController::class)
    ->name('admin.orders.receipt');

Route::middleware([EnsureBackOfficeAccess::class, EnsureAdminPasswordChanged::class, 'throttle:30,1'])->group(function (): void {
    Route::get('/admin/invoices/preview/{token}', [GeneratedInvoiceController::class, 'preview'])->whereUuid('token')->name('admin.invoices.preview');
    Route::get('/admin/invoices/{invoice}', [GeneratedInvoiceController::class, 'show'])->name('admin.invoices.show');
    Route::get('/admin/invoices/{invoice}/download/{format}', [GeneratedInvoiceController::class, 'download'])
        ->whereIn('format', ['pdf', 'docx'])->name('admin.invoices.download');
});

Route::middleware(['auth:web', EnsureBackOfficeAccess::class, EnsureAdminPasswordChanged::class, 'throttle:30,1'])
    ->get('/admin/purchase-orders/{record}/docx', PurchaseOrderDocxController::class)
    ->name('admin.purchase-orders.docx');

Route::middleware([EnsureBackOfficeAccess::class, EnsureAdminPasswordChanged::class])->get('/admin/payments/{payment}/receipt', PaymentReceiptController::class)
    ->name('admin.payments.receipt');

Route::middleware([EnsureBackOfficeAccess::class, EnsureAdminPasswordChanged::class, 'throttle:30,1'])->group(function (): void {
    Route::get('/admin/orders/{order}/print-certificate', [OrderPrintController::class, 'certificate'])
        ->name('admin.orders.print-certificate');
    Route::post('/admin/orders/{order}/print', [OrderPrintController::class, 'store'])
        ->name('admin.orders.print');
    Route::post('/admin/orders/{order}/rawbt', [OrderPrintController::class, 'rawbt'])
        ->name('admin.orders.rawbt');
    Route::get('/admin/payments/{payment}/print-certificate', [PaymentPrintController::class, 'certificate'])
        ->name('admin.payments.print-certificate');
    Route::post('/admin/payments/{payment}/print', [PaymentPrintController::class, 'store'])
        ->name('admin.payments.print');
    Route::post('/admin/payments/{payment}/rawbt', [PaymentPrintController::class, 'rawbt'])
        ->name('admin.payments.rawbt');
});
