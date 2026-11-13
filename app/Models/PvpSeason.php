<?php

namespace App\Models;

use App\Enums\PvpSeasonStatus;
use App\Enums\UserRankType;
use App\Models\Abstracts\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 
 *
 * @property string $id
 * @property PvpSeasonStatus $status
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PvpSeason newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PvpSeason newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PvpSeason query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PvpSeason whereFinishAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PvpSeason whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PvpSeason whereStatus($value)
 * @property \Illuminate\Support\Carbon $finish_at
 * @mixin \Eloquent
 */
class PvpSeason extends Model
{
    public const array CRYSTALS_REWARDS = [
        UserRankType::peasant->value => 0,
        UserRankType::squire->value => 100,
        UserRankType::novice->value => 200,
        UserRankType::gladiator->value => 300,
        UserRankType::warrior->value => 400,
        UserRankType::soldier->value => 600,
        UserRankType::guardian->value => 800,
        UserRankType::champion->value => 1000,
        UserRankType::hero->value => 1200,
        UserRankType::conqueror->value => 1500,
        UserRankType::tyrant->value => 1800,
        UserRankType::king->value => 2100,
        UserRankType::immortal->value => 2400,
        UserRankType::demigod->value => 2700,
        UserRankType::god->value => 3000,
        UserRankType::titan->value => 3000,
    ];

    public $timestamps = false;
    protected $casts = [
        'status' => PvpSeasonStatus::class,
        'finish_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
