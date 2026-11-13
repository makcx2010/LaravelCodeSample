<?php

namespace App\Console\Commands;

use App\Enums\PvpSeasonStatus;
use App\Enums\UserPvpRewardStatus;
use App\Enums\UserRankType;
use App\Models\PvpSeason;
use App\Models\User;
use App\Service\Telegram\MiniApp\Bot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class MatchmakingPvpResetSeason extends Command
{
    private const int CHUNK = 5000;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:command:matchmaking:pvp:reset_season';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run pvp season reset command';

    public function __construct(protected Bot $bot)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $redisKey = 'pvp:season_reward';
        $lock = Cache::lock($redisKey, 3600);
        if (!$lock->get()) return self::SUCCESS;

        $last = null;
        DB::transaction(function () use (&$last) {
            $ts = now();
            $last = PvpSeason::where('status', PvpSeasonStatus::active->value)
                ->where('finish_at', '<', $ts)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($last) {
                PvpSeason::where('status', PvpSeasonStatus::active->value)
                    ->where('finish_at', '<', $ts)
                    ->whereKeyNot($last->getKey())->update(['status' => PvpSeasonStatus::finished->value]);
            }
        });
        if (!$last) {
            $lock->release();
            return self::SUCCESS;
        }

        try {
            User::select(['id', 'pvp_rating_rank_type', 'pvp_rating_elo', 'pvp_rating_elo_next'])
                ->whereDoesntHave('pvpRewards', function ($q) use ($last) {
                    $q->where('pvp_season_id', $last->id);
                })
                ->whereNotIn('pvp_rating_rank_type', [UserRankType::peasant->value, UserRankType::titan->value])
                ->chunkById(self::CHUNK, function ($chunk) use ($last) {
                    $rewardRows = $userUpdates = [];
                    /** @var User $u */
                    foreach ($chunk as $u) {
                        $rankUpCnt = UserRankType::getRankNumber(UserRankType::peasant) - UserRankType::getRankNumber($u->pvp_rating_rank_type);
                        $rankType = UserRankType::peasant;
                        $elo = User::START_COMMON_ELO;
                        $eloNext = UserRankType::calculatePvpRatingStarsNext($rankType);
                        for($i = 1; $i <= $rankUpCnt; $i++){
                            $elo++;
                            if ($elo > $eloNext) {
                                $elo = User::START_COMMON_ELO;
                                $rankType = $rankType->getNextRankType();
                                $eloNext = UserRankType::calculatePvpRatingStarsNext($rankType);
                            }
                        }

                        $rewardRows[] = [
                            'id' => Str::uuid7()->toString(),
                            'user_id' => $u->id,
                            'pvp_season_id' => $last->id,
                            'crystals_amount' => PvpSeason::CRYSTALS_REWARDS[$u->pvp_rating_rank_type->value],
                            'status' => UserPvpRewardStatus::active->value,
                        ];
                        $userUpdates[] = [
                            'id' => $u->id,
                            'pvp_rating_elo' => $elo,
                            'pvp_rating_elo_next' => $eloNext,
                            'pvp_rating_rank_type' => $rankType->value,
                        ];
                    }

                    DB::transaction(function () use ($rewardRows, $userUpdates) {
                        DB::table('user_pvp_rewards')->insert($rewardRows);
                        // 2) создаём временную таблицу для обновлений
                        DB::statement("
        CREATE TEMPORARY TABLE tmp_user_pvp_reset_updates (
            id VARCHAR(36) NOT NULL,
            pvp_rating_elo INT NOT NULL,
            pvp_rating_elo_next INT NOT NULL,
            pvp_rating_rank_type VARCHAR(255) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB
        DEFAULT CHARSET = utf8mb4
        COLLATE = utf8mb4_unicode_ci
    ");
                        // 3) массово заливаем данные во временную таблицу
                        DB::table('tmp_user_pvp_reset_updates')->insert($userUpdates);
                        // 4) одно массовое обновление по джойну
                        DB::statement("
        UPDATE users u
        JOIN tmp_user_pvp_reset_updates t ON t.id = u.id
        SET u.pvp_rating_elo       = t.pvp_rating_elo,
            u.pvp_rating_elo_next  = t.pvp_rating_elo_next,
            u.pvp_rating_rank_type = t.pvp_rating_rank_type,
            u.updated_at           = NOW()
    ");
                        // 5) Явно прибираем за собой
                        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_user_pvp_reset_updates');
                    });
                }, column: 'id');
            $last->status = PvpSeasonStatus::finished;
            $last->save();
        } catch (Throwable $e) {
            $this->bot->api->sendMessage(['chat_id' => User::TG_ID_FOR_ALERTS, 'text' => "pvp_season_reset:error: {$e->getMessage()}"]);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
