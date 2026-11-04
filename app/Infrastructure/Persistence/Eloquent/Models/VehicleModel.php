<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleModel extends Model
{
    protected $table = 'vehicles';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'brand',
        'model',
        'year',
        'plate',
        'created_user_id',
        'updated_user_id',
    ];
}
