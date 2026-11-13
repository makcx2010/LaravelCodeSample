<?php

namespace App\Enums;

use LogicException;

enum UserRankType: string
{
    case peasant = 'peasant';
    case squire = 'squire';
    case novice = 'novice';
    case gladiator = 'gladiator';
    case warrior = 'warrior';
    case soldier = 'soldier';
    case guardian = 'guardian';
    case champion = 'champion';
    case hero = 'hero';
    case conqueror = 'conqueror';
    case tyrant = 'tyrant';
    case king = 'king';
    case immortal = 'immortal';
    case demigod = 'demigod';
    case god = 'god';
    case titan = 'titan';

    public function getPrevRankType(): self
    {
        return match($this) {
            self::peasant => throw new LogicException('Unexpected call'),
            self::squire => self::peasant,
            self::novice => self::squire,
            self::gladiator => self::novice,
            self::warrior => self::gladiator,
            self::soldier => self::warrior,
            self::guardian => self::soldier,
            self::champion => self::guardian,
            self::hero => self::champion,
            self::conqueror => self::hero,
            self::tyrant => self::conqueror,
            self::king => self::tyrant,
            self::immortal => self::king,
            self::demigod => self::immortal,
            self::god => self::demigod,
            self::titan => self::god,
        };
    }
    public function getNextRankType(): self
    {
        return match($this) {
            self::peasant => self::squire,
            self::squire => self::gladiator,
            self::gladiator => self::novice,
            self::novice => self::warrior,
            self::warrior => self::soldier,
            self::soldier => self::guardian,
            self::guardian => self::champion,
            self::champion => self::hero,
            self::hero => self::conqueror,
            self::conqueror => self::tyrant,
            self::tyrant => self::king,
            self::king => self::immortal,
            self::immortal => self::demigod,
            self::demigod => self::god,
            self::god => self::titan,
            self::titan => throw new LogicException('Unexpected call'),
        };
    }

    public static function calculatePvpRatingStarsNext(UserRankType $rankType): int
    {
        return match($rankType) {
            self::peasant, self::squire, self::novice, self::gladiator, self::warrior => 3,
            self::soldier, self::guardian, self::champion, self::hero, self::conqueror => 4,
            self::tyrant, self::king, self::immortal, self::demigod, self::god => 5,
            self::titan => 0,
        };
    }
    public static function getRankNumber(UserRankType $rankType): int
    {
        return match($rankType) {
            self::peasant => 15,
            self::squire => 14,
            self::gladiator => 13,
            self::novice => 12,
            self::warrior => 11,
            self::soldier => 10,
            self::guardian => 9,
            self::champion => 8,
            self::hero => 7,
            self::conqueror => 6,
            self::tyrant => 5,
            self::king => 4,
            self::immortal => 3,
            self::demigod => 2,
            self::god => 1,
            self::titan => 0,
        };
    }
}
