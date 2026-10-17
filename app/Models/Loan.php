<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    protected $table = 'library.loan';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'member_id',
        'loaned_by_user_id',
        'loaned_at',
    ];

    protected $casts = [
        'loaned_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'loaned_by_user_id', 'id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoanItem::class, 'loan_id', 'id');
    }

    /**
     * Calculate loan status dynamically per spec HU-08 and CA-08.3:
     * - closed: all items returned
     * - overdue: at least one unreturned item has due_at < now()
     * - open: has unreturned items and none is overdue
     */
    public function getCalculatedStatusAttribute(): string
    {
        $now = Carbon::now('UTC');
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        if ($items->isEmpty()) {
            return 'closed';
        }

        $allReturned = true;
        $hasOverdue = false;

        foreach ($items as $item) {
            if ($item->returned_at === null) {
                $allReturned = false;
                if ($item->due_at !== null && Carbon::parse($item->due_at)->lessThan($now)) {
                    $hasOverdue = true;
                }
            }
        }

        if ($allReturned) {
            return 'closed';
        }

        if ($hasOverdue) {
            return 'overdue';
        }

        return 'open';
    }
}
