<?php

use App\Http\Controllers\AccountingFilesController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\ExtractionController;
use App\Http\Controllers\SessionController;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');
Route::redirect('/admin/workspace', '/admin');
Route::get('/login', [SessionController::class, 'show'])->name('login');
Route::post('/login', [SessionController::class, 'login'])->middleware('throttle:shared-login');
Route::post('/logout', [SessionController::class, 'logout'])->middleware('auth');
foreach (['portal', 'director', 'accountant'] as $legacyPanel) {
    Route::redirect('/'.$legacyPanel.'/{any?}', '/admin')->where('any', '.*');
}
Route::get('/account', fn () => view('accounting.account'))->middleware('auth');
Route::middleware(['auth', 'active', 'module:accounting', 'throttle:staff-write'])->prefix('crm')->group(function () {
    Route::post('invoice-documents', [AccountingFilesController::class, 'uploadInvoices']);
    Route::get('invoice-documents/{document}/pdf', [AccountingFilesController::class, 'invoice'])->name('crm.invoice-document');
    Route::get('expenses/{expense}/file', [AccountingFilesController::class, 'expense'])->name('crm.expense-file');
    Route::get('master-template', [CrmController::class, 'masterTemplate']);
    Route::post('import/preview', [CrmController::class, 'importPreview']);
    Route::post('import/commit', [CrmController::class, 'importCommit']);
    Route::get('import/errors', [CrmController::class, 'importErrors']);
    Route::get('word/template', [CrmController::class, 'wordTemplate']);
    Route::post('word/preview', [CrmController::class, 'wordPreview']);
    Route::post('word/create', [CrmController::class, 'wordCreate']);
    Route::get('invoices/{invoice}/pdf', [CrmController::class, 'pdf']);
    Route::get('notes/{note}/pdf', [CrmController::class, 'notePdf']);
    Route::post('documents', [CrmController::class, 'documentUpload']);
    Route::get('documents/{document}/download', [CrmController::class, 'download'])->middleware('signed')->name('crm.document');
    Route::get('payments/export', [CrmController::class, 'export']);
    Route::get('reports/gstr1', [CrmController::class, 'gstr1']);
    Route::get('reports/ageing', [CrmController::class, 'ageing']);
});

Route::middleware(['auth', 'active', 'module:accounting', 'throttle:staff-write'])->group(function () {
    Route::post('/crm/extractions', [ExtractionController::class, 'upload'])->name('crm.extractions.upload');
    Route::post('/crm/extractions/{id}/retry', [ExtractionController::class, 'retry'])->name('crm.extractions.retry');
    Route::post('/crm/extractions/{id}/confirm', [ExtractionController::class, 'confirm']);
});
Route::get('/setup', function () {
    abort_unless(app()->environment('local') && in_array(request()->ip(), ['127.0.0.1', '::1']) && ! User::role('Accountant')->exists(), 404);

    return view('setup');
});
Route::post('/setup', function (Request $r) {
    abort_unless(app()->environment('local') && in_array($r->ip(), ['127.0.0.1', '::1']) && ! User::role('Accountant')->exists(), 404);
    $data = $r->validate(['name' => 'required|max:100', 'email' => 'required|email|unique:users,email', 'password' => 'required|confirmed|min:12']);
    $u = User::create($data);
    $u->assignRole('Accountant');
    auth()->login($u);
    $r->session()->regenerate();

    return redirect('/admin/workspace');
})->middleware('throttle:10,1');

Route::post('/crm/branding', function (Request $r) {
    abort_unless($r->user()->is_active && $r->user()->allowed('accounting.settings.manage'), 403);
    $r->validate(['logo' => 'nullable|image|mimes:png,jpg,jpeg|max:2048', 'signature' => 'nullable|image|mimes:png,jpg,jpeg|max:2048']);
    foreach (['logo', 'signature'] as $key) {
        if ($r->hasFile($key)) {
            $path = $r->file($key)->store('Branding', config('crm.disk'));
            Setting::updateOrCreate(['key' => $key.'_path'], ['value' => $path]);
        }
    }

    return back()->with('success', 'Invoice branding saved.');
})->middleware('auth');
