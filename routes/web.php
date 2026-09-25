<?php

use App\Livewire\Account\Show as Account;
use App\Livewire\Admin\Home;
use App\Livewire\Admin\Metrics;
use App\Livewire\Admin\Status;
use App\Livewire\Apps\Index as AppsIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Roles\Index as RolesIndex;
use App\Livewire\Tokens\Index as TokensIndex;
use App\Livewire\Users\Index as UsersIndex;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.home'));

Route::get('/login', Login::class)->name('login')->middleware('guest');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout')->middleware('auth');

Route::middleware('auth')->prefix('admin')->group(function () {
    Route::get('/account', Account::class)->name('account');
    Route::get('/', Home::class)->name('admin.home');
    Route::get('/users', UsersIndex::class)->name('admin.users')->middleware('can:users.read');
    Route::get('/roles', RolesIndex::class)->name('admin.roles')->middleware('can:roles.read');
    Route::get('/tokens', TokensIndex::class)->name('admin.tokens');
    Route::get('/apps', AppsIndex::class)->name('admin.apps')->middleware('can:apps.read');
    Route::get('/metrics', Metrics::class)->name('admin.metrics')->middleware('can:metrics.read');
    Route::get('/status', Status::class)->name('admin.status')->middleware('can:status.read');
});
