<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiResponse;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Category::where('user_id', Auth::id())
            ->ordered();

        // Filter by type if provided
        if ($request->has('type')) {
            $query->ofType($request->type);
        }

        $categories = $query->get();

        return ApiResponse::success($categories, 'Categories retrieved successfully');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:expense,income,savings',
            'sort_order' => 'nullable|integer|min:0',
            'is_default' => 'nullable|boolean',
        ]);

        // Check if category name already exists for this user and type
        $existingCategory = Category::where('user_id', Auth::id())
            ->where('name', $request->name)
            ->where('type', $request->type)
            ->first();

        if ($existingCategory) {
            return ApiResponse::error('Category with this name already exists for this type', Response::HTTP_CONFLICT);
        }

        $category = Category::create([
            'user_id' => Auth::id(),
            'name' => $request->name,
            'type' => $request->type,
            'sort_order' => $request->sort_order ?? 0,
            'is_default' => $request->is_default ?? false,
        ]);

        return ApiResponse::created($category, 'Category created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(Category $category)
    {
        // Ensure user can only access their own categories
        if ($category->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        return ApiResponse::success($category, 'Category retrieved successfully');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        // Ensure user can only update their own categories
        if ($category->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:expense,income,savings',
            'sort_order' => 'nullable|integer|min:0',
            'is_default' => 'nullable|boolean',
        ]);

        // Check if category name already exists for this user and type (excluding current category)
        $existingCategory = Category::where('user_id', Auth::id())
            ->where('name', $request->name)
            ->where('type', $request->type)
            ->where('id', '!=', $category->id)
            ->first();

        if ($existingCategory) {
            return ApiResponse::error('Category with this name already exists for this type', Response::HTTP_CONFLICT);
        }

        $category->update([
            'name' => $request->name,
            'type' => $request->type,
            'sort_order' => $request->sort_order ?? $category->sort_order,
            'is_default' => $request->is_default ?? $category->is_default,
        ]);

        return ApiResponse::success($category, 'Category updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        // Ensure user can only delete their own categories
        if ($category->user_id !== Auth::id()) {
            return ApiResponse::forbidden('Unauthorized');
        }

        // Check if category is being used in budget items or expenses
        if ($category->budgetItems()->exists() || $category->expenses()->exists()) {
            return ApiResponse::error('Cannot delete category that is being used in budget items or expenses', Response::HTTP_CONFLICT);
        }

        $category->delete();

        return ApiResponse::success(null, 'Category deleted successfully');
    }
}
