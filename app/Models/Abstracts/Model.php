<?php

namespace App\Models\Abstracts;

use Illuminate\Support\Str;

/**
 * @method static static findOrFail($id, $columns = [])
 * @method static static create($attributes)
 */
abstract class Model extends \Illuminate\Database\Eloquent\Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'id' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    protected $guarded = [];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (self $model) {
            if (empty($model->id)) $model->id = Str::uuid7()->toString();
        });
    }
}
