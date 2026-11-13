<?php

namespace App\Service\Redis;

use App\DTO\PvpMatchRequestDTO;
use App\Enums\UserRankType;
use Illuminate\Support\Facades\Redis;
use LogicException;

class PvpMatchRequestService
{
    public function add(UserRankType $rankType, string $key): void
    {
        if ($rankType === UserRankType::titan) Redis::lpush(PvpMatchRequestDTO::REDIS_ELITE_LIST_KEY, $key);
        else Redis::lpush(PvpMatchRequestDTO::REDIS_COMMON_LIST_KEY, $key);
    }

    public function remove(UserRankType $rankType, string $key): void
    {
        if ($rankType === UserRankType::titan) Redis::lrem(PvpMatchRequestDTO::REDIS_ELITE_LIST_KEY, 0, $key);
        else Redis::lrem(PvpMatchRequestDTO::REDIS_COMMON_LIST_KEY, 0, $key);
    }

    /** @return PvpMatchRequestDTO[] */
    public function getArray(string $key): array
    {
        if (!in_array($key, [PvpMatchRequestDTO::REDIS_COMMON_LIST_KEY, PvpMatchRequestDTO::REDIS_ELITE_LIST_KEY], true)) throw new LogicException('Unknown key');

        $rawList = Redis::lrange($key, 0, -1);

        return array_map(static fn($item) => PvpMatchRequestDTO::fromJson($item), $rawList);
    }


}
