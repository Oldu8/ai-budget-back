<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppUser extends Model
{
    use SoftDeletes;

    protected $table = 'app_users';

    protected $fillable = [
        'supabase_user_id',
        'email',
        'first_name',
        'last_name',
        'email_verified_at',
        'payment_customer_id',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
}
