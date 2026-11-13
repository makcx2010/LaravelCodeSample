<?php

namespace App\DTO;

use App\Enums\UserRankType;
use App\Service\Helpers\MatchHelper;
use Carbon\Carbon;

class PvpMatchRequestDTO
{
    public const string REDIS_COMMON_LIST_KEY = 'pvp_match_request:common_list';
    public const string REDIS_ELITE_LIST_KEY = 'pvp_match_request:elite_list';

    public int $priority = 0;

    public function __construct(
        public string $id,
        public string $userId,
        public int $elo,
        public UserRankType $rankType,
        public Carbon $createdAt
    )
    {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'priority' => 0,
            'elo' => $this->elo,
            'rankType' => $this->rankType,
            'createdAt' => $this->createdAt->timestamp,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new self(
            id: $data['id'],
            userId: $data['userId'],
            elo: $data['elo'],
            rankType: UserRankType::from($data['rankType']),
            createdAt: Carbon::parse($data['createdAt'])
        );
    }
}
