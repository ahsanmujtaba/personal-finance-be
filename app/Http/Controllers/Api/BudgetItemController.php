<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiResponse;
use App\Models\Budget;
use App\Models\BudgetItem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class BudgetItemController extends Controller
{
    /**
     * Store a newly created budget item for a specific budget.
     */
    public function store(Request $request, Budget $budget)
    {
        // Ensure user can only add items to their own budgets
        if ($budget->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'planned_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Check if budget item already exists for this category in this budget
        $existingItem = BudgetItem::where('budget_id', $budget->id)
            ->where('category_id', $request->category_id)
            ->first();

        if ($existingItem) {
            return ApiResponse::error('Budget item already exists for this category in this budget', Response::HTTP_CONFLICT);
        }

        $budgetItem = BudgetItem::create([
            'budget_id' => $budget->id,
            'category_id' => $request->category_id,
            'planned_amount' => $request->planned_amount,
            'notes' => $request->notes,
        ]);

        return ApiResponse::created($budgetItem->load('category'), 'Budget item created successfully');
    }

    /**
     * Update the specified budget item.
     */
    public function update(Request $request, BudgetItem $budgetItem)
    {
        // Ensure user can only update items from their own budgets
        if ($budgetItem->budget->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'planned_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Check if another budget item exists for this category in the same budget (excluding current item)
        $existingItem = BudgetItem::where('budget_id', $budgetItem->budget_id)
            ->where('category_id', $request->category_id)
            ->where('id', '!=', $budgetItem->id)
            ->first();

        if ($existingItem) {
            return ApiResponse::error('Budget item already exists for this category in this budget', Response::HTTP_CONFLICT);
        }

        $budgetItem->update([
            'category_id' => $request->category_id,
            'planned_amount' => $request->planned_amount,
            'notes' => $request->notes,
        ]);

        return ApiResponse::success($budgetItem->load('category'), 'Budget item updated successfully');
    }

    /**
     * Remove the specified budget item.
     */
    public function destroy(BudgetItem $budgetItem)
    {
        // Ensure user can only delete items from their own budgets
        if ($budgetItem->budget->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        $budgetItem->delete();

        return ApiResponse::success(null, 'Budget item deleted successfully');
    }
}