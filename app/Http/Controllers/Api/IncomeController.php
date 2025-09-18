<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiResponse;
use App\Models\Income;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class IncomeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Income::where('user_id', Auth::id())
            ->with(['budget']);

        // Filter by budget
        if ($request->has('budget_id')) {
            $query->where('budget_id', $request->budget_id);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('date', '<=', $request->end_date);
        }

        // Filter by amount range
        if ($request->has('min_amount')) {
            $query->where('amount', '>=', $request->min_amount);
        }

        if ($request->has('max_amount')) {
            $query->where('amount', '<=', $request->max_amount);
        }

        // Search by source or note
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('source', 'like', "%{$search}%")
                  ->orWhere('note', 'like', "%{$search}%");
            });
        }

        $incomes = $query->orderBy('date', 'desc')
            ->get();

        return ApiResponse::success($incomes, 'Incomes retrieved successfully');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'budget_id' => 'required|exists:budgets,id',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'source' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:1000',
        ]);

        // Verify category belongs to user and is income type
        $budget = \App\Models\Budget::where('id', $request->budget_id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$budget) {
            return ApiResponse::notFound('Budget not found');
        }

        $income = Income::create([
            'user_id' => Auth::id(),
            'budget_id' => $request->budget_id,
            'date' => $request->date,
            'amount' => $request->amount,
            'source' => $request->source,
            'note' => $request->note,
        ]);

        return ApiResponse::created($income->load('budget'), 'Income created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(Income $income)
    {
        // Ensure user can only view their own incomes
        if ($income->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        return ApiResponse::success($income->load('budget'), 'Income retrieved successfully');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Income $income)
    {
        // Ensure user can only update their own incomes
        if ($income->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        $request->validate([
            'budget_id' => 'required|exists:budgets,id',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'source' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:1000',
        ]);

        // Verify category belongs to user and is income type
        $budget = \App\Models\Budget::where('id', $request->budget_id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$budget) {
            return ApiResponse::notFound('Budget not found');
        }

        $income->update([
            'budget_id' => $request->budget_id,
            'date' => $request->date,
            'amount' => $request->amount,
            'source' => $request->source,
            'note' => $request->note,
        ]);

        return ApiResponse::success($income->load('budget'), 'Income updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Income $income)
    {
        // Ensure user can only delete their own incomes
        if ($income->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        $income->delete();

        return ApiResponse::success(null, 'Income deleted successfully');
    }
}
