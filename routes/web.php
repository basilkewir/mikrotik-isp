<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\ConnectedDeviceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalDashboardController;
use App\Http\Controllers\Portal\PortalTicketController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware(['web'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Routers
    Route::resource('routers', RouterController::class)->only(['index', 'store', 'destroy']);
    Route::put('routers/{router}', [RouterController::class, 'update'])->name('routers.update');
    Route::get('routers/{router}/profiles', [RouterController::class, 'profiles'])->name('routers.profiles');
    Route::get('routers/{router}/firewall-policy', [RouterController::class, 'firewallPolicy'])->name('routers.firewall-policy');
    Route::post('routers/{router}/firewall-policy', [RouterController::class, 'installFirewallPolicy'])->name('routers.firewall-policy.install');
    Route::delete('routers/{router}/firewall-policy', [RouterController::class, 'removeFirewallPolicy'])->name('routers.firewall-policy.remove');
    Route::post('routers/{router}/test', [RouterController::class, 'testConnection'])->name('routers.test');

    // Plans
    Route::resource('plans', PlanController::class)->only(['index', 'store', 'destroy']);
    Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');

    // Clients
    Route::resource('clients', ClientController::class)->only(['index', 'store', 'show']);
    Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
    Route::post('clients/{client}/devices', [ClientController::class, 'storeDevice'])->name('clients.devices.store');
    Route::put('devices/{device}', [ClientController::class, 'updateDevice'])->name('devices.update');
    Route::delete('devices/{device}', [ClientController::class, 'destroyDevice'])->name('devices.destroy');
    Route::post('devices/{device}/disable', [ClientController::class, 'disableDevice'])->name('devices.disable');
    Route::post('devices/{device}/enable', [ClientController::class, 'enableDevice'])->name('devices.enable');

    // Live connected devices (ARP / DHCP / Hotspot / PPPoE merged)
    Route::get('connected-devices', [ConnectedDeviceController::class, 'index'])->name('connected-devices.index');

    // Wallet (manual adjustments — Monetbil top-ups come via the payment gateway)
    Route::post('clients/{client}/wallet/topup', [WalletController::class, 'topup'])->name('wallet.topup');
    Route::post('clients/{client}/wallet/debit', [WalletController::class, 'debit'])->name('wallet.debit');
    Route::post('clients/{client}/wallet/redeem', [WalletController::class, 'redeem'])->name('wallet.redeem');

    // Vouchers
    Route::get('vouchers', [VoucherController::class, 'index'])->name('vouchers.index');
    Route::post('vouchers', [VoucherController::class, 'store'])->name('vouchers.store');
    Route::post('vouchers/{voucher}/void', [VoucherController::class, 'void'])->name('vouchers.void');

    // Device inventory (full typology, incl. infrastructure devices)
    Route::get('inventory', [DeviceController::class, 'index'])->name('inventory.index');
    Route::post('inventory', [DeviceController::class, 'store'])->name('inventory.store');
    Route::put('inventory/{device}', [DeviceController::class, 'update'])->name('inventory.update');
    Route::delete('inventory/{device}', [DeviceController::class, 'destroy'])->name('inventory.destroy');

    // Payments (Monetbil Mobile Money)
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::get('payments/{payment}/return', [PaymentController::class, 'returnTo'])->name('payments.return');

    // Invoices
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::post('invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->name('invoices.pay');
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');

    // Support tickets (admin)
    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::post('tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::post('tickets/{ticket}/reply', [TicketController::class, 'reply'])->name('tickets.reply');
    Route::put('tickets/{ticket}/status', [TicketController::class, 'updateStatus'])->name('tickets.status');

    // Settings
    Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingController::class, 'update'])->name('settings.update');

    // Subscriptions
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('subscriptions', [SubscriptionController::class, 'store'])->name('subscriptions.store');
    Route::put('subscriptions/{subscription}', [SubscriptionController::class, 'update'])->name('subscriptions.update');
    Route::delete('subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])->name('subscriptions.destroy');
    Route::post('routers/{router}/sessions/kick', [SubscriptionController::class, 'kickLiveSession'])->name('routers.sessions.kick');
    Route::post('subscriptions/{subscription}/disable', [SubscriptionController::class, 'disable'])->name('subscriptions.disable');
    Route::post('subscriptions/{subscription}/enable', [SubscriptionController::class, 'enable'])->name('subscriptions.enable');
    Route::post('subscriptions/{subscription}/kick', [SubscriptionController::class, 'kick'])->name('subscriptions.kick');
});

// Monetbil server-to-server notification — outside the web group (no session/CSRF);
// authenticity is enforced via the Monetbil signature + checkPayment re-verification.
Route::post('payments/monetbil/notify', [PaymentController::class, 'notify'])->name('payments.notify');

/*
|--------------------------------------------------------------------------
| Subscriber self-care portal
|--------------------------------------------------------------------------
| Login = client_code + portal_password (separate "client" guard on clients table).
*/
Route::middleware('guest:client')->group(function () {
    Route::get('/portal/login', [PortalAuthController::class, 'showLogin'])->name('portal.login');
    Route::post('/portal/login', [PortalAuthController::class, 'login'])->name('portal.login.attempt');
});

Route::middleware('auth:client')->group(function () {
    Route::get('/portal', [PortalDashboardController::class, 'index'])->name('portal.dashboard');
    Route::post('/portal/logout', [PortalAuthController::class, 'logout'])->name('portal.logout');

    // Subscriber support tickets
    Route::get('/portal/tickets', [PortalTicketController::class, 'index'])->name('portal.tickets');
    Route::post('/portal/tickets', [PortalTicketController::class, 'store'])->name('portal.tickets.store');
    Route::post('/portal/tickets/{ticket}/reply', [PortalTicketController::class, 'reply'])->name('portal.tickets.reply');
});
