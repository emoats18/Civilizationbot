<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Discord\Discord;
use Discord\Parts\Channel\Poll\Poll;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

class Polls
{
    CONST GAMEMODE_QUESTION = 'Gamemode?';
    CONST GAMEMODE_ANSWERS = [
        'Nomads',
        'TDM',
        'RP'
    ];
    CONST GAMEMODE_ALLOW_MULTISELECT = false;
    CONST GAMEMODE_DURATION = 1;

    CONST EPOCH_QUESTION = 'Epoch?';
    CONST EPOCH_ANSWERS = [
        'Stone',
        'Bronze',
        'Medieval',
        'Imperial',
        'Industrial',
        'Modern'
    ];
    CONST EPOCH_ALLOW_MULTISELECT = false;
    CONST EPOCH_DURATION = 1;

    CONST RESEARCH_QUESTION = 'Research method?';
    CONST RESEARCH_ANSWERS = [
        'Auto',
        'Classic',
        'Locked'
    ];
    CONST RESEARCH_ALLOW_MULTISELECT = false;
    CONST RESEARCH_DURATION = 1;

    CONST MAPSWAP_QUESTION = 'Change map?';
    CONST MAPSWAP_ANSWERS = [
        'Yes',
        'No'
    ];
    CONST MAPSWAP_ALLOW_MULTISELECT = false;
    CONST MAPSWAP_DURATION = 1;

    //CONST MAP_QUESTION = 'Map?';

    /**
     * Retrieves a poll based on the specified type.
     *
     * @param Discord                   $discord The Discord instance.
     * @param string                    $type    The type of the poll.
     * @return PromiseInterface<Poll>   A promise that resolves to the poll instance.
     * @throws \Exception               If the poll type is invalid.
     */
    public static function getPoll(Discord $discord, string $type): PromiseInterface
    {
        if (! isset(self::${$type . '_QUESTION'})) return reject(new \Exception('Invalid poll type'));
        return resolve(
            (new Poll($discord))
                ->setQuestion(        self::{$type . '_QUESTION'         }) // The question of the poll. Only text is supported
                ->setAnswers(         self::{$type . '_ANSWERS'          }) // Each of the answers available in the poll, up to 10
                ->setAllowMultiselect(self::{$type . '_ALLOW_MULTISELECT'}) // Whether a user can select multiple answers
                ->setDuration(        self::{$type . '_DURATION'         }) // Number of hours the poll should be open for, up to 32 days. Defaults to 24
        );
    }
}