<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Discord\Discord;
use Monolog\Logger;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;
use function React\Promise\reject;

/**
 * This enum defines various methods for moderating text content.
 * 
 * @method static self EXACT() Matches the exact word.
 * @method static self RUSSIAN() Matches any Russian Cyrillic characters.
 * @method static self CHINESE() Matches any Chinese Han characters.
 * @method static self KOREAN() Matches any Korean Hangul characters.
 * @method static self STR_STARTS_WITH() Matches if the text starts with the specified word.
 * @method static self STR_ENDS_WITH() Matches if the text ends with the specified word.
 * @method static self STR_CONTAINS() Matches if the text contains the specified word.
 * 
 * @see https://www.regular-expressions.info/unicode.html for more information on Unicode regular expressions.
 * 
 * @method static bool matches(string $lower, array $badwords) Checks if the given text matches any of the bad words based on the specified moderation method.
 */
enum ModerationMethod: string {
    case EXACT = 'exact';
    case RUSSIAN = 'russian';
    case CHINESE = 'chinese';
    case KOREAN = 'korean';
    //case UNICODE = 'unicode';
    case STR_STARTS_WITH = 'str_starts_with';
    case STR_ENDS_WITH = 'str_ends_with';
    case STR_CONTAINS = 'str_contains';

    public static function matches(string $lower, array $badwords): bool {
        $method = $badwords['method'] ?? self::STR_CONTAINS;
        try { $moderationMethod = self::from($method);
        } catch (\UnhandledMatchError $e) { $moderationMethod = self::STR_CONTAINS; }
        return match ($moderationMethod) {
            self::EXACT => preg_match('/\b' . preg_quote($badwords['word'], '/') . '\b/i', $lower),
            self::RUSSIAN => preg_match('/\p{Cyrillic}/u', $lower),
            self::CHINESE => preg_match('/\p{Han}/u', $lower),
            self::KOREAN => preg_match('/\p{Hangul}/u', $lower),
            self::STR_STARTS_WITH => str_starts_with($lower, $badwords['word']),
            self::STR_ENDS_WITH => str_ends_with($lower, $badwords['word']),
            self::STR_CONTAINS => str_contains($lower, $badwords['word']),
            // default => str_contains($lower, $badwords['word']), // Redundant
        };
    }
}

class Moderator
{
    public Civ13 $civ13;
    public Discord $discord;
    public Logger $logger;
    public array $timers = [];
    public string $status = 'status.txt';
    public bool $ready = false;

    public function __construct(Civ13 $civ13)
    {
        $this->civ13 =& $civ13;
        $this->discord =& $civ13->discord;
        $this->logger =& $civ13->logger;
        $this->afterConstruct();
    }
    private function afterConstruct(): void
    {
        $this->civ13->ready
            ? $this->setup()
            : $this->discord->once('init', fn() => $this->setup());
    }
    public function setup(): PromiseInterface
    {
        if ($this->ready) return reject(new \LogicException('Moderator already setup'));
        $this->civ13->moderator =& $this;
        $this->logger->info("Added Moderator");
        $this->ready = true;
        return resolve(null);
    }

