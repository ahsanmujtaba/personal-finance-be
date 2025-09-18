<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BudgetItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'budget_id',
        'category_id',
        'planned_amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'planned_amount' => 'decimal:2',
        ];
    }

    // Relationships
    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    // Computed properties
    public function getSpentAmountAttribute()
    {
        return $this->expenses()->sum('amount');
    }

    public function getRemainingAmountAttribute()
    {
        return $this->planned_amount - $this->spent_amount;
    }

    public function getIsOverBudgetAttribute()
    {
        return $this->spent_amount > $this->planned_amount;
    }

    public function getBudgetUtilizationAttribute()
    {
        if ($this->planned_amount == 0) {
            return 0;
        }
        return ($this->spent_amount / $this->planned_amount) * 100;
    }
}
