<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiResponse;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * Get monthly financial summary
     */
    public function monthlySummary(Request $request)
    {
        $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $year = $request->year;
        $month = $request->month;
        $userId = Auth::id();

        // Get start and end dates for the month
        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        // Total income for the month
        $totalIncome = Income::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('amount');

        // Total expenses for the month
        $totalExpenses = Expense::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('amount');

        // Net income (income - expenses)
        $netIncome = $totalIncome - $totalExpenses;

        // Expense breakdown by category
        $expensesByCategory = Expense::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->join('categories', 'expenses.category_id', '=', 'categories.id')
            ->select('categories.name', 'categories.id', DB::raw('SUM(expenses.amount) as total'))
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('total', 'desc')
            ->get();

        // Income breakdown by category
        $incomesByCategory = Income::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->join('categories', 'incomes.category_id', '=', 'categories.id')
            ->select('categories.name', 'categories.id', DB::raw('SUM(incomes.amount) as total'))
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('total', 'desc')
            ->get();

        return ApiResponse::success([
            'period' => [
                'year' => $year,
                'month' => $month,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
            'summary' => [
                'total_income' => $totalIncome,
                'total_expenses' => $totalExpenses,
                'net_income' => $netIncome,
            ],
            'expenses_by_category' => $expensesByCategory,
            'incomes_by_category' => $incomesByCategory,
        ], 'Monthly summary retrieved successfully');
    }

    /**
     * Get budget vs actual comparison
     */
    public function budgetVsActual(Request $request)
    {
        $request->validate([
            'budget_id' => 'required|exists:budgets,id',
        ]);

        $budget = Budget::where('id', $request->budget_id)
            ->where('user_id', Auth::id())
            ->with('budgetItems.category')
            ->first();

        if (!$budget) {
            return ApiResponse::notFound('Budget not found');
        }

        $startDate = Carbon::parse($budget->start_date);
        $endDate = Carbon::parse($budget->end_date);
        $userId = Auth::id();

        $comparison = [];
        $totalBudgeted = 0;
        $totalActual = 0;

        foreach ($budget->budgetItems as $item) {
            $actualSpent = Expense::where('user_id', $userId)
                ->where('category_id', $item->category_id)
                ->whereBetween('date', [$startDate, $endDate])
                ->sum('amount');

            $budgetedAmount = $item->budgeted_amount;
            $variance = $budgetedAmount - $actualSpent;
            $percentageUsed = $budgetedAmount > 0 ? ($actualSpent / $budgetedAmount) * 100 : 0;

            $comparison[] = [
                'category' => $item->category,
                'budgeted_amount' => $budgetedAmount,
                'actual_spent' => $actualSpent,
                'variance' => $variance,
                'percentage_used' => round($percentageUsed, 2),
                'is_over_budget' => $actualSpent > $budgetedAmount,
            ];

            $totalBudgeted += $budgetedAmount;
            $totalActual += $actualSpent;
        }

        return ApiResponse::success([
            'budget' => $budget,
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
            'summary' => [
                'total_budgeted' => $totalBudgeted,
                'total_actual' => $totalActual,
                'total_variance' => $totalBudgeted - $totalActual,
                'percentage_used' => $totalBudgeted > 0 ? round(($totalActual / $totalBudgeted) * 100, 2) : 0,
            ],
            'category_comparison' => $comparison,
        ], 'Budget vs actual comparison retrieved successfully');
    }

    /**
     * Get spending trends over time
     */
    public function spendingTrends(Request $request)
    {
        $request->validate([
            'period' => 'required|in:6months,1year,2years',
            'category_id' => 'nullable|exists:categories,id',
        ]);

        $userId = Auth::id();
        $endDate = Carbon::now();
        
        switch ($request->period) {
            case '6months':
                $startDate = $endDate->copy()->subMonths(6);
                $groupBy = 'month';
                break;
            case '1year':
                $startDate = $endDate->copy()->subYear();
                $groupBy = 'month';
                break;
            case '2years':
                $startDate = $endDate->copy()->subYears(2);
                $groupBy = 'month';
                break;
        }

        $query = Expense::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate]);

        if ($request->category_id) {
            // Verify category belongs to user
            $category = Category::where('id', $request->category_id)
                ->where('user_id', $userId)
                ->first();

            if (!$category) {
                return ApiResponse::notFound('Category not found');
            }

            $query->where('category_id', $request->category_id);
        }

        $trends = $query->select(
                DB::raw('YEAR(date) as year'),
                DB::raw('MONTH(date) as month'),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->map(function ($item) {
                return [
                    'period' => Carbon::create($item->year, $item->month, 1)->format('Y-m'),
                    'year' => $item->year,
                    'month' => $item->month,
                    'total' => $item->total,
                ];
            });

        return ApiResponse::success([
            'period' => $request->period,
            'category_id' => $request->category_id,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'trends' => $trends,
        ], 'Spending trends retrieved successfully');
    }

    /**
     * Get comprehensive dashboard statistics
     */
    public function dashboard(Request $request)
    {
        $userId = Auth::id();
        $currentMonth = Carbon::now();
        $startOfMonth = $currentMonth->copy()->startOfMonth();
        $endOfMonth = $currentMonth->copy()->endOfMonth();
        $lastMonth = $currentMonth->copy()->subMonth();
        $startOfLastMonth = $lastMonth->copy()->startOfMonth();
        $endOfLastMonth = $lastMonth->copy()->endOfMonth();

        // Current month totals
        $monthlyIncome = Income::where('user_id', $userId)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->whereHas('budget')
            ->sum('amount');

        $monthlyExpenses = Expense::where('user_id', $userId)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->whereHas('budget')
            ->sum('amount');

        // Last month totals for comparison
        $lastMonthIncome = Income::where('user_id', $userId)
            ->whereBetween('date', [$startOfLastMonth, $endOfLastMonth])
            ->whereHas('budget')
            ->sum('amount');

        $lastMonthExpenses = Expense::where('user_id', $userId)
            ->whereBetween('date', [$startOfLastMonth, $endOfLastMonth])
            ->whereHas('budget')
            ->sum('amount');

        // Year to date totals
        $yearStart = $currentMonth->copy()->startOfYear();
        $ytdIncome = Income::where('user_id', $userId)
            ->whereHas('budget')
            ->whereBetween('date', [$yearStart, $endOfMonth])
            ->sum('amount');

        $ytdExpenses = Expense::where('user_id', $userId)
            ->whereBetween('date', [$yearStart, $endOfMonth])
            ->whereHas('budget')
            ->sum('amount');

        // All time totals
        $totalIncome = Income::where('user_id', $userId)->whereHas('budget')->sum('amount');
        $totalExpenses = Expense::where('user_id', $userId)->whereHas('budget')->sum('amount');

        // Recent transactions
        $recentExpenses = Expense::where('user_id', $userId)
            ->whereHas('budget')
            ->with(['category'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        $recentIncomes = Income::where('user_id', $userId)
            ->whereHas('budget')
            ->with(['budget'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // Top spending categories this month
        $topCategories = Expense::where('user_id', $userId)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->whereHas('budget')
            ->select('category_id', DB::raw('SUM(amount) as total_spent'))
            ->with('category')
            ->groupBy('category_id')
            ->orderBy('total_spent', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($expense) {
                return [
                    'category' => $expense->category,
                    'total_spent' => $expense->total_spent,
                ];
            });

        // Active budgets with detailed progress
        $currentMonthKey = $currentMonth->format('Y-m-01');
        $activeBudgets = Budget::where('user_id', $userId)
            ->whereDate('month', $currentMonthKey)
            ->with(['budgetItems.category'])
            ->get()
            ->map(function ($budget) use ($userId) {
                // Calculate start and end dates from month field
                $budgetMonth = Carbon::parse($budget->month);
                $startDate = $budgetMonth->copy()->startOfMonth();
                $endDate = $budgetMonth->copy()->endOfMonth();
                
                $totalBudgeted = $budget->budgetItems->sum('planned_amount');
                $totalSpent = 0;
                $categoryBreakdown = [];

                foreach ($budget->budgetItems as $item) {
                    $spent = Expense::where('user_id', $userId)
                        ->where('category_id', $item->category_id)
                        ->where('budget_item_id', $item->id)
                        ->whereBetween('date', [$startDate, $endDate])
                        ->sum('amount');
                    $totalSpent += $spent;
                    
                    $categoryBreakdown[] = [
                        'category' => $item->category,
                        'budgeted' => $item->planned_amount,
                        'spent' => $spent,
                        'remaining' => $item->planned_amount - $spent,
                        'percentage_used' => $item->planned_amount > 0 ? round(($spent / $item->planned_amount) * 100, 2) : 0,
                        'is_over_budget' => $spent > $item->planned_amount,
                    ];
                }

                return [
                    'id' => $budget->id,
                    'name' => $budget->name,
                    'period' => [
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString(),
                    ],
                    'total_budgeted' => $totalBudgeted,
                    'total_spent' => $totalSpent,
                    'total_remaining' => $totalBudgeted - $totalSpent,
                    'percentage_used' => $totalBudgeted > 0 ? round(($totalSpent / $totalBudgeted) * 100, 2) : 0,
                    'is_over_budget' => $totalSpent > $totalBudgeted,
                    'status' => $totalSpent > $totalBudgeted ? 'over_budget' : 
                               ($totalBudgeted > 0 && $totalSpent / $totalBudgeted > 0.8 ? 'warning' : 'healthy'),
                    'category_breakdown' => $categoryBreakdown,
                ];
            });

        // Calculate percentage changes
        $incomeChange = $lastMonthIncome > 0 ? round((($monthlyIncome - $lastMonthIncome) / $lastMonthIncome) * 100, 2) : 0;
        $expenseChange = $lastMonthExpenses > 0 ? round((($monthlyExpenses - $lastMonthExpenses) / $lastMonthExpenses) * 100, 2) : 0;

        return ApiResponse::success([
            'overview' => [
                'current_month' => [
                    'income' => $monthlyIncome,
                    'expenses' => $monthlyExpenses,
                    'net' => $monthlyIncome - $monthlyExpenses,
                    'income_change_percentage' => $incomeChange,
                    'expense_change_percentage' => $expenseChange,
                ],
                'year_to_date' => [
                    'income' => $ytdIncome,
                    'expenses' => $ytdExpenses,
                    'net' => $ytdIncome - $ytdExpenses,
                ],
                'all_time' => [
                    'income' => $totalIncome,
                    'expenses' => $totalExpenses,
                    'net' => $totalIncome - $totalExpenses,
                ],
            ],
            'recent_transactions' => [
                'expenses' => $recentExpenses,
                'incomes' => $recentIncomes,
            ],
            'top_spending_categories' => $topCategories,
            'active_budgets' => $activeBudgets,
        ], 'Dashboard data retrieved successfully');
    }

    /**
     * Get current month budget statistics
     */
    public function currentMonthBudgetStats(Request $request)
    {
        $userId = Auth::id();
        $currentMonth = Carbon::now();
        $startOfMonth = $currentMonth->copy()->startOfMonth();
        $endOfMonth = $currentMonth->copy()->endOfMonth();

        // Find current month budget
        $currentMonthKey = $currentMonth->format('Y-m-01');
        $currentBudget = Budget::where('user_id', $userId)
            ->whereDate('month', $currentMonthKey)
            ->with(['budgetItems.category'])
            ->first();

        if (!$currentBudget) {
            return ApiResponse::success([
                'has_budget' => false,
                'message' => 'No budget found for current month',
            ], 'Current month budget stats retrieved');
        }

        // Calculate start and end dates from month field
        $budgetMonth = Carbon::parse($currentBudget->month);
        $startDate = $budgetMonth->copy()->startOfMonth();
        $endDate = $budgetMonth->copy()->endOfMonth();
        
        // Calculate detailed budget statistics
        $totalBudgeted = $currentBudget->budgetItems->sum('planned_amount');
        $totalSpent = 0;
        $categoryStats = [];
        $overBudgetCategories = 0;
        $warningCategories = 0;

        foreach ($currentBudget->budgetItems as $item) {
            $spent = Expense::where('user_id', $userId)
                ->where('category_id', $item->category_id)
                ->whereBetween('date', [$startDate, $endDate])
                ->sum('amount');
            
            $totalSpent += $spent;
            $remaining = $item->planned_amount - $spent;
            $percentageUsed = $item->planned_amount > 0 ? ($spent / $item->planned_amount) * 100 : 0;
            
            $status = 'healthy';
            if ($spent > $item->planned_amount) {
                $status = 'over_budget';
                $overBudgetCategories++;
            } elseif ($percentageUsed > 80) {
                $status = 'warning';
                $warningCategories++;
            }

            $categoryStats[] = [
                'category' => $item->category,
                'budgeted_amount' => $item->planned_amount,
                'spent_amount' => $spent,
                'remaining_amount' => $remaining,
                'percentage_used' => round($percentageUsed, 2),
                'status' => $status,
                'is_over_budget' => $spent > $item->planned_amount,
            ];
        }

        // Calculate days remaining in budget period
        $daysInPeriod = $startDate->diffInDays($endDate) + 1;
        $daysElapsed = $startDate->diffInDays(Carbon::now()) + 1;
        $daysRemaining = max(0, $daysInPeriod - $daysElapsed);
        $periodProgress = $daysInPeriod > 0 ? round(($daysElapsed / $daysInPeriod) * 100, 2) : 0;

        // Calculate spending velocity
        $dailyBudget = $daysInPeriod > 0 ? $totalBudgeted / $daysInPeriod : 0;
        $actualDailySpending = $daysElapsed > 0 ? $totalSpent / $daysElapsed : 0;
        $projectedMonthEnd = $actualDailySpending * $daysInPeriod;

        return ApiResponse::success([
            'has_budget' => true,
            'budget' => [
                'id' => $currentBudget->id,
                'name' => $currentBudget->name,
                'period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'days_total' => $daysInPeriod,
                    'days_elapsed' => $daysElapsed,
                    'days_remaining' => $daysRemaining,
                    'period_progress_percentage' => $periodProgress,
                ],
            ],
            'summary' => [
                'total_budgeted' => $totalBudgeted,
                'total_spent' => $totalSpent,
                'total_remaining' => $totalBudgeted - $totalSpent,
                'percentage_used' => $totalBudgeted > 0 ? round(($totalSpent / $totalBudgeted) * 100, 2) : 0,
                'is_over_budget' => $totalSpent > $totalBudgeted,
                'over_budget_amount' => max(0, $totalSpent - $totalBudgeted),
            ],
            'velocity' => [
                'daily_budget' => round($dailyBudget, 2),
                'actual_daily_spending' => round($actualDailySpending, 2),
                'projected_month_end_spending' => round($projectedMonthEnd, 2),
                'on_track' => $projectedMonthEnd <= $totalBudgeted,
            ],
            'category_health' => [
                'total_categories' => count($categoryStats),
                'healthy_categories' => count($categoryStats) - $overBudgetCategories - $warningCategories,
                'warning_categories' => $warningCategories,
                'over_budget_categories' => $overBudgetCategories,
            ],
            'category_breakdown' => $categoryStats,
        ], 'Current month budget statistics retrieved successfully');
    }
}
