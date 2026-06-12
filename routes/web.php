<?php

use App\Http\Controllers\Analytics\DashboardController;
use App\Http\Controllers\Analytics\HeatmapController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/prehlad');

Route::controller(DashboardController::class)->group(function () {
    Route::get('/prehlad', 'overview')->name('analytics.overview');
    Route::get('/rfm', 'rfm')->name('analytics.rfm');
    Route::get('/casove-vzorce', 'time')->name('analytics.time');
    Route::get('/produkty', 'products')->name('analytics.products');
    Route::get('/nakupny-proces', 'process')->name('analytics.process');
    Route::get('/zakaznici', 'customers')->name('analytics.customers');
    Route::get('/clarity', 'clarity')->name('analytics.clarity');
    Route::get('/zhrnutie', 'summary')->name('analytics.summary');
});

Route::get('/heatmapy', [HeatmapController::class, 'index'])->name('analytics.heatmaps');
Route::post('/heatmapy', [HeatmapController::class, 'store'])->name('analytics.heatmaps.store');
Route::delete('/heatmapy/{heatmap}', [HeatmapController::class, 'destroy'])->name('analytics.heatmaps.destroy');
