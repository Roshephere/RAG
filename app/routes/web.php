<?php

use App\Http\Controllers\ConversationController;
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

    Route::prefix('knowledge-bases/{knowledgeBase}/conversations')
        ->name('knowledge-bases.conversations.')
        ->where([
            'knowledgeBase' => '[0-9]+',
            'conversation' => '[0-9]+',
        ])
        ->group(function () {

            Route::get('/', [ConversationController::class, 'index'])->name('index');

            Route::post('/', [ConversationController::class, 'store'])->name('store');

            Route::get('/{conversation}', [ConversationController::class, 'show'])->name('show');

            Route::delete('/{conversation}', [ConversationController::class, 'destroy'])->name('destroy');

            Route::post('/{conversation}/messages', [ConversationController::class, 'storeMessage'])
                ->middleware('throttle:10,1')
                ->name('messages.store');
        });
});

require __DIR__.'/settings.php';
