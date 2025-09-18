<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiResponse;
use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class BudgetController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Budget::where('user_id', Auth::id())
            ->with(['budgetItems.category', 'incomes', 'expenses.category']);

        // If month parameter is provided, filter by month or create if not exists
        if ($request->has('month')) {
            $month = $request->month;
            
            // Validate month format
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                return ApiResponse::error('Invalid month format. Use YYYY-MM format.', Response::HTTP_BAD_REQUEST);
            }
            
            // Convert to first day of month format
            $monthDate = $month . '-01';
            
            $budget = $query->where('month', $monthDate)->first();
            
            // If budget doesn't exist, create a skeleton budget
            if (!$budget) {
                $budget = Budget::create([
                    'user_id' => Auth::id(),
                    'month' => $monthDate,
                    'notes' => null,
                ]);
                $budget->load(['budgetItems.category', 'incomes', 'expenses']);
            }
            
            return ApiResponse::success($budget, 'Budget retrieved successfully');
        }

        // Return all budgets if no month specified
        $budgets = $query->orderBy('month', 'desc')->get();

        // Calculate summary data
        $currentMonth = now()->format('Y-m-01');
        $totalBudgets = $budgets->count();
        
        // Get current month budget
        $currentMonthBudget = $budgets->where('month', $currentMonth)->first();
        $currentMonthBudgets = $currentMonthBudget ? 1 : 0;
        
        // Calculate current month amounts
        $currentMonthPlannedAmount = 0;
        $currentMonthActualAmount = 0;
        $currentMonthBalance = 0;
        
        if ($currentMonthBudget) {
            // Calculate planned amount from budget items
            $currentMonthPlannedAmount = $currentMonthBudget->budgetItems->sum('planned_amount');
            
            // Calculate actual amount from expenses
            $currentMonthActualAmount = $currentMonthBudget->expenses->sum('amount');
            
            // Calculate balance
            $currentMonthBalance = $currentMonthPlannedAmount - $currentMonthActualAmount;
        }
        
        $summary = [
            'total_budgets' => $totalBudgets,
            'current_month_budgets' => $currentMonthBudgets,
            'current_month_planned_amount' => number_format($currentMonthPlannedAmount, 2, '.', ''),
            'current_month_actual_amount' => number_format($currentMonthActualAmount, 2, '.', ''),
            'current_month_balance' => number_format($currentMonthBalance, 2, '.', '')
        ];
        
        $response = [
            'budgets' => $budgets,
            'summary' => $summary
        ];

        return ApiResponse::success($response, 'Budgets retrieved successfully');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'month' => 'required|date_format:Y-m-01',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Check if budget already exists for this month
        $existingBudget = Budget::where('user_id', Auth::id())
            ->where('month', $request->month)
            ->first();

        if ($existingBudget) {
            return ApiResponse::error('Budget already exists for this month', Response::HTTP_CONFLICT);
        }

        $budget = Budget::create([
            'user_id' => Auth::id(),
            'month' => $request->month,
            'notes' => $request->notes,
        ]);

        return ApiResponse::created($budget->load(['budgetItems.category', 'incomes', 'expenses']), 'Budget created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(Budget $budget)
    {
        // Ensure user can only access their own budgets
        if ($budget->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        $budget->load(['budgetItems.category', 'budgetItems.expenses', 'incomes', 'expenses']);
        
        // Calculate financial summary
        $totalPlanned = $budget->budgetItems->sum('planned_amount');
        $totalIncome = $budget->incomes->sum('amount');
        $totalExpenses = $budget->expenses->sum('amount');
        $actualSpent = $totalExpenses; // Actual amount spent from budget
        $savings = $totalIncome - $totalExpenses; // Total savings (income - expenses)
        $balance = $totalIncome - $actualSpent; // Budget balance (income - actual spent)
        
        // Zero-based budgeting calculations
        $unallocatedIncome = $totalIncome - $totalPlanned;
        $overBudgetItems = $budget->budgetItems->filter(function ($item) {
            return $item->is_over_budget;
        });
        $underBudgetItems = $budget->budgetItems->filter(function ($item) {
            return $item->remaining_amount > 0;
        });
        
        // Budget item health metrics
        $budgetItemsHealth = $budget->budgetItems->map(function ($item) {
            $data =[
                'id' => $item->id,
                'category' => $item->category->name,
                'planned_amount' => number_format($item->planned_amount, 2, '.', ''),
                'spent_amount' => number_format($item->spent_amount, 2, '.', ''),
                'remaining_amount' => number_format($item->remaining_amount, 2, '.', ''),
                'utilization_percentage' => number_format($item->budget_utilization, 1, '.', ''),
                'is_over_budget' => $item->is_over_budget,
                'status' => $item->is_over_budget ? 'over_budget' : 
                           ($item->budget_utilization > 80 ? 'warning' : 'healthy')
            ];
            $item->spent_amount = $data['spent_amount'];
            $item->remaining_amount = $data['remaining_amount'];
            $item->utilization_percentage = $data['utilization_percentage'];
            $item->is_over_budget = $data['is_over_budget'];
            $item->status = $data['status'];
            return $data;
        });
        
        $summary = [
            'total_planned' => number_format($totalPlanned, 2, '.', ''),
            'actual_spent' => number_format($actualSpent, 2, '.', ''),
            'total_income' => number_format($totalIncome, 2, '.', ''),
            'total_expenses' => number_format($totalExpenses, 2, '.', ''),
            'savings' => number_format($savings, 2, '.', ''),
            'balance' => number_format($balance, 2, '.', ''),
            'unallocated_income' => number_format($unallocatedIncome, 2, '.', ''),
            'over_budget_items_count' => $overBudgetItems->count(),
            'under_budget_items_count' => $underBudgetItems->count(),
            'budget_health_score' => $this->calculateBudgetHealthScore($budget),
            'is_zero_based' => abs($unallocatedIncome) < 0.01 // Within 1 cent tolerance
        ];
        
        $response = [
            'budget' => $budget,
            'summary' => $summary,
            'budget_items_health' => $budgetItemsHealth
        ];

        return ApiResponse::success($response, 'Budget retrieved successfully');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Budget $budget)
    {
        // Ensure user can only update their own budgets
        if ($budget->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        $request->validate([
            'month' => 'required|date_format:Y-m-01',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Check if another budget exists for this month (excluding current budget)
        $existingBudget = Budget::where('user_id', Auth::id())
            ->where('month', $request->month)
            ->where('id', '!=', $budget->id)
            ->first();

        if ($existingBudget) {
            return ApiResponse::error('Budget already exists for this month', Response::HTTP_CONFLICT);
        }

        $budget->update([
            'month' => $request->month,
            'notes' => $request->notes,
        ]);

        return ApiResponse::success($budget->load(['budgetItems.category', 'incomes', 'expenses']), 'Budget updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Budget $budget)
    {
        // Ensure user can only delete their own budgets
        if ($budget->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        $budget->delete();

        return ApiResponse::success(null, 'Budget deleted successfully');
    }

    /**
     * Calculate budget health score based on various metrics
     */
    private function calculateBudgetHealthScore($budget)
    {
        $totalItems = $budget->budgetItems->count();
        
        if ($totalItems === 0) {
            return 100; // Perfect score for empty budget
        }
        
        $healthyItems = $budget->budgetItems->filter(function ($item) {
            return !$item->is_over_budget && $item->budget_utilization <= 80;
        })->count();
        
        $warningItems = $budget->budgetItems->filter(function ($item) {
            return !$item->is_over_budget && $item->budget_utilization > 80;
        })->count();
        
        $overBudgetItems = $budget->budgetItems->filter(function ($item) {
            return $item->is_over_budget;
        })->count();
        
        // Calculate weighted score
        $score = (($healthyItems * 100) + ($warningItems * 70) + ($overBudgetItems * 0)) / $totalItems;
        
        return round($score, 1);
    }
}