    /**
     * Scrutinizes the given ckey and applies ban rules if necessary.
     *
     * @param string $ckey The ckey to be scrutinized.
     * @return void
     */
    public function scrutinizeCkey(string $ckey): void
    { // Suspicious user ban rules
        if (! isset($this->civ13->permitted[$ckey]) && ! in_array($ckey, $this->civ13->seen_players)) {
            $this->civ13->seen_players[] = $ckey;
            $ckeyinfo = $this->civ13->ckeyinfo($ckey);
            $ban = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->civ13->discord_formatted}"];
            if ($ckeyinfo['altbanned']) { // Banned with a different ckey
                if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true) . ' (Alt Banned)');
            } else foreach ($ckeyinfo['ips'] as $ip) {
                $ip_data = $this->civ13->getIpData($ip);
                /* We should only check new connections
                if (isset($ip_data['proxy']) && $ip_data['proxy']) { // Proxy
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true) . ' (Proxy)');
                    break;
                }
                if (isset($ip_data['hosting']) && $ip_data['hosting']) {
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true) . ' (Hosting)');
                    break;
                }*/
                if (isset($ip_data['country']) && in_array($ip_data['country'] ?? 'unknown', $this->civ13->blacklisted_countries)) { // Country code
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true) . ' (Blacklisted Country)');
                    break;
                }
                foreach ($this->civ13->blacklisted_regions as $region) if (str_starts_with($ip, $region)) { // IP Segments
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true) . ' (Blacklisted Region)');
                    break 2;
                }
            }
        }
        if ($this->civ13->verifier->get('ss13', $ckey)) return; // Verified users are exempt from further checks
        if (! isset($this->civ13->permitted[$ckey]) && ! isset($this->civ13->ages[$ckey]) && ! $this->civ13->checkByondAge($age = $this->civ13->getByondAge($ckey))) { // Force new accounts to register in Discord
            $ban = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Byond account `$ckey` must register and be approved to play. ($age) Verify at {$this->civ13->discord_formatted}"];
            if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true));
        }
    }

    /**
     * Moderates game chat by checking for blacklisted words/phrases and taking appropriate actions.
     *
     * @param string $ckey The ckey of the player sending the chat message.
     * @param string $string The chat message string to be moderated.
     * @param array $badwords_array An array of blacklisted words/phrases and their moderation methods.
     * @param array &$badword_warnings An array to store any warnings generated by the moderation process.
     * @param string $server The server where the chat message is being sent.
     * @return string The original chat message string.
     */
    public function moderate(Gameserver $gameserver, string $ckey, string $string, array $badwords_array, array &$badword_warnings): bool
    {
        $lower = strtolower($string);
        //$this->logger->debug("[MODERATE] ckey = `$ckey`, string = `$string`, lower = `$lower`");
        $seenCategories = [];
        $infractions = array_filter($badwords_array, function($badwords) use ($lower, &$seenCategories) {
            if ($badwords['category'] && ! isset($seenCategories[$badwords['category']]) && ModerationMethod::matches($lower, $badwords)) {
                $seenCategories[$badwords['category']] = true;
                return true;
            }
            return false;
        });
        //$this->logger->debug(empty($seenCategories) ? 'No infractions' : 'Infractions found');
        foreach ($infractions as $badwords_arr) $this->__relayViolation($gameserver, $ckey, $badwords_arr, $badword_warnings);
        return ! empty($seenCategories);
    }
    /**
     * This function is called from the game's chat hook if a player says something that contains a blacklisted word.
     *
     * @param string $server The server.
     * @param string $ckey The player's unique identifier.
     * @param array $badwords_array An array containing information about the blacklisted word.
     * @param array &$badword_warnings A reference to an array that stores the number of warnings for each player.
     * @return string|false The warning message or false if the player should not be warned or banned.
     */
    private function __relayViolation(Gameserver $gameserver, string $ckey, array $badwords_array, array &$badword_warnings): string|false
    {
        if (Civ13::sanitizeInput($ckey) === Civ13::sanitizeInput($this->discord->username)) return false; // Don't ban or alert staff for the bot

        // Notify staff in Discord about the violation
        $filtered = substr($badwords_array['word'], 0, 1) . str_repeat('%', strlen($badwords_array['word'])-2) . substr($badwords_array['word'], -1, 1);
        $warning = "You are currently violating a server rule. Further violations will result in an automatic ban that will need to be appealed on our Discord. Review the rules at {$this->civ13->rules}. Reason: {$badwords_array['reason']} ({$badwords_array['category']} => $filtered)";
        if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, "`$ckey` is" . substr($warning, 7));

        if (isset($this->civ13->verifier))
            if ($guild = $this->discord->guilds->get('id', $this->civ13->civ13_guild_id))
                if ($item = $this->civ13->verifier->get('ss13', $ckey))
                    if (isset($item['discord']) && $member = $guild->members->get('id', $item['discord']))    
                        if ($member->roles->has($this->civ13->role_ids['Admin']))
                            return false; // Don't ban an admin

        if (! $this->__relayWarningCounter($ckey, $badwords_array, $badword_warnings)) return $this->civ13->ban(['ckey' => $ckey, 'duration' => $badwords_array['duration'], 'reason' => "Blacklisted phrase ($filtered). Review the rules at {$this->civ13->rules}. Appeal at {$this->civ13->discord_formatted}"]);
        $gameserver->DirectMessage($warning, $this->discord->username, $ckey);
        return $warning;
    }
    /*
     * This function determines if a player has been warned too many times for a specific category of bad words
     * If they have, it will return false to indicate they should be banned
     * If they have not, it will return true to indicate they should be warned
     */
    private function __relayWarningCounter(string $ckey, array $badwords_array, array &$badword_warnings): bool
    {
        $badword_warnings[$ckey][$badwords_array['category']] = ($badword_warnings[$ckey][$badwords_array['category']] ?? 0) + 1;

        if ($filename = $badword_warnings === $this->civ13->ic_badwords_warnings
            ? 'ic_badwords_warnings.json'
            : ($badword_warnings === $this->civ13->ooc_badwords_warnings
                ? 'ooc_badwords_warnings.json'
                : null)
        ) $this->civ13->VarSave($filename, $badword_warnings);

        return $badword_warnings[$ckey][$badwords_array['category']] <= $badwords_array['warnings'];
    }
}