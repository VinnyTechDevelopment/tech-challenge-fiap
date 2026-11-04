<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerModel extends Model
{
    protected $table = 'customers';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'email',
        'phone',
        'document',
        'created_user_id',
        'updated_user_id',
    ];
}
