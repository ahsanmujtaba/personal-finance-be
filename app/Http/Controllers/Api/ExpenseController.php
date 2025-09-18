<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiResponse;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ExpenseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Expense::forUser(Auth::id())
            ->with(['budget', 'category', 'budgetItem'])
            ->orderBy('date', 'desc');

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->inDateRange($request->start_date, $request->end_date);
        }

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by budget
        if ($request->has('budget_id')) {
            $query->where('budget_id', $request->budget_id);
        }

        // Search by merchant or note
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('merchant', 'like', "%{$search}%")
                  ->orWhere('note', 'like', "%{$search}%");
            });
        }

        $expenses = $query->get();

        return ApiResponse::success($expenses, 'Expenses retrieved successfully');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'budget_id' => 'required|exists:budgets,id',
            'budget_item_id' => 'required|exists:budget_items,id',
            'category_id' => 'required|exists:categories,id',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'merchant' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:1000',
        ]);

        // Verify category belongs to user
        $category = \App\Models\Category::where('id', $request->category_id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$category) {
            return ApiResponse::notFound('Category not found');
        }

        // Verify budget belongs to user
        $budget = \App\Models\Budget::where('id', $request->budget_id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$budget) {
            return ApiResponse::notFound('Budget not found');
        }

        // Verify budget item belongs to the budget and category
        $budgetItem = \App\Models\BudgetItem::where('id', $request->budget_item_id)
            ->where('budget_id', $request->budget_id)
            ->where('category_id', $request->category_id)
            ->first();

        if (!$budgetItem) {
            return ApiResponse::notFound('Budget item not found or does not match budget and category');
        }

        // Check if expense would exceed budget item limit
        $currentSpent = $budgetItem->spent_amount;
        if (($currentSpent + $request->amount) > $budgetItem->planned_amount) {
            return ApiResponse::error('Expense would exceed budget item limit', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $expense = Expense::create([
            'user_id' => Auth::id(),
            'budget_id' => $request->budget_id,
            'budget_item_id' => $request->budget_item_id,
            'category_id' => $request->category_id,
            'date' => $request->date,
            'amount' => $request->amount,
            'merchant' => $request->merchant,
            'note' => $request->note,
        ]);

        return ApiResponse::created($expense->load(['budget', 'category', 'budgetItem']), 'Expense created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(Expense $expense)
    {
        // Ensure user can only access their own expenses
        if ($expense->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        return ApiResponse::success($expense->load(['budget', 'category', 'budgetItem']), 'Expense retrieved successfully');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Expense $expense)
    {
        // Ensure user can only update their own expenses
        if ($expense->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        $request->validate([
            'budget_id' => 'required|exists:budgets,id',
            'budget_item_id' => 'required|exists:budget_items,id',
            'category_id' => 'required|exists:categories,id',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'merchant' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:1000',
        ]);

        // Verify category belongs to user
        $category = \App\Models\Category::where('id', $request->category_id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$category) {
            return ApiResponse::notFound('Category not found');
        }

        // Verify budget belongs to user
        $budget = \App\Models\Budget::where('id', $request->budget_id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$budget) {
            return ApiResponse::notFound('Budget not found');
        }

        // Verify budget item belongs to the budget and category
        $budgetItem = \App\Models\BudgetItem::where('id', $request->budget_item_id)
            ->where('budget_id', $request->budget_id)
            ->where('category_id', $request->category_id)
            ->first();

        if (!$budgetItem) {
            return ApiResponse::notFound('Budget item not found or does not match budget and category');
        }

        // Check if expense would exceed budget item limit (excluding current expense amount)
        $currentSpent = $budgetItem->spent_amount - $expense->amount;
        if (($currentSpent + $request->amount) > $budgetItem->planned_amount) {
            return ApiResponse::error('Expense would exceed budget item limit', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $expense->update([
            'budget_id' => $request->budget_id,
            'budget_item_id' => $request->budget_item_id,
            'category_id' => $request->category_id,
            'date' => $request->date,
            'amount' => $request->amount,
            'merchant' => $request->merchant,
            'note' => $request->note,
        ]);

        return ApiResponse::success($expense->load(['budget', 'category', 'budgetItem']), 'Expense updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Expense $expense)
    {
        // Ensure user can only delete their own expenses
        if ($expense->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        $expense->delete();

        return ApiResponse::success(null, 'Expense deleted successfully');
    }
}
