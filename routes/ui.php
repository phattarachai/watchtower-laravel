<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Phattarachai\WatchtowerLaravel\Server\Http\Controllers\AlertRuleController;
use Phattarachai\WatchtowerLaravel\Server\Http\Controllers\BulkIssueController;
use Phattarachai\WatchtowerLaravel\Server\Http\Controllers\IssueStatusController;
use Phattarachai\WatchtowerLaravel\Server\Http\Controllers\UiController;
use Phattarachai\WatchtowerLaravel\Server\Http\Controllers\UiProjectController;

Route::get('/', [UiController::class, 'issues'])->name('issues');
Route::get('/alerts', [UiController::class, 'alerts'])->name('alerts');
Route::get('/settings', [UiController::class, 'settings'])->name('settings');
Route::get('/issues/{group}', [UiController::class, 'issue'])->name('issue');

Route::patch('/issues/status', [BulkIssueController::class, 'status'])->name('issues.bulk-status');
Route::delete('/issues', [BulkIssueController::class, 'destroy'])->name('issues.bulk-destroy');
Route::patch('/issues/{group}/status', IssueStatusController::class)->name('issues.status');

Route::post('/alerts', [AlertRuleController::class, 'store'])->name('alerts.store');
Route::patch('/alerts/{rule}', [AlertRuleController::class, 'update'])->name('alerts.update');
Route::delete('/alerts/{rule}', [AlertRuleController::class, 'destroy'])->name('alerts.destroy');
Route::post('/alerts/{rule}/test', [AlertRuleController::class, 'test'])->name('alerts.test');

Route::post('/projects', [UiProjectController::class, 'store'])->name('projects.store');
Route::patch('/projects/{project}', [UiProjectController::class, 'update'])->name('projects.update');
Route::post('/projects/{project}/rotate-key', [UiProjectController::class, 'rotateKey'])->name('projects.rotate');
