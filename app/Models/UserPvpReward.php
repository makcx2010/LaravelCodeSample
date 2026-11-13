<?php

namespace App\Models;

use App\Enums\UserPvpRewardStatus;
use App\Models\Abstracts\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 
 *
 * @property string $id
 * @property string $pvp_season_id
 * @property string $user_id
 * @property UserPvpRewardStatus $status
 * @property int $crystals_amount
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPvpReward newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPvpReward newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPvpReward query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPvpReward whereCrystalsAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPvpReward whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPvpReward wherePvpSeasonId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPvpReward whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPvpReward whereUserId($value)
 * @mixin \Eloquent
 */
class UserPvpReward extends Model
{
    public $timestamps = false;
    protected $casts = [
        'status' => UserPvpRewardStatus::class,
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
