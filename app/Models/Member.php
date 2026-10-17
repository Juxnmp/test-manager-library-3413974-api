<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    protected $table = 'library.member';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'full_name',
        'document_number',
    ];

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'member_id', 'id');
    }
}
