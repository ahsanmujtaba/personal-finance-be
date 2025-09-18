<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\BudgetItemController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\IncomeController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\SocialAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public authentication routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Social authentication routes
Route::prefix('auth')->group(function () {
    Route::get('/google', [SocialAuthController::class, 'redirectToGoogle']);
    Route::post('/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);
    Route::get('/facebook', [SocialAuthController::class, 'redirectToFacebook']);
    Route::post('/facebook/callback', [SocialAuthController::class, 'handleFacebookCallback']);
});

// Protected authentication routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    
    // Profile and password management
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/password', [AuthController::class, 'updatePassword']);
    
    // Budget management routes
    Route::apiResource('budgets', BudgetController::class);
    
    // Budget items nested routes
    Route::post('budgets/{budget}/items', [BudgetItemController::class, 'store']);
    
    // Budget items standalone routes
    Route::patch('budget-items/{budgetItem}', [BudgetItemController::class, 'update']);
    Route::delete('budget-items/{budgetItem}', [BudgetItemController::class, 'destroy']);
    
    // Category management routes
    Route::apiResource('categories', CategoryController::class);
    
    // Expense management routes
    Route::apiResource('expenses', ExpenseController::class);
    
    // Income management routes
    Route::apiResource('incomes', IncomeController::class);
    
    // Report routes
    Route::prefix('reports')->group(function () {
        Route::get('/dashboard', [ReportController::class, 'dashboard']);
        Route::get('/current-month-budget-stats', [ReportController::class, 'currentMonthBudgetStats']);
        Route::get('/monthly-summary', [ReportController::class, 'monthlySummary']);
        Route::get('/budget-vs-actual', [ReportController::class, 'budgetVsActual']);
        Route::get('/spending-trends', [ReportController::class, 'spendingTrends']);
    });
});