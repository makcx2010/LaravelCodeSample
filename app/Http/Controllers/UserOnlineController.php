<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Redis;

class UserOnlineController extends Controller
{
    public function ping(): JsonResponse
    {
        $user = User::authWith();
        $userId = $user->id;
        $now = time();
        $ttlSeconds = 25;
        $lastSeenTtlSeconds = 24 * 60 * 60;
        $presenceKey = "presence:user:$userId";
        $lastSeenKey = "presence:last_seen:$userId";
        $lua = <<<'LUA'
local presenceKey = KEYS[1]
local lastSeenKey = KEYS[2]

local now     = tonumber(ARGV[1])
local ttl     = tonumber(ARGV[2])
local lastTtl = tonumber(ARGV[3])

local expireAt = now + ttl

-- online: ключ существует, пока не протухнет TTL
redis.call('SET', presenceKey, '1', 'EX', ttl)

-- last_seen: timestamp с TTL (например, 24 часа)
redis.call('SET', lastSeenKey, now, 'EX', lastTtl)

return expireAt
LUA;

        $expireAt = Redis::eval(
            $lua,
            2,
            $presenceKey,
            $lastSeenKey,
            $now,
            $ttlSeconds,
            $lastSeenTtlSeconds
        );

        return response()->json(['ok' => true, 'expire_at' => (int) $expireAt]);
    }

    public function status(array $idList): array
    {
        $idList = array_values(array_unique(array_filter($idList, fn ($v) => $v !== null && $v !== '')));
        if (count($idList) === 0) return [];

        $presenceKeys = array_map(fn ($id) => "presence:user:$id", $idList);
        $lastSeenKeys = array_map(fn ($id) => "presence:last_seen:$id", $idList);
        $responses = Redis::pipeline(function ($pipe) use ($presenceKeys, $lastSeenKeys) {
            $pipe->mget($presenceKeys);
            $pipe->mget($lastSeenKeys);
        });

        $presenceArr = $responses[0] ?? [];
        $lastSeenArr = $responses[1] ?? [];

        $result = [];
        foreach ($idList as $i => $id) {
            $isOnline = (bool)($presenceArr[$i] ?? null);
            $lastSeenRaw = $lastSeenArr[$i] ?? null;
            $result[$id] = [
                'is_online' => $isOnline,
                'last_seen_at' => ($lastSeenRaw === null || $lastSeenRaw === false) ? null : (int) $lastSeenRaw,
            ];
        }

        return $result;
    }
}
