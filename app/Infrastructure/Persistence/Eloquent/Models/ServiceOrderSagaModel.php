<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceOrderSagaModel extends Model
{
    protected $table = 'service_order_sagas';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'pending_compensations' => 'array',
        'deadline_at' => 'datetime',
    ];
}
