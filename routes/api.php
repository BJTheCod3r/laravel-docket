<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Http\Controllers\ReportController;
use BJTheCod3r\Docket\Http\Controllers\TransitionReportController;
use Illuminate\Support\Facades\Route;

Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
Route::post('reports', [ReportController::class, 'store'])->name('reports.store');
Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
Route::delete('reports/{report}', [ReportController::class, 'destroy'])->name('reports.destroy');
Route::post('reports/{report}/transition', TransitionReportController::class)->name('reports.transition');
