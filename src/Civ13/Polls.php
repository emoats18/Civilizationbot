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
     * @param  Discord                  $discord The Discord instance.
     * @param  string                   $type    The type of the poll.
     * @return PromiseInterface<Poll>            A promise that resolves to the poll instance.
     * @throws \Exception                        If the poll type is invalid.
     */
    public static function getPoll(Discord $discord, string $type): PromiseInterface
    {
        $type = strtoupper($type);
        if (! isset(self::listPolls()[$type])) return reject(new \Exception("Invalid poll type `$type`. Available polls: " . implode(', ', array_keys(self::listPolls()))));
        return resolve(
            (new Poll($discord))
                ->setQuestion(         constant('self::' . $type . '_QUESTION')         ) // The question of the poll. Only text is supported
                ->setAnswers(          constant('self::' . $type . '_ANSWERS')          ) // Each of the answers available in the poll, up to 10
                ->setAllowMultiselect( constant('self::' . $type . '_ALLOW_MULTISELECT')) // Whether a user can select multiple answers
                ->setDuration(         constant('self::' . $type . '_DURATION')         ) // Number of hours the poll should be open for, up to 32 days. Defaults to 24
        );
    }

    /**
     * Lists all poll questions by extracting the constant names that end with '_QUESTION'.
     *
     * This method uses reflection to get all constants defined in the class,
     * filters them to include only those whose names end with '_QUESTION',
     * and then maps these names to remove the '_QUESTION' suffix.
     * The resulting array is then flipped, making the original names the values.
     *
     * @return array An associative array where the keys are the poll names without the '_QUESTION' suffix.
     */
    public static function listPolls(): array
    {        
        return array_flip(array_map(fn($name) => substr($name, 0, -9), array_filter(array_keys((new \ReflectionClass(__CLASS__))->getConstants()), fn($name) => str_ends_with($name, '_QUESTION'))));
    }
}