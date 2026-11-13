<?php

namespace App\Console\Commands;

use App\DTO\PvpMatchRequestDTO;
use App\Enums\CentrifugoEvent;
use App\Enums\MatchStatus;
use App\Enums\MatchType;
use App\Models\PvpMatch;
use App\Models\User;
use App\Service\Centrifugo\CentrifugoService;
use App\Service\Redis\PvpMatchRequestService;
use App\Service\Telegram\MiniApp\BotPvpAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MatchmakingPvpWorker extends Command
{
    protected const int ELO_START_INCREMENT_MULTIPLIER = 2;
    protected const int ELO_INTERVAL_INCREMENT_SECS = 5;
    protected const int ELO_INTERVAL_INCREMENT_COMMON = 1;
    protected const int ELO_INTERVAL_INCREMENT_ELITE = 10;
    protected const int MAX_INCREMENTS = 7;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:worker:matchmaking';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run matchmaking pvp worker';

    public function __construct(
        protected PvpMatchRequestService $pvpMatchRequestService,
        protected CentrifugoService $centrifugoService,
        protected BotPvpAlert $botPvpAlert,
    )
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $tick = 0;
        $maxTicks = 1800;
        while (true) {
            $start = now();
            $tick++;
            try {
                $this->process();
            } catch (Throwable $e) {
                try {
                    $this->botPvpAlert->sendMessage(['chat_id' => User::TG_ID_FOR_ALERTS, 'text' => "app:matchmaking_worker:process: {$e->getMessage()}"]);
                } catch (Throwable $e) {}
                Log::channel('custom')->error('app:matchmaking_worker:process', [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }

            if ($tick >= $maxTicks) {
                $this->info('Max ticks reached');
                exit(0);
            }

            $elapsed = now()->diffInMicroseconds($start, true);
            $sleep = max(0, 1_000_000 - $elapsed);
            if ($sleep <= 500_000) {
                try {
                    $this->botPvpAlert->sendMessage(['chat_id' => User::TG_ID_FOR_ALERTS, 'text' => "app:matchmaking_worker:process:low_sleep: $elapsed $sleep"]);
                } catch (Throwable $e) {}
                Log::channel('custom')->alert('app:matchmaking_worker:process:low_sleep', ['elapsed' => $elapsed, 'sleep' => $sleep]);
            }
            usleep($sleep);
        }
    }

    private function process(): void
    {
        try {
            $this->processList(MatchType::common, PvpMatchRequestDTO::REDIS_COMMON_LIST_KEY, self::ELO_INTERVAL_INCREMENT_COMMON);
        } catch (Throwable $e) {
            try {
                $this->botPvpAlert->sendMessage(['chat_id' => User::TG_ID_FOR_ALERTS, 'text' => "app:matchmaking_worker:processList:common: {$e->getMessage()}"]);
            } catch (Throwable $e) {}
            Log::channel('custom')->error('app:matchmaking_worker:processList:common', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
//        titans leaderboard will not be available at start of project (implement later). Some things already was implemented, search by project @titansPvp
//        try {
//            $this->processList(MatchType::titan, PvpMatchRequestDTO::REDIS_ELITE_LIST_KEY, self::ELO_INTERVAL_INCREMENT_ELITE);
//        } catch (Throwable $e) {
//        try {
//            $this->botPvpAlert->sendMessage(['chat_id' => User::TG_ID_FOR_ALERTS, 'text' => "app:matchmaking_worker:processList:elite: {$e->getMessage()}"]);
//        } catch (Throwable $e) {}
//        Log::channel('custom')->error('app:matchmaking_worker:processList:elite', [
//            'message' => $e->getMessage(),
//            'code' => $e->getCode(),
//            'file' => $e->getFile(),
//            'line' => $e->getLine(),
//        ]);
//        }
    }
    private function processList(MatchType $type, string $listKey, int $intervalIncrement): void
    {
        $requests = $this->pvpMatchRequestService->getArray($listKey);
        if (empty($requests)) return;

        // 1) Обновляем приоритеты
        $now = now();
        foreach ($requests as $r) {
            $r->priority += intdiv($r->createdAt->diffInSeconds($now), self::ELO_INTERVAL_INCREMENT_SECS);
        }

        // 2) Сортируем по ELO (индексы 0..N-1)
        usort($requests, static fn($a, $b) => $a->elo <=> $b->elo);
        $n = count($requests);
        if ($n < 2) return;

        // 3) Порядок обработки - по убыванию приоритета
        $keysByPriority = range(0, $n - 1);
        usort($keysByPriority, static function(int $a, int $b) use ($requests) {
            return $requests[$b]->priority <=> $requests[$a]->priority;
        });

        // 4) Двусвязный список по индексам 0..N-1
        $prev = $next = $alive = [];
        for ($i = 0; $i < $n; $i++) {
            $prev[$i]  = $i > 0      ? $i - 1 : null;
            $next[$i]  = $i + 1 < $n ? $i + 1 : null;
            $alive[$i] = true;
        }
        $unlink = static function(int $i) use (&$prev, &$next, &$alive): void {
            if (!$alive[$i]) return;
            $L = $prev[$i]; $R = $next[$i];
            if ($L !== null) $next[$L] = $R;
            if ($R !== null) $prev[$R] = $L;
            $prev[$i] = $next[$i] = null;
            $alive[$i] = false;
        };

        // 5) Подбираем пары; сразу генерим id для bulk-insert
        $matchesData = [];   // для уведомлений: [req1DTO, req2DTO, id]
        $insertRows  = [];   // для БД: массив строк вставки

        foreach ($keysByPriority as $idx) {
            if (!$alive[$idx]) continue;
            $request = $requests[$idx];

            // ближайшие живые соседи по ELO
            $leftIdx  = $prev[$idx];
            $rightIdx = $next[$idx];
            if ($leftIdx === null && $rightIdx === null) continue;

            if ($leftIdx === null) {
                $oppIdx = $rightIdx;
            } elseif ($rightIdx === null) {
                $oppIdx = $leftIdx;
            } else {
                $diffLeft  = abs($requests[$leftIdx]->elo  - $request->elo);
                $diffRight = abs($requests[$rightIdx]->elo - $request->elo);
                $oppIdx = ($diffLeft <= $diffRight) ? $leftIdx : $rightIdx;
            }

            $eloRange = min(
                (self::ELO_START_INCREMENT_MULTIPLIER * $intervalIncrement) + ($request->priority * $intervalIncrement),
                $intervalIncrement * self::MAX_INCREMENTS
            );
            $requestOpponent = $requests[$oppIdx];
            if (abs($requestOpponent->elo - $request->elo) > $eloRange) continue;

            // матч найден - фиксация
            $id = Str::uuid7()->toString();
            $matchesData[] = [$request, $requestOpponent, $id];
            $insertRows[] = [
                'id'                      => $id,
                'pvp_match_request1_id'   => $request->id,
                'pvp_match_request2_id'   => $requestOpponent->id,
                'type'                    => $type->value,
                'ws_channel'              => "pvp_match:$id",
                'status'                  => MatchStatus::pending->value,
                'updated_at'              => $now,
                'created_at'              => $now,
            ];

            // удаляем из связного списка за O(1)
            $unlink($idx);
            $unlink($oppIdx);

            // чистим Redis-заявки
            $this->pvpMatchRequestService->remove($request->rankType, $request->toJson());
            $this->pvpMatchRequestService->remove($requestOpponent->rankType, $requestOpponent->toJson());
        }

        if (empty($insertRows)) return;

        // 6) Chunk bulk-insert всех матчей
        DB::transaction(static function () use ($insertRows) {
            $chunks = array_chunk($insertRows, 500);
            foreach ($chunks as $batch) PvpMatch::insert($batch);
        });

        // 7) Рассылаем события
        $commands = [];
        foreach ($matchesData as [$player1, $player2, $pvpMatchId]) {
            $commands[] = [
                'publish' => [
                    'channel' => "user#$player1->userId",
                    'data' => [
                        'event' => CentrifugoEvent::pvp_match_found->value,
                        'payload' => [
                            'pvp_match_id' => $pvpMatchId,
                            'pvp_match_request_id' => $player1->id,
                        ],
                    ],
                ],
            ];
            $commands[] = [
                'publish' => [
                    'channel' => "user#$player2->userId",
                    'data' => [
                        'event' => CentrifugoEvent::pvp_match_found->value,
                        'payload' => [
                            'pvp_match_id' => $pvpMatchId,
                            'pvp_match_request_id' => $player2->id,
                        ],
                    ],
                ],
            ];
        }
        $this->centrifugoService->publishBatch($commands);
    }

}
