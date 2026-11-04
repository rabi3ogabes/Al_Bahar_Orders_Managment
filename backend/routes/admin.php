<?php

// Admin dashboard module routes (prefix /admin, name admin., middleware auth + staff).

use App\Livewire\Admin;
use Illuminate\Support\Facades\Route;

// ── Organization ──────────────────────────────────────────────────────────────
Route::get('branches', Admin\Branches\BranchIndex::class)->middleware('permission:branches.view')->name('branches.index');
Route::get('branches/create', Admin\Branches\BranchEditor::class)->middleware('permission:branches.create')->name('branches.create');
Route::get('branches/{branch}', Admin\Branches\BranchEditor::class)->middleware('permission:branches.view')->name('branches.edit');
