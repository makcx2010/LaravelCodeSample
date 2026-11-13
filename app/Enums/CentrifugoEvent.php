<?php

namespace App\Enums;

enum CentrifugoEvent: string
{
    case pvp_match_found = 'pvp_match_found';
    case pvp_match_canceled = 'pvp_match_canceled';
    case pvp_match_canceled_timeout = 'pvp_match_canceled_timeout';
    case pvp_match_confirmation_timeout = 'pvp_match_confirmation_timeout';
    case pvp_match_started = 'pvp_match_started';
    case pvp_match_finished = 'pvp_match_finished';
    case message_received = 'message_received';
}
