<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\KnowledgeBaseController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('/knowledge-bases', [KnowledgeBaseController::class, 'index'])->name('knowledge-bases.index');
    Route::post('/knowledge-bases', [KnowledgeBaseController::class, 'store'])->name('knowledge-bases.store');
    Route::put('/knowledge-bases/{id}', [KnowledgeBaseController::class, 'update'])->name('knowledge-bases.update');
    Route::delete('/knowledge-bases/{id}', [KnowledgeBaseController::class, 'destroy'])->name('knowledge-bases.destroy');

    // knowledge base document upload route
    Route::post('/knowledge-bases/{knowledgeBase}/documents', [DocumentController::class, 'store'])->name('knowledge-bases.documents.store');
    Route::delete('/knowledge-bases/{knowledgeBase}/documents/{document}', [DocumentController::class, 'destroy'])->name('knowledge-bases.documents.destroy');
    Route::get('/knowledge-bases/{knowledgeBase}/documents/{document}/download', [DocumentController::class, 'download'])->name('knowledge-bases.documents.download');
});

require __DIR__.'/settings.php';
