<?php
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Byond\Byond;
use Civ13\Exceptions\FileNotFoundException;
use Civ13\Exceptions\MissingSystemPermissionException;
use Discord\Discord;
use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\Member;
use Monolog\Logger;
use React\Promise\PromiseInterface;

use function React\Async\await;
use function React\Promise\resolve;
use function React\Promise\reject;

class MessageServiceManager
{
    public Civ13 $civ13;
    public Discord $discord;
    public Logger $logger;
    public MessageHandler $messageHandler;

    public function __construct(Civ13 &$civ13) {
        $this->civ13 =& $civ13;
        $this->discord =& $civ13->discord;
        $this->logger =& $civ13->logger;
        $this->messageHandler = new MessageHandler($this->civ13);
        $this->__afterConstruct();
    }

    public function __afterConstruct()
    {
        $this->__generateGlobalMessageCommands();
        $this->logger->debug('[CHAT COMMAND LIST] ' . PHP_EOL . $this->messageHandler->generateHelp());
    }

    public function handle(Message $message): ?PromiseInterface
    {
        $message_array = $this->civ13->filterMessage($message);
        if (! $message_array['called']) return null; // Bot was not called
        if ($return = $this->messageHandler->handle($message)) return $return;
        if (! $message_array['message_content_lower']) { // No command given
            $random_responses = ['You can see a full list of commands by using the `help` command.'];
            $random_responses = [
                'You can see a full list of commands by using the `help` command.',
                'I\'m sorry, I can\'t do that, Dave.',
                '404 Error: Humor not found.',
                'Hmm, looks like someone called me to just enjoy my company.',
                'Seems like I\'ve been summoned!',
                'I see you\'ve summoned the almighty ' . ($this->discord->username ?? $this->discord->username) . ', ready to dazzle you with... absolutely nothing!',
                'Ah, the sweet sound of my name being called!',
                'I\'m here, reporting for duty!',
                'Greetings, human! It appears you\'ve summoned me to bask in my digital presence.',
                'You rang? Or was that just a pocket dial in the digital realm?',
                'Ah, the classic call and no command combo!',
                'I\'m here, at your service!',
                'You\'ve beckoned, and here I am!'
            ];
            if (count($random_responses) > 0) return $this->civ13->reply($message, $random_responses[rand(0, count($random_responses)-1)]);
        }
        if ($message_array['message_content_lower'] === 'dev') // This is a special case for the developer to test things
            if (isset($this->civ13->technician_id) && isset($this->civ13->role_ids['Chief Technical Officer']))
                if ($message->user_id === $this->civ13->technician_id)
                    return $message->member->addRole($this->civ13->role_ids['Chief Technical Officer']);
        return null;
    }

    /*
     * The generated functions include `ping`, `help`, `cpu`, `approveme`, and `insult`.
     * The `ping` function replies with "Pong!" when called.
     * The `help` function generates a list of available commands based on the user's roles.
     * The `cpu` function returns the CPU usage of the system.
     * The `approveme` function verifies a user's identity and assigns them the `Verified` role.
     * And more! (see the code for more details)
     */
    private function __generateGlobalMessageCommands(): void
    {
        $this->messageHandler
            ->offsetSet('stop',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $message->react("🛑")->then(fn() => $this->civ13->stop()),
                ['Owner', 'Chief Technical Officer'])    
            ->offsetSet('restart',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>                
                    $message->react("👍")->then(function () {
                        if (isset($this->civ13->restart_message)) return $this->civ13->restart_message->edit(MessageBuilder::new()->setContent('Manually Restarting...'))->then(fn() => $this->civ13->restart());
                        elseif (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) return $this->civ13->sendMessage($channel, 'Manually Restarting...')->then(fn() => $this->civ13->restart());
                        return $this->civ13->restart();
                    }),
                ['Owner', 'Chief Technical Officer'])
            ->offsetSet('ping',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->reply($message, 'Pong!'))
            ->offsetSets(['help', 'commands'],
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->reply($message, $this->messageHandler->generateHelp($message->member->roles), 'help.txt', true))
            ->offsetSet('stats',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $message->reply(MessageBuilder::new()->setContent('Civ13 Stats')->addEmbed($this->civ13->stats->handle()->setFooter($this->civ13->embed_footer))),
                ['Owner', 'Chief Technical Officer'])
            ->offsetSet('httphelp',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->reply($message, $this->civ13->httpServiceManager->httpHandler->generateHelp(), 'httphelp.txt', true),
                ['Owner', 'Chief Technical Officer'])
            ->offsetSet('cpu',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->reply($message,$this->civ13->CPU()),
                ['Verified'])
            ->offsetSet('checkip',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->reply($message, @file_get_contents('http://ipecho.net/plain', false, stream_context_create(['http' => ['connect_timeout' => 5]]))),
                ['Verified'])
            ->offsetSet('bancheck_centcom',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Wrong format. Please try `bancheck [ckey]`.');
                    if (is_numeric($ckey)) {
                        if (! $item = $this->civ13->verifier->get('discord', $ckey)) return $this->civ13->reply($message, "No ckey found for Discord ID `$ckey`.");
                        $ckey = $item['ss13'];
                    }
                    if (! $json = Byond::bansearch_centcom($ckey)) return $this->civ13->reply($message, "Unable to locate bans for **$ckey** on centcom.melonmesa.com.");
                    if ($json === '[]') return $this->civ13->reply($message, "No bans were found for **$ckey** on centcom.melonmesa.com.");
                    return $this->civ13->reply($message, $json, $ckey.'_bans.json', true);
                }, ['Verified'])
            ->offsetSet('bancheck',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Wrong format. Please try `bancheck [ckey]`.');
                    if (is_numeric($ckey)) {
                        if (! $item = $this->civ13->verifier->get('discord', $ckey)) return $this->civ13->reply($message, "No ckey found for Discord ID `$ckey`.");
                        $ckey = $item['ss13'];
                    }
                    $reason = 'unknown';
                    $found = false;
                    $content = '';
                    foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                        if (! touch ($gameserver->basedir . Civ13::bans) || ! $file = @fopen($gameserver->basedir . Civ13::bans, 'r')) {
                            $this->logger->warning('Could not open `' . $gameserver->basedir . Civ13::bans . "` for reading.");
                            return $message->react("🔥");
                        }
                        while (($fp = fgets($file, 4096)) !== false) {
                            $linesplit = explode(';', trim(str_replace('|||', '', $fp))); // $split_ckey[0] is the ckey
                            if ((count($linesplit)>=8) && ($linesplit[8] === strtolower($ckey))) {
                                $found = true;
                                $type = $linesplit[0];
                                $reason = $linesplit[3];
                                $admin = $linesplit[4];
                                $date = $linesplit[5];
                                $content .= "**$ckey** has been **$type** banned from **{$gameserver->name}** on **$date** for **$reason** by $admin." . PHP_EOL;
                            }
                        }
                        fclose($file);
                    }
                    if (! $found) $content .= "No bans were found for **$ckey**." . PHP_EOL;
                    elseif (isset($this->civ13->role_ids['Banished']) && $member = $this->civ13->verifier->getVerifiedMember($ckey))
                        if (! $member->roles->has($this->civ13->role_ids['Banished']))
                            $member->addRole($this->civ13->role_ids['Banished']);
                    return $this->civ13->reply($message, $content, 'bancheck.txt');
                }, ['Verified'])
            /**
             * This method retrieves information about a ckey, including primary identifiers, IPs, CIDs, and dates.
             * It also iterates through playerlogs ban logs to find all known ckeys, IPs, and CIDs.
             * If the user has Ambassador privileges, it also displays primary IPs and CIDs.
             * @param Message $message The message object.
             * @param array $message_filtered The filtered message content.
             * @param string $command The command used to trigger this method.
             * @return PromiseInterface<Message> The message response.
             */
            
            ->offsetSet('getround',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    if (! $input = trim(substr($message_filtered['message_content'], strlen($command)))) return $this->civ13->reply($message, 'Invalid format! Please use the format: getround `game_id`');
                    $input = explode(' ', $input);
                    $rounds = [];
                    foreach ($this->civ13->enabled_gameservers as $gameserver) if ($round = $gameserver->getRound($game_id = $input[0])) {
                        $round['server_key'] = $gameserver->key;
                        $rounds[$gameserver->name] = $round;
                    }
                    if (! $rounds) return $this->civ13->reply($message, 'No data found for that round.');
                    $ckey = isset($input[1]) ? Civ13::sanitizeInput($input[1]) : null;
                    $high_staff = $this->civ13->hasRank($message->member, ['Owner', 'Chief Technical Officer', 'Ambassador']);
                    $staff = $this->civ13->hasRank($message->member, ['Admin']);
                    $builder = MessageBuilder::new()->setContent("Round data for game_id `$game_id`" . ($ckey ? " (ckey: `$ckey`)" : ''));
                    foreach ($rounds as $server => $r) {
                        if ($log = $r['log'] ?? '') $log = str_replace('/', ';', "logs {$r['server_key']}$log");
                        $embed = $this->civ13->createEmbed()
                            ->setTitle($server)
                            //->addFieldValues('Game ID', $game_id);
                            ->addFieldValues('Start', $r['start'] ?? 'Unknown', true)
                            ->addFieldValues('End', $r['end'] ?? 'Ongoing/Unknown', true);
                        if (($players = implode(', ', array_keys($r['players']))) && strlen($players) <= 1024) $embed->addFieldValues('Players (' . count($r['players']) . ')', $players);
                        else $embed->addFieldValues('Players (' . count($r['players']) . ')', 'Either none or too many to list!');
                        if ($discord_ids = array_filter(array_map(fn($c) => ($item = $this->civ13->verifier->get('ss13', $c)) ? "<@{$item['discord']}>" : null, array_keys($r['players'])))) {
                            if (strlen($verified_players = implode(', ', $discord_ids)) <= 1024) $embed->addFieldValues('Verified Players (' . count($discord_ids) . ')', $verified_players);
                            else $embed->addFieldValues('Verified Players (' . count($discord_ids) . ')', 'Too many to list!');
                        }
                        if ($ckey && $player = $r['players'][$ckey]) {
                            $player['ip'] ??= [];
                            $player['cid'] ??= [];
                            $ip = $high_staff ? implode(', ', $player['ip']) : 'Redacted';
                            $cid = $high_staff ? implode(', ', $player['cid']): 'Redacted';
                            $login = $player['login'] ?? 'Unknown';
                            $logout = $player['logout'] ?? 'Unknown';
                            $embed->addFieldValues("Player Data ($ckey)", "IP: $ip" . PHP_EOL . "CID: $cid" . PHP_EOL . "Login: $login" . PHP_EOL . "Logout: $logout");
                        }
                        if ($staff) $embed->addFieldValues('Bot Logging Interrupted', $r['interrupted'] ? 'Yes' : 'No', true)->addFieldValues('Log Command', $log ?? 'Unknown', true);
                        
                        
                        $interaction_log_handler = function (Interaction $interaction, string $command): PromiseInterface
                        {
                            if (! $interaction->member->roles->has($this->civ13->role_ids['Admin'])) return $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent('You do not have permission to use this command.'), true);
                            $tokens = explode(';', substr($command, strlen('logs ')));
                            $keys = [];
                            foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                                $keys[] = $gameserver->key;
                                if (trim($tokens[0]) !== $gameserver->key) continue; // Check if server is valid
                                if (! isset($gameserver->basedir) || ! file_exists($gameserver->basedir . Civ13::log_basedir)) {
                                    $this->logger->warning($error = "Either basedir or `" . Civ13::log_basedir . "` is not defined or does not exist");
                                    return $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent($error));
                                }

                                unset($tokens[0]);
                                $results = $this->civ13->FileNav($gameserver->basedir . Civ13::log_basedir, $tokens);
                                if ($results[0]) return $interaction->sendFollowUpMessage(MessageBuilder::new()->addFile($results[1], 'log.txt'), true);
                                if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
                                if (! isset($results[2]) || ! $results[2]) return $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent('Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`'));
                                return $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent("{$results[2]} is not an available option! Available options: " . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`'));
                            }
                            return $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent('Please use the format `logs {server}`. Valid servers: `' . implode(', ', $keys) . '`'));
                        };
                        if ($staff) $builder->addComponent(
                            ActionRow::new()->addComponent(
                                Button::new(Button::STYLE_PRIMARY, $log)
                                    ->setLabel('Log')
                                    ->setEmoji('📝')
                                    ->setListener(fn($interaction) => $interaction->acknowledge()->then(fn() => $interaction_log_handler($interaction, $interaction->data['custom_id'])), $this->discord, $oneOff = true)
                            )
                        );
                    }
                    return $message->reply($builder->addEmbed($embed)->setAllowedMentions(['parse' => []]));
                }, ['Verified'])
            ->offsetSet('discord2ckey',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    ($item = $this->civ13->verifier->get('discord', $id = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))))
                        ? $this->civ13->reply($message, "`$id` is registered to `{$item['ss13']}`")
                        : $this->civ13->reply($message, "`$id` is not registered to any byond username"),
                ['Verified'])
            ->offsetSet('ckeyinfo',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    if (! $id = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Invalid format! Please use the format: ckeyinfo `ckey`');
                    if (! ($item = $this->civ13->verifier->getVerifiedItem($id) ?? []) && is_numeric($id)) return $this->civ13->reply($message, "No data found for Discord ID `$id`.");
                    if (! $ckey = $item['ss13'] ?? $id) return $this->civ13->reply($message, "Invalid ckey `$ckey`.");
                    if (! $collectionsArray = $this->civ13->getCkeyLogCollections($ckey)) return $this->civ13->reply($message, "No data found for ckey `$ckey`.");

                    /** @var string[] */
                    $ckeys = [$ckey];
                    $ips = [];
                    $cids = [];
                    $dates = [];
                    $ckey_age = [];
                    // Get the ckey's primary identifiers and fill in any blanks
                    foreach ($collectionsArray as $item) foreach ($item as $log) {
                        if (isset($log['ip'])   && ! isset($ips[$log['ip']]))     $ips[$log['ip']]     = $log['ip'];
                        if (isset($log['cid'])  && ! isset($cids[$log['cid']]))   $cids[$log['cid']]   = $log['cid'];
                        if (isset($log['date']) && ! isset($dates[$log['date']])) $dates[$log['date']] = $log['date'];
                    }

                    $builder = MessageBuilder::new();
                    $embed = $this->civ13->createEmbed()->setTitle($ckey);
                    if ($ckeys) {
                        foreach ($ckeys as $c) ($age = $this->civ13->getByondAge($c)) ? $ckey_age[$c] = $age : $ckey_age[$c] = "N/A";
                        $ckey_age_string = implode(', ', array_map(fn($key, $value) => "$key ($value)", array_keys($ckey_age), $ckey_age));
                        if (strlen($ckey_age_string) > 1 && strlen($ckey_age_string) <= 1024) $embed->addFieldValues('Primary Ckeys', $ckey_age_string);
                        elseif (strlen($ckey_age_string) > 1024) $builder->addFileFromContent('primary_ckeys.txt', $ckey_age_string);
                    }
                    if ($item && isset($item['ss13']) && $user = $this->civ13->verifier->getVerifiedUser($item['ss13']))
                        $embed->setAuthor("{$user->username} ({$user->id})", $user->avatar);
                    if ($high_staff = $this->civ13->hasRank($message->member, ['Owner', 'Chief Technical Officer', 'Ambassador'])) {
                        $ips_string = implode(', ', $ips);
                        $cids_string = implode(', ', $cids);
                        if (strlen($ips_string) > 1 && strlen($ips_string) <= 1024) $embed->addFieldValues('Primary IPs', $ips_string, true);
                        elseif (strlen($ips_string) > 1024) $builder->addFileFromContent('primary_ips.txt', $ips_string);
                        if (strlen($cids_string) > 1 && strlen($cids_string) <= 1024) $embed->addFieldValues('Primary CIDs', $cids_string, true);
                        elseif (strlen($cids_string) > 1024) $builder->addFileFromContent('primary_cids.txt', $cids_string);
                    }
                    if ($dates && strlen($dates_string = implode(', ', $dates)) <= 1024) $embed->addFieldValues('First Seen Dates', $dates_string);

                    $found_ckeys = [];
                    $found_ips = [];
                    $found_cids = [];
                    $found_dates = [];
                    Civ13::updateCkeyinfoVariables($this->civ13->playerlogsToCollection(), $ckeys, $ips, $cids, $dates, $found_ckeys, $found_ips, $found_cids, $found_dates, true);
                    Civ13::updateCkeyinfoVariables($this->civ13->bansToCollection(), $ckeys, $ips, $cids, $dates, $found_ckeys, $found_ips, $found_cids, $found_dates, false);

                    if ($ckeys) {
                        if ($ckey_age_string = implode(', ', array_map(fn($c) => "$c (" . ($ckey_age[$c] ?? ($this->civ13->getByondAge($c) !== false ? $this->civ13->getByondAge($c) : "N/A")) . ")", $ckeys))) {
                            if (strlen($ckey_age_string) > 1 && strlen($ckey_age_string) <= 1024) $embed->addFieldValues('Matched Ckeys', trim($ckey_age_string));
                            elseif (strlen($ckey_age_string) > 1025) $builder->addFileFromContent('matched_ckeys.txt', $ckey_age_string);
                        }
                    }
                    if ($high_staff) {
                        if ($ips && ($matched_ips_string = implode(', ', $ips)) !== $ips_string) {
                            if (strlen($matched_ips_string) > 1 && strlen($matched_ips_string) <= 1024) $embed->addFieldValues('Matched IPs', $matched_ips_string, true);
                            elseif (strlen($matched_ips_string) > 1024) $builder->addFileFromContent('matched_ips.txt', $matched_ips_string);
                        }
                        if ($cids && ($matched_cids_string = implode(', ', $cids)) !== $cids_string) {
                            if (strlen($matched_cids_string) > 1 && strlen($cids_string) <= 1024) $embed->addFieldValues('Matched CIDs', $cids_string, true);
                            elseif (strlen($matched_cids_string) > 1024) $builder->addFileFromContent('matched_cids.txt', $cids_string);
                        }
                    } else $builder->setContent('IPs and CIDs have been hidden for privacy reasons.');
                    if ($ips && $regions_string = implode(', ', array_unique(array_map(fn($ip) => $this->civ13->getIpData($ip)['countryCode'] ?? 'unknown', $ips)))) {
                        if (strlen($regions_string) > 1 && strlen($regions_string) <= 1024) $embed->addFieldValues('Regions', $regions_string, true);
                        elseif (strlen($regions_string) > 1024) $builder->addFileFromContent('regions.txt', $regions_string);
                    }
                    if ($dates && ($matched_dates_string = implode(', ', $dates)) !== $dates_string) {
                        if (strlen($matched_dates_string) > 1 && strlen($matched_dates_string) <= 1024) $embed->addFieldValues('Matched Dates', $matched_dates_string, true);
                        elseif (strlen($matched_dates_string) > 1024) $builder->addFileFromContent('matched_dates.txt', $matched_dates_string);
                    }
                    $embed->addfieldValues('Verified', $this->civ13->verifier->get('ss13', $ckey) ? 'Yes' : 'No', true);
                    if ($discord_string = implode(', ', array_filter(array_map(fn(string $c) => ($result = $this->civ13->verifier->get('ss13', $c)) ? "<@{$result['discord']}>" : null, $ckeys)))) {
                        if (strlen($discord_string) > 1 && strlen($discord_string) <= 1024) $embed->addFieldValues('Discord', $discord_string, true);
                        elseif (strlen($discord_string) > 1024) $builder->addFileFromContent('discord.txt', $discord_string);                
                    }
                    $embed->addfieldValues('Currently Banned', $this->civ13->bancheck($ckey) ? 'Yes' : 'No', true);
                    $embed->addfieldValues('Alt Banned', $this->civ13->altbancheck($found_ckeys, $ckey) ? 'Yes' : 'No', true);
                    $embed->addfieldValues('Ignoring banned alts or new account age', isset($this->civ13->permitted[$ckey]) ? 'Yes' : 'No', true);
                    return $message->reply($builder->addEmbed($embed));
                }, ['Admin'])
            ->offsetSet('ckey2discord',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    ($item = $this->civ13->verifier->get('ss13', $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))))
                        ? $this->civ13->reply($message, "`$ckey` is registered to <@{$item['discord']}>")
                        : $this->civ13->reply($message, "`$ckey` is not registered to any discord id"),
                ['Verified'])
            ->offsetSet('ckey',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    //if (str_starts_with($message_filtered['message_content_lower'], 'ckeyinfo')) return null; // This shouldn't happen, but just in case...
                    if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) {
                        if (! $item = $this->civ13->verifier->getVerifiedItem($ckey = $message->user_id)) return $this->civ13->reply($message, "You are not registered to any byond username");
                        return $this->civ13->reply($message, "You are registered to `{$item['ss13']}`");
                    }
                    if (is_numeric($ckey)) {
                        if (! $item = $this->civ13->verifier->getVerifiedItem($ckey)) return $this->civ13->reply($message, "`$ckey` is not registered to any ckey");
                        if (! $age = $this->civ13->getByondAge($item['ss13'])) return $this->civ13->reply($message, "`{$item['ss13']}` does not exist");
                        return $this->civ13->reply($message, "`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
                    }
                    if (! $age = $this->civ13->getByondAge($ckey)) return $this->civ13->reply($message, "`$ckey` does not exist");
                    if ($item = $this->civ13->verifier->getVerifiedItem($ckey)) return $this->civ13->reply($message, "`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
                    return $this->civ13->reply($message, "`$ckey` is not registered to any discord id ($age)");
                }, ['Verified'])
                ->offsetSet('ooc',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    $message_filtered['message_content'] = trim(substr($message_filtered['message_content'], trim(strlen($command))));
                    foreach ($this->civ13->enabled_gameservers as &$gameserver) switch (strtolower($message->channel->name)) {
                        case "ooc-{$gameserver->key}":                    
                            if ($gameserver->OOCMessage($message_filtered['message_content'], $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $message->author->username)) return $message->react("📧");
                            return $message->react("🔥");
                    }
                    return $this->civ13->reply($message, 'You need to be in any of the #ooc channels to use this command.');
                }, ['Verified'])
            ->offsetSet('asay',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    $message_filtered['message_content'] = trim(substr($message_filtered['message_content'], trim(strlen($command))));
                    foreach ($this->civ13->enabled_gameservers as $server) {
                        switch (strtolower($message->channel->name)) {
                            case "asay-{$server->key}":
                                if ($this->civ13->AdminMessage($message_filtered['message_content'], $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $message->author->username, $server->key)) return $message->react("📧");
                                return $message->react("🔥");
                        }
                    }
                    return $this->civ13->reply($message, 'You need to be in any of the #asay channels to use this command.');
                }, ['Verified'])
            ->offsetSets(['dm', 'pm'],
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    if (! str_contains($message_filtered['message_content'], ';')) return $this->civ13->reply($message, 'Invalid format! Please use the format `dm [ckey]; [message]`.');
                    $explode = explode(';', $message_filtered['message_content']);
                    $recipient = Civ13::sanitizeInput(substr(array_shift($explode), strlen($command)));
                    $msg = implode(' ', $explode);
                    foreach ($this->civ13->enabled_gameservers as $server) {
                        switch (strtolower($message->channel->name)) {
                            case "asay-{$server->key}":
                            case "ic-{$server->key}":
                            case "ooc-{$server->key}":
                                if ($this->civ13->DirectMessage($msg, $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $message->author->username, $recipient, $server->key)) return $message->react("📧");
                                return $message->react("🔥");
                        }
                    }
                    return $this->civ13->reply($message, 'You need to be in any of the #ic, #asay, or #ooc channels to use this command.');
                }, ['Admin'])
            ->offsetSet('globalooc',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->OOCMessage(trim(substr($message_filtered['message_content'], trim(strlen($command)))), $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $message->author->username)
                        ? $message->react("📧")
                        : $message->react("🔥"),
                ['Admin'])
            ->offsetSet('globalasay',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->AdminMessage(trim(substr($message_filtered['message_content'], trim(strlen($command)))), $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $message->author->username)
                        ? $message->react("📧")
                        : $message->react("🔥"),
                ['Admin'])
            ->offsetSet('permit',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    if (! Byond::isValidCkey($ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->civ13->reply($message, "Byond username `$ckey` does not exist.");
                    $this->civ13->permitCkey($ckey, strlen($command));
                    return $this->civ13->reply($message, "Byond username `$ckey` is now permitted to bypass the Byond account restrictions.");
                }, ['Admin'])
            ->offsetSets(['unpermit', 'revoke'],
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    $this->civ13->permitCkey($ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))), false);
                    return $this->civ13->reply($message, "Byond username `$ckey` is no longer permitted to bypass the Byond account restrictions.");
                }, ['Admin'])
            ->offsetSet('permitted',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    empty($this->civ13->permitted)
                        ? $this->civ13->reply($message, 'No users have been permitted to bypass the Byond account restrictions.')
                        : $this->civ13->reply($message, 'The following ckeys are now permitted to bypass the Byond account limit and restrictions: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', array_keys($this->civ13->permitted)) . '`')
                , ['Admin'], 'exact')
            ->offsetSet('refresh',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->verifier->getVerified(false) ? $message->react("👍") : $message->react("👎"),
                ['Admin'])
            ->offsetSet('discard',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Byond username was not passed. Please use the format `discard <byond username>`.');
                    $string = "`$ckey` will no longer attempt to be automatically registered.";
                    if ($item = $this->civ13->verifier->provisional->get('ss13', $ckey)) {
                        if ($member = $message->guild->members->get('id', $item['discord'])) {
                            $member->removeRole($this->civ13->role_ids['Verified']);
                            $string .= " The <@&{$this->civ13->role_ids['Verified']}> role has been removed from $member.";
                        }
                        $this->civ13->verifier->provisional->pull($ckey);
                        $this->civ13->VarSave('provisional.json', $this->civ13->verifier->provisional->toArray());
                    }
                    return $this->civ13->reply($message, $string);
                }, ['Admin'])
            ->offsetSet('listbans',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->listbans($message, trim(substr($message_filtered['message_content_lower'], strlen($command)))),
                ['Admin'])
            ->offsetSet('softban',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    $this->civ13->softban($id = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))));
                    return $this->civ13->reply($message, "`$id` is no longer allowed to get verified.");
                }, ['Admin'])
            ->offsetSet('unsoftban',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    $this->civ13->softban($id = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))), false);
                    return $this->civ13->reply($message, "`$id` is allowed to get verified again.");
                }, ['Admin'])
            ->offsetSet('ban',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    $message_filtered['message_content'] = substr($message_filtered['message_content'], trim(strlen($command)));
                    $split_message = explode('; ', $message_filtered['message_content']);
                    if (! $split_message[0] = Civ13::sanitizeInput($split_message[0])) return $this->civ13->reply($message, 'Missing ban ckey! Please use the format `ban ckey; duration; reason`');
                    if (! isset($this->civ13->ages[$split_message[0]]) && ! Byond::isValidCkey($split_message[0])) return $this->civ13->reply($message, "Byond username `{$split_message[0]}` does not exist.");
                    if (! isset($split_message[1]) || ! $split_message[1]) return $this->civ13->reply($message, 'Missing ban duration! Please use the format `ban ckey; duration; reason`');
                    if (! isset($split_message[2]) || ! $split_message[2]) return $this->civ13->reply($message, 'Missing ban reason! Please use the format `ban ckey; duration; reason`');
                    $arr = ['ckey' => $split_message[0], 'duration' => $split_message[1], 'reason' => $split_message[2] . " Appeal at {$this->civ13->discord_formatted}"];
                    return $this->civ13->reply($message, $this->civ13->ban($arr, $this->civ13->verifier->getVerifiedItem($message->author)['ss13']));
                }, ['Admin'])
            ->offsetSet('unban',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    if (is_numeric($ckey = Civ13::sanitizeInput($message_filtered['message_content_lower'] = substr($message_filtered['message_content_lower'], trim(strlen($command))))))
                        if (! $item = $this->civ13->verifier->getVerifiedItem($ckey)) return $this->civ13->reply($message, "No data found for Discord ID `$ckey`.");
                            else $ckey = $item['ss13'];
                    if (isset($this->civ13->verifier) && ! $message->member->roles->has($this->civ13->role_ids['Ambassador']) && ! $this->civ13->verifier->isVerified($ckey)) return $this->civ13->reply($message, "No verified data found for ID `$ckey`. Byond user must verify with `approveme` first.");
                    if (! isset($this->civ13->ages[$ckey]) && ! Byond::isValidCkey($ckey)) return $this->civ13->reply($message, "Byond username `$ckey` does not exist.");
                    $this->civ13->unban($ckey, $admin = $this->civ13->verifier->getVerifiedItem($message->author)['ss13']);
                    return $this->civ13->reply($message, "**$admin** unbanned **$ckey**");
                }, ['Admin'])
            ->offsetSet('maplist',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    (file_exists($fp = $this->civ13->gitdir . Civ13::maps) && $file_contents = @file_get_contents($fp))
                        ? $message->reply(MessageBuilder::new()->addFileFromContent('maps.txt', $file_contents))
                        : $message->react("🔥"),
                ['Admin'])
            ->offsetSet('adminlist',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $message->reply(array_reduce($this->civ13->enabled_gameservers, function ($builder, $gameserver) {
                        if (file_exists($path = $gameserver->basedir . Civ13::admins)) $builder->addFile($path, $gameserver->key . '_adminlist.txt');
                        return $builder;
                    }, MessageBuilder::new()->setContent('Admin Lists'))),
                ['Admin'])
            ->offsetSet('factionlist',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $message->reply(array_reduce($this->civ13->enabled_gameservers, function ($builder, $gameserver) {
                        if (file_exists($path = $gameserver->basedir . Civ13::factionlist)) $builder->addfile($path, $gameserver->key . '_factionlist.txt');
                        return $builder;
                    }, MessageBuilder::new()->setContent('Faction Lists'))),
                ['Admin'])
            ->offsetSet('getrounds',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    if (! $id = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Invalid format! Please use the format: getrounds `ckey`');
                    if (! $item = $this->civ13->verifier->getVerifiedItem($id)) return $this->civ13->reply($message, "No verified data found for ID `$id`.");
                    $rounds = [];
                    foreach ($this->civ13->enabled_gameservers as $gameserver) if ($r = $gameserver->getRounds([$item['ss13']])) $rounds[$gameserver->name] = $r;
                    if (! $rounds) return $this->civ13->reply($message, 'No data found for that ckey.');
                    $builder = MessageBuilder::new();
                    foreach ($rounds as $server_name => $rounds) {
                        $embed = $this->civ13->createEmbed()->setTitle($server_name)->addFieldValues('Rounds', count($rounds));
                        if ($user = $this->civ13->verifier->getVerifiedUser($item)) $embed->setAuthor("{$user->username} ({$user->id})", $user->avatar);
                        $builder->addEmbed($embed);
                    }
                    return $message->reply($builder);
                }, ['Admin'])
            ->offsetSet('tests',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    $tokens = explode(' ', trim(substr($message_filtered['message_content'], strlen($command))));
                    if (empty($tokens[0])) {
                        if (empty($this->civ13->tests)) return $this->civ13->reply($message, "No tests have been created yet! Try creating one with `tests add {test_key} {question}`");
                        $reply = 'Available tests: `' . implode('`, `', array_keys($this->civ13->tests)) . '`';
                        $reply .= PHP_EOL . 'Available commands: `list {test_key}`, `add {test_key} {question}`, `post {test_key} {question #}`, `remove {test_key} {question #}` `delete {test_key}`';
                        return $this->civ13->reply($message, $reply);
                    }
                    if (! isset($tokens[1])) return $this->civ13->reply($message, 'Invalid format! You must include the name of the test, e.g. `tests list {test_key}.');
                    if (! isset($this->civ13->tests[$test_key = strtolower($tokens[1])]) && $tokens[0] !== 'add') return $this->civ13->reply($message, "Test `$test_key` hasn't been created yet! Please add a question first.");
                    switch ($tokens[0]) {
                        case 'list':
                            return $message->reply(MessageBuilder::new()->addFileFromContent("$test_key.txt", var_export($this->civ13->tests[$test_key], true))->setContent('Number of questions: ' . count(array_keys($this->civ13->tests[$test_key]))));
                        case 'delete':
                            if (isset($tokens[2])) return $this->civ13->reply($message, "Invalid format! Please use the format `tests delete {test_key}`"); // Prevents accidental deletion of tests
                            unset($this->civ13->tests[$test_key]);
                            $this->civ13->VarSave('tests.json', $this->civ13->tests);
                            return $this->civ13->reply($message, "Deleted test `$test_key`");
                        case 'add':
                            if (! $question = implode(' ', array_slice($tokens, 2))) return $this->civ13->reply($message, 'Invalid format! Please use the format `tests add {test_key} {question}`');
                            $this->civ13->tests[$test_key][] = $question;
                            $this->civ13->VarSave('tests.json', $this->civ13->tests);
                            return $this->civ13->reply($message, "Added question to test `$test_key`: `$question`");
                        case 'remove':
                            if (!isset($tokens[2]) || !is_numeric($tokens[2])) return $this->civ13->reply($message, "Invalid format! Please use the format `tests remove {test_key} {question #}`");
                            if (!isset($this->civ13->tests[$test_key][$tokens[2]])) return $this->civ13->reply($message, "Question not found in test `$test_key`! Please use the format `tests {test_key} remove {question #}`");
                            $question = $this->civ13->tests[$test_key][$tokens[2]];
                            unset($this->civ13->tests[$test_key][$tokens[2]]);
                            $this->civ13->VarSave('tests.json', $this->civ13->tests);
                            return $this->civ13->reply($message, "Removed question `{$tokens[2]}`: `$question`");
                        case 'post':
                            if (!isset($tokens[2]) || !is_numeric($tokens[2])) return $this->civ13->reply($message, "Invalid format! Please use the format `tests post {test_key} {# of questions}`");
                            if (count($this->civ13->tests[$test_key]) < $tokens[2]) return $this->civ13->reply($message, "Can't return more questions than exist in a test!");
                            $test = $this->civ13->tests[$test_key]; // Copy the array, don't reference it
                            shuffle($test);
                            return $this->civ13->reply($message, implode(PHP_EOL, array_slice($test, 0, $tokens[2])));
                        default:
                            return $this->civ13->reply($message, 'Invalid format! Available commands: `list {test_key}`, `add {test_key} {question}`, `post {test_key} {question #}`, `remove {test_key} {question #}` `delete {test_key}`');
                    }
                }, ['Ambassador'])
            ->offsetSet('fullbancheck',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    array_map(fn($member) => ($item = $this->civ13->verifier->getVerifiedItem($member)) ? $this->civ13->bancheck($item['ss13']) : null, $message->guild->members->toArray())
                        ? $message->react("👍")
                        : $message->react("👎"),
                ['Ambassador'])
            ->offsetSet('updatebans',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface => // Attempts to fill in any missing data for the ban
                    array_reduce($this->civ13->enabled_gameservers, function ($carry, $gameserver) {
                        return $carry || array_reduce($this->civ13->enabled_gameservers, function ($carry2, $gameserver2) use ($gameserver) {
                            return $carry2 || (! await($gameserver->banlog_update(null, file_get_contents($gameserver2->basedir . Civ13::playerlogs))) instanceof \Throwable);
                        }, false);
                    }, false)
                        ? $message->react("👍")
                        : $message->react("🔥"),
                ['Ambassador'])
            ->offsetSet('cleanuplogs',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface => // Attempts to fill in any missing data for the ban
                    $message->react(array_reduce($this->civ13->enabled_gameservers, fn($carry, $gameserver) => $carry && $gameserver->cleanupLogs(), true) ? "👍" : "👎"),
                ['Ambassador'])
            ->offsetSet('fixroles',
                function (Message $message, string $command, array $message_filtered): PromiseInterface 
                {
                    if (! $guild = $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)) return $message->react("🔥");
                    if ($unverified_members = $guild->members->filter(function (Member $member) {
                        return ! $member->roles->has($this->civ13->role_ids['Verified'])
                            && ! $member->roles->has($this->civ13->role_ids['Banished'])
                            && ! $member->roles->has($this->civ13->role_ids['Permabanished']);
                    })) foreach ($unverified_members as $member) if ($this->civ13->verifier->getVerifiedItem($member)) $member->addRole($this->civ13->role_ids['Verified'], 'fixroles');
                    if (
                        $verified_members = $guild->members->filter(fn (Member $member) => $member->roles->has($this->civ13->role_ids['Verified']))
                    ) foreach ($verified_members as $member) if (! $this->civ13->verifier->getVerifiedItem($member)) $member->removeRole($this->civ13->role_ids['Verified'], 'fixroles');
                    return $message->react("👍");
                }, ['Ambassador'])
            
            ->offsetSet('panic_bunker',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->reply($message, 'Panic bunker is now ' . (($this->civ13->panic_bunker = ! $this->civ13->panic_bunker) ? 'enabled.' : 'disabled.')),
                ['Ambassador'])
            ->offsetSet('serverstatus',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    return $message->reply('Command disabled.');
                    return $message->reply(MessageBuilder::new()->setContent(implode(PHP_EOL, array_map(fn($gameserver) => "{$gameserver->name}: {$gameserver->ip}:{$gameserver->port}", $this->civ13->enabled_gameservers)))->addEmbed(array_map(fn($gameserver) => $gameserver->generateServerstatusEmbed(), $this->civ13->enabled_gameservers)));
                },
                ['Ambassador'])
            ->offsetSet('newmembers',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    // usort MIGHT be too slow if there are thousands of members. It currently resolves in less than a second with 669 members, but this is a future-proofed method.
                    resolve($message->guild->members->toArray()) // Check all members without filtering by date (it's too slow and not necessary because we're only displaying the 10 most recent members anyway)
                        ->then(function ($members) {
                            return \React\Promise\all($members);
                        })
                        ->then(function ($members) {
                            usort($members, function ($a, $b) {
                                return $b->joined_at->getTimestamp() - $a->joined_at->getTimestamp();
                            });
                            $promises = array_map(function (Member $member) {
                                return new \React\Promise\Promise(function ($resolve) use ($member) {
                                    $resolve([
                                        'username' => $member->user->username,
                                        'id' => $member->id,
                                        'join_date' => $member->joined_at->format('Y-m-d H:i:s')
                                    ]);
                                });
                            }, $members);
                            return \React\Promise\all($promises);
                        })
                        ->then(function ($sortedMembers) use ($message) {
                            $memberCount = 10; // Number of members to display
                            $mostRecentMembers = array_slice($sortedMembers, 0, $memberCount);
                            // if (count($mostRecentMembers) < $memberCount) $memberCount = count($mostRecentMembers); // If there are less than 10 members, display all of them

                            $membersData = [];
                            foreach ($mostRecentMembers as $member) {
                                $membersData[] = [
                                    'username' => $member['username'],
                                    'id' => $member['id'],
                                    'join_date' => $member['join_date']
                                ];
                            }
                            return $membersData;
                        })
                        ->then(function ($membersData) use ($message) {
                            $message->react("👍");
                            return $message->reply(MessageBuilder::new()->addFileFromContent('new_members.json', json_encode($membersData, JSON_PRETTY_PRINT)));
                        }),
                ['Ambassador'])
            ->offsetSet('fullaltcheck',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    $ckeys = [];
                    $members = $message->guild->members->filter(function (Member $member) { return ! $member->roles->has($this->civ13->role_ids['Banished']); });
                    foreach ($members as $member) if ($item = $this->civ13->verifier->getVerifiedItem($member->id)) {
                        if (!isset($item['ss13'])) continue;
                        $ckeyinfo = $this->civ13->ckeyinfo($item['ss13']);
                        if (count($ckeyinfo['ckeys']) > 1) $ckeys = array_unique(array_merge($ckeys, $ckeyinfo['ckeys']));
                    }
                    if ($ckeys) {
                        $builder = MessageBuilder::new();
                        $builder->addFileFromContent('alts.txt', '`'.implode('`' . PHP_EOL . '`', $ckeys));
                        $builder->setContent('The following ckeys are alt accounts of unbanned verified players.');
                        return $message->reply($builder);
                    }
                    return $this->civ13->reply($message, 'No alts found.');
                }, ['Ambassador'])
            /**
             * Changes the relay method between 'file' and 'webhook' and sends a message to confirm the change.
             *
             * @param Message $message The message object received from the user.
             * @param array $message_filtered An array of filtered message content.
             * @param string $command The command string.
             *
             * @return PromiseInterface
             */
            ->offsetSet('togglerelaymethod',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    if (! ($key = trim(substr($message_filtered['message_content'], strlen($command)))) || ! isset($this->civ13->enabled_gameservers[$key]) || ! $gameserver = $this->civ13->enabled_gameservers[$key]) return $this->civ13->reply($message, 'Invalid format! Please use the format `togglerelaymethod ['.implode('`, `', array_keys($this->civ13->enabled_gameservers)).']`.');
                    return $this->civ13->reply($message, 'Relay method changed to `' . (($gameserver->legacy_relay = ! $gameserver->legacy_relay) ? 'file' : 'webhook') . '`.');
                }, ['Ambassador'])
            ->offsetSet('listrounds',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    ($rounds = array_reduce($this->civ13->enabled_gameservers, function ($carry, $gameserver) {
                        if ($r = $gameserver->getRounds()) $carry[$gameserver->name] = $r;
                        return $carry;
                    }, []))
                        ? $this->civ13->reply($message, "Rounds: " . json_encode($rounds))
                        :  $this->civ13->reply($message, 'No data found.'),
                ['Ambassador'])
            ->offsetSet('playerlist',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface => // This function is only authorized to be used by the database administrator
                    (($message->user_id === $this->civ13->technician_id) && $playerlist = array_unique(array_merge(...array_map(fn($gameserver) => $gameserver->players, $this->civ13->enabled_gameservers))))
                        ? $this->civ13->reply($message, implode(', ', $playerlist))
                        : $message->react("❌"),
                ['Chief Technical Officer'])
            ->offsetSet('updateadmins',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    ($this->civ13->adminlistUpdate())
                        ? $message->react("👍")
                        : $message->react("🔥"),
                ['Ambassador'])
            ->offsetSet('pullrepo',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    (is_dir($fp = $this->civ13->gitdir) && OSFunctions::execInBackground("git -C {$fp} pull"))
                        ? $message->react("👍")
                        : $message->react("🔥"),
                ['Ambassador'])
            ->offsetSet('updatedeps',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    OSFunctions::execInBackground('composer update')
                        ? $message->react("👍")
                        : $message->react("🔥"),
                ['Ambassador'])
            ->offsetSet('unvet',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                { // Adds the infantry role to all veterans
                    if (! isset($this->civ13->role_ids['veteran']) || ! isset($this->civ13->role_ids['Verified'])) return $message->react("❌");
                    if ($message->user_id != $this->civ13->technician_id) return $message->react("❌");
                    if (! $members = $message->guild->members->filter(fn($member) =>
                        $member->roles->has($this->civ13->role_ids['veteran']) &&
                        ! $member->roles->has($this->civ13->role_ids['Verified'])
                    )) return $message->react("👎");
                    
                    $message->react("⏱️");
                    if (! $members_array = $members->toArray()) return $message->react("❌"); // No members to process
                    $promise = array_shift($members_array)->addRole($this->civ13->role_ids['Verified']);
                    if (! $members_array) return $promise->then(fn() => $message->react("👍")); // There was only one member to process
                    $promise = array_reduce($members_array, fn(PromiseInterface $carry_promise, Member $member) =>
                        $carry_promise->then(fn() => $member->addRole($this->civ13->role_ids['Verified'])),
                    $promise);
                    return $promise->then(fn() => $message->react("👍"));
                }, ['Chief Technical Officer'])
            ->offsetSet('retryregister',
                function (Message $message, string $command, array $message_filtered): PromiseInterface { // This function is only authorized to be used by the database administrator
                    if ($message->user_id !== $this->civ13->technician_id) return $message->react("❌");
                    if (! $arr = $this->civ13->verifier->provisional->toArray()) return $this->civ13->reply($message, 'No users are pending verification.');
                    return ($msg = implode(PHP_EOL, array_map(function ($item) {
                        $ckey = $item['ss13'] ?? 'Unknown';
                        $discord_id = $item['discord'] ?? 'Unknown';
                        return $this->civ13->verifier->provisionalRegistration($ckey, $discord_id)
                            ? "Successfully verified $ckey to <@{$discord_id}>"
                            : "Failed to verify $ckey to <@{$discord_id}>";
                    }, $arr))) ? $this->civ13->reply($message, $msg) : $this->civ13->reply($message, 'Unable to register provisional users.');
                },
                ['Chief Technical Officer'])
            ->offsetSet('listprovisional',
                function (Message $message, string $command, array $message_filtered): PromiseInterface {
                    if (! isset($this->civ13->verifier)) return $this->civ13->reply($message, 'Verifier is not enabled.');
                    if (! $arr = $this->civ13->verifier->provisional->toArray()) return $this->civ13->reply($message, 'No users are pending verification.');
                    return ($msg = implode(PHP_EOL, array_map(function ($item) {
                        $ckey = $item['ss13'] ?? 'Unknown';
                        $discord_id = $item['discord'] ?? 'Unknown';
                        return "$ckey: <@$discord_id>";
                    }, $arr))) ? $this->civ13->reply($message, $msg) : $message->react("❌");
                }, ['Admin'])
            ->offsetSet('register',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                { // This function is only authorized to be used by the database administrator
                    if ($message->user_id != $this->civ13->technician_id) return $message->react("❌");
                    $split_message = explode(';', trim(substr($message_filtered['message_content_lower'], strlen($command))));
                    if (! isset($split_message[1])) return $this->civ13->reply($message, 'Invalid format! Please use the format `register <byond username>; <discord id>`.');
                    if (! $ckey = Civ13::sanitizeInput($split_message[0])) return $this->civ13->reply($message, 'Byond username was not passed. Please use the format `register <byond username>; <discord id>`.');
                    if (! is_numeric($discord_id = Civ13::sanitizeInput($split_message[1]))) return $this->civ13->reply($message, "Discord id `$discord_id` must be numeric.");
                    return $this->civ13->reply($message, $this->civ13->verifier->register($ckey, $discord_id)['error']);
                }, ['Chief Technical Officer'])
            ->offsetSet('provision',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                { // This function is only authorized to be used by the database administrator
                    if ($message->user_id !== $this->civ13->technician_id) return $message->react("❌");
                    if (! isset($this->civ13->verifier)) return $this->civ13->reply($message, 'Verifier is not enabled.');
                    $split_message = explode(';', trim(substr($message_filtered['message_content_lower'], strlen($command))));
                    return $this->civ13->verifier->provision($split_message[0] ?? null, $split_message[1] ?? null)->then(
                        fn($result) => $message->react("👍")->then(fn () => $this->civ13->reply($message, $result)),
                        fn(\Throwable $error) => $message->react(($error instanceof \InvalidArgumentException) ? "❌" : "👎")->then(fn() => $this->civ13->reply($message, $error->getMessage()))
                    );
                }, ['Chief Technical Officer'])
            ->offsetSet('unverify',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                { // This function is only authorized to be used by the database administrator
                    if ($message->user_id != $this->civ13->technician_id) return $message->react("❌");
                    if (! $split_message = explode(';', trim(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->civ13->reply($message, 'Invalid format! Please use the format `register <byond username>; <discord id>`.');
                    if (! $id = Civ13::sanitizeInput($split_message[0])) return $this->civ13->reply($message, 'Please use the format `register <byond username>; <discord id>`.');
                    return $this->civ13->reply($message, $this->civ13->verifier->unverify($id)['message']);
                }, ['Chief Technical Officer'])   
            ->offsetSet('dumpappcommands',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $message->reply('Application commands: `' . implode('`, `', array_map(fn($command) => $command->getName(), $this->civ13->discord->__get('application_commands'))) . '`'),
                ['Chief Technical Officer'])            
            ->offsetSet('logs',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    $log_handler = function (Message $message, string $message_content): PromiseInterface
                    {
                        $tokens = explode(';', $message_content);
                        $keys = [];
                        foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                            $keys[] = $gameserver->key;
                            if (trim($tokens[0]) !== $gameserver->key) continue; // Check if server is valid
                            if (! isset($gameserver->basedir) || ! file_exists($gameserver->basedir . Civ13::log_basedir)) {
                                $this->logger->warning("Either basedir or `" . Civ13::log_basedir . "` is not defined or does not exist");
                                return $message->react("🔥");
                            }
            
                            unset($tokens[0]);
                            $results = $this->civ13->FileNav($gameserver->basedir . Civ13::log_basedir, $tokens);
                            if ($results[0]) return $message->reply(MessageBuilder::new()->addFile($results[1], 'log.txt'));
                            if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
                            if (! isset($results[2]) || ! $results[2]) return $this->civ13->reply($message, 'Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
                            return $this->civ13->reply($message, "{$results[2]} is not an available option! Available options: " . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
                        }
                        return $this->civ13->reply($message, 'Please use the format `logs {server}`. Valid servers: `' . implode(', ', $keys) . '`');
                    };
                    return $log_handler($message, trim(substr($message_filtered['message_content'], strlen($command))));
                },
                ['Admin'])
            ->offsetSet('playerlogs',
                function (Message $message, string $command, array $message_filtered): PromiseInterface
                {
                    $tokens = explode(';', trim(substr($message_filtered['message_content'], strlen($command))));
                    $keys = [];
                    foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                        $keys[] = $gameserver->key;
                        if (trim($tokens[0]) !== $gameserver->key) continue;
                        if (! isset($gameserver->basedir) || ! file_exists($gameserver->basedir . Civ13::playerlogs) || ! $file_contents = @file_get_contents($gameserver->basedir . Civ13::playerlogs)) return $message->react("🔥");
                        return $message->reply(MessageBuilder::new()->addFileFromContent('playerlogs.txt', $file_contents));
                    }
                    return $this->civ13->reply($message, 'Please use the format `logs {server}`. Valid servers: `' . implode(', ', $keys). '`' );
                },
                ['Admin'])
            ->offsetSet('botlog',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $message->reply(MessageBuilder::new()->addFile('botlog.txt')),
                ['Owner', 'Chief Technical Officer'])
            ->offsetSet('ip_data',
                fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                    $this->civ13->reply($message, ($input = trim(substr($message_filtered['message_content'], strlen($command)))) && ($data = $this->civ13->getIpData($input))
                        ? json_encode($data, JSON_PRETTY_PRINT)
                        : 'Invalid format or no data found. Please use the format: ip_data `ip address`'
                    ),
                ['Owner', 'Chief Technical Officer']);  
            if (isset($this->civ13->role_ids['Paroled'], $this->civ13->channel_ids['parole_logs']))
                $this->messageHandler
                    ->offsetSet('parole',
                        function (Message $message, string $command, array $message_filtered): PromiseInterface
                        {
                            if (! $item = $this->civ13->verifier->getVerifiedItem($id = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->civ13->reply($message, "<@{$id}> is not currently verified with a byond username or it does not exist in the cache yet");
                            $this->civ13->paroleCkey($ckey = $item['ss13'], $message->user_id, true);
                            $admin = $this->civ13->verifier->getVerifiedItem($message->author)['ss13'];
                            if ($member = $this->civ13->verifier->getVerifiedMember($item))
                                if (! $member->roles->has($this->civ13->role_ids['Paroled']))
                                    $member->addRole($this->civ13->role_ids['Paroled'], "`$admin` ({$message->member->displayname}) paroled `$ckey`");
                            if ($channel = $this->discord->getChannel($this->civ13->channel_ids['parole_logs'])) $this->civ13->sendMessage($channel, "`$ckey` (<@{$item['discord']}>) has been placed on parole by `$admin` (<@{$message->user_id}>).");
                            return $message->react("👍");
                        }, ['Admin'])
                    ->offsetSet('release',
                        function (Message $message, string $command, array $message_filtered): PromiseInterface
                        {
                            if (! $item = $this->civ13->verifier->getVerifiedItem($id = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->civ13->reply($message, "<`$id` is not currently verified with a byond username or it does not exist in the cache yet");
                            $this->civ13->paroleCkey($ckey = $item['ss13'], $message->user_id, false);
                            $admin = $this->civ13->verifier->getVerifiedItem($message->author)['ss13'];
                            if ($member = $this->civ13->verifier->getVerifiedMember($item))
                                if ($member->roles->has($this->civ13->role_ids['Paroled']))
                                    $member->removeRole($this->civ13->role_ids['Paroled'], "`$admin` ({$message->member->displayname}) released `$ckey`");
                            if ($channel = $this->discord->getChannel($this->civ13->channel_ids['parole_logs'])) $this->civ13->sendMessage($channel, "`$ckey` (<@{$item['discord']}>) has been released from parole by `$admin` (<@{$message->user_id}>).");
                            return $message->react("👍");
                        },
                        ['Admin'])
                ;
            if (isset($this->civ13->verifier, $this->civ13->role_ids['Verified']))
                $this->messageHandler->offsetSets(['approveme', 'aproveme', 'approvme'],
                    function (Message $message, string $command, array $message_filtered): PromiseInterface
                    {
                        if (isset($this->civ13->role_ids['Verified']) && $message->member->roles->has($this->civ13->role_ids['Verified'])) return $this->civ13->reply($message, 'You already have the verification role!');
                        if ($item = $this->civ13->verifier->getVerifiedItem($message->author)) {
                            $message->member->setRoles([$this->civ13->role_ids['Verified']], "approveme {$item['ss13']}");
                            return $message->react("👍");
                        }
                        if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Invalid format! Please use the format `approveme ckey`');
                        return $this->civ13->reply($message, $this->civ13->verifier->process($ckey, $message->user_id, $message->member));
                    });

            if (file_exists(Civ13::insults_path))
                $this->messageHandler->offsetSet('insult',
                    function (Message $message, string $command, array $message_filtered): PromiseInterface
                    {
                        if (! $insults_array = file(Civ13::insults_path, FILE_IGNORE_NEW_LINES)) return $this->civ13->reply($message, 'No insults found!');
                        if (! ($split_message = explode(' ', $message_filtered['message_content'])) || count($split_message) <= 1 || strlen($split_message[1]) === 0) $split_message[1] = "<@{$message->user_id}>"; // $split_target[1] is the target of the insult
                        return $message->channel->sendMessage(MessageBuilder::new()->setContent($split_message[1] . ', ' . $insults_array[array_rand($insults_array)])->setAllowedMentions(['parse' => []]));
                    }, ['Verified']);
            
            if (isset($this->civ13->folders['typespess_path'], $this->civ13->files['typespess_launch_server_path']))
                $this->messageHandler->offsetSet('ts',
                    function (Message $message, string $command, array $message_filtered): PromiseInterface
                    {
                        if (! $state = trim(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Wrong format. Please try `ts on` or `ts off`.');
                        if (! in_array($state, ['on', 'off'])) return $this->civ13->reply($message, 'Wrong format. Please try `ts on` or `ts off`.');
                        if ($state === 'on') {
                            OSFunctions::execInBackground("cd {$this->civ13->folders['typespess_path']}");
                            OSFunctions::execInBackground('git pull');
                            OSFunctions::execInBackground("sh {$this->civ13->files['typespess_launch_server_path']}&");
                            return $this->civ13->reply($message, 'Put **TypeSpess Civ13** test server on: http://civ13.com/ts');
                        }
                        OSFunctions::execInBackground('killall index.js');
                        return $this->civ13->reply($message, '**TypeSpess Civ13** test server down.');
                    }, ['Owner']);

            $this->__generateServerMessageCommands();
    }

    /**
     * This method generates server functions based on the server settings.
     * It loops through the server settings and generates server functions for each enabled server.
     * For each server, it generates the following message-related functions, prefixed with the server name:
     * - configexists: checks if the server configuration exists.
     * - host: starts the server host process.
     * - kill: kills the server process.
     * - restart: restarts the server process by killing and starting it again.
     * - mapswap: swaps the current map of the server with a new one.
     * - ban: bans a player from the server.
     * - unban: unbans a player from the server.
     * Also, for each server, it generates the following functions:
     * - discord2ooc: relays message to the server's OOC channel.
     * - discord2admin: relays messages to the server's admin channel.
     * 
     * @return void
     */
    private function __generateServerMessageCommands(): void
    {
        if (isset($this->civ13->enabled_gameservers['eternal'], $this->civ13->enabled_gameservers['eternal']->basedir) && file_exists($fp = $this->civ13->enabled_gameservers['eternal']->basedir . Civ13::awards))
            $this->messageHandler->offsetSet('medals',
                function (Message $message, string $command, array $message_filtered) use ($fp): PromiseInterface
                {
                    if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Wrong format. Please try `medals [ckey]`.');
                    $medals = function (string $ckey) use ($fp): false|string
                    {
                        $result = '';
                        if (! $search = @fopen($fp, 'r')) return false;
                        $found = false;
                        while (! feof($search)) if (str_contains($line = trim(str_replace(PHP_EOL, '', fgets($search))), $ckey)) {  # remove '\n' at end of line
                            $found = true;
                            $duser = explode(';', $line);
                            if ($duser[0] === $ckey) {
                                switch ($duser[2]) {
                                    case 'long service medal': $medal_s = '<:long_service:705786458874707978>'; break;
                                    case 'combat medical badge': $medal_s = '<:combat_medical_badge:706583430141444126>'; break;
                                    case 'tank destroyer silver badge': $medal_s = '<:tank_silver:705786458882965504>'; break;
                                    case 'tank destroyer gold badge': $medal_s = '<:tank_gold:705787308926042112>'; break;
                                    case 'assault badge': $medal_s = '<:assault:705786458581106772>'; break;
                                    case 'wounded badge': $medal_s = '<:wounded:705786458677706904>'; break;
                                    case 'wounded silver badge': $medal_s = '<:wounded_silver:705786458916651068>'; break;
                                    case 'wounded gold badge': $medal_s = '<:wounded_gold:705786458845216848>'; break;
                                    case 'iron cross 1st class': $medal_s = '<:iron_cross1:705786458572587109>'; break;
                                    case 'iron cross 2nd class': $medal_s = '<:iron_cross2:705786458849673267>'; break;
                                    default:  $medal_s = '<:long_service:705786458874707978>';
                                }
                                $result .= "**{$duser[1]}:** {$medal_s} **{$duser[2]}**, *{$duser[4]}*, {$duser[5]}" . PHP_EOL;
                            }
                        }
                        if ($result != '') return $result;
                        if (! $found && ($result === '')) return 'No medals found for this ckey.';
                    };
                    if (! $msg = $medals($ckey)) return $this->civ13->reply($message, 'There was an error trying to get your medals!');
                    return $this->civ13->reply($message, $msg, 'medals.txt');
                }, ['Verified']);
        if (isset($this->civ13->enabled_gameservers['eternal'], $this->civ13->enabled_gameservers['eternal']->basedir) && file_exists($fp = $this->civ13->enabled_gameservers['eternal']->basedir . Civ13::awards_br))
            $this->messageHandler->offsetSet('brmedals',
                function (Message $message, string $command, array $message_filtered) use ($fp): PromiseInterface
                {
                    if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Wrong format. Please try `brmedals [ckey]`.');
                    $brmedals = function (string $ckey) use ($fp): string
                    {
                        if (! $search = @fopen($fp, 'r')) return "Error opening $fp.";
                        $result = '';
                        while (! feof($search)) if (str_contains($line = trim(str_replace(PHP_EOL, '', fgets($search))), $ckey)) {
                            $duser = explode(';', $line);
                            if ($duser[0] === $ckey) $result .= "**{$duser[1]}:** placed *{$duser[2]} of {$duser[5]},* on {$duser[4]} ({$duser[3]})" . PHP_EOL;
                        }
                        return $result
                            ? $result
                            : 'No medals found for this ckey.';
                    };
                    if (! $msg = $brmedals($ckey)) return $this->civ13->reply($message, 'There was an error trying to get your medals!');
                    return $this->civ13->reply($message, $msg, 'brmedals.txt');
                    // return $this->civ13->reply($message, "Too many medals to display.");
                }, ['Verified']);
        
        foreach ($this->civ13->enabled_gameservers as &$gameserver) {
            if (! file_exists($gameserver->basedir . Civ13::playernotes_basedir)) $this->logger->warning("Skipping server function `{$gameserver->key}notes` because the required config files were not found.");
            else {
                $this->messageHandler
                    ->offsetSet("{$gameserver->key}notes",
                        function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
                        {
                            if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content'], strlen($command)))) return $this->civ13->reply($message, 'Missing ckey! Please use the format `notes ckey`');
                            $first_letter_lower = strtolower(substr($ckey, 0, 1));
                            $first_letter_upper = strtoupper(substr($ckey, 0, 1));
                            
                            $letter_dir = '';
                            
                            if (is_dir($basedir = $gameserver->basedir . Civ13::playernotes_basedir. "/$first_letter_lower")) $letter_dir = $basedir . "/$first_letter_lower";
                            elseif (is_dir($basedir = $gameserver->basedir . Civ13::playernotes_basedir . "/$first_letter_upper")) $letter_dir = $basedir . "/$first_letter_upper";
                            else return $this->civ13->reply($message, "No notes found for any ckey starting with `$first_letter_upper`.");

                            $player_dir = '';
                            $dirs = [];
                            $scandir = scandir($letter_dir);
                            if ($scandir) $dirs = array_filter($scandir, function($dir) use ($ckey) {
                                return strtolower($dir) === strtolower($ckey)/* && is_dir($letter_dir . "/$dir")*/;
                            });
                            if (count($dirs) > 0) $player_dir = $letter_dir . "/" . reset($dirs);
                            else return $this->civ13->reply($message, "No notes found for `$ckey`.");

                            if (file_exists($player_dir . "/info.sav")) $file_path = $player_dir . "/info.sav";
                            else return $this->civ13->reply($message, "A notes folder was found for `$ckey`, however no notes were found in it.");

                            $result = '';
                            if ($contents = @file_get_contents($file_path)) $result = $contents;
                            else return $this->civ13->reply($message, "A notes file with path `$file_path` was found for `$ckey`, however the file could not be read.");
                            
                            return $this->civ13->reply($message, $result, 'info.sav', true);
                        },
                        ['Admin'])
                ;
            }
            $this->messageHandler
                ->offsetSet("{$gameserver->key}ranking",
                    fn (Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $gameserver->recalculateRanking()->then(
                            fn () => $gameserver->getRanking()->then(
                                fn (string $ranking) => $this->civ13->reply($message, $ranking, 'ranking.txt'),
                                function (MissingSystemPermissionException $error) use ($message) {
                                    $this->logger->error($err = $error->getMessage());
                                    $message->react("🔥")->then(fn () => $this->civ13->reply($message, $err));
                                }
                            ),
                            function (MissingSystemPermissionException $error) use ($message) {
                                $this->logger->error($err = $error->getMessage());
                                $message->react("🔥")->then(fn () => $this->civ13->reply($message, $err));
                            }
                        ),
                    ['Verified'])
                ->offsetSet("{$gameserver->key}rank",
                    function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
                    {
                        if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) {
                            if (! $item = $this->civ13->verifier->getVerifiedItem($message->author)) return $this->civ13->reply($message, 'Wrong format. Please try `rankme [ckey]`.');
                            $ckey = $item['ss13'];
                        }
                        if (! $gameserver->recalculateRanking()) return $this->civ13->reply($message, 'There was an error trying to recalculate ranking! The bot may be misconfigured.');
                        if (! $msg = $gameserver->getRank($ckey)) return $this->civ13->reply($message, 'There was an error trying to get your ranking!');
                        return $this->civ13->sendMessage($message->channel, $msg, 'rank.txt');
                        // return $this->civ13->reply($message, "Your ranking is too long to display.");
                    },
                    ['Verified'])                
                ->offsetSet("{$gameserver->key}ban",
                    function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
                    {
                        if (! $this->civ13->hasRequiredConfigRoles(['Banished'])) $this->logger->warning("Skipping server function `{$gameserver->key} ban` because the required config roles were not found.");
                        if (! $message_content = substr($message_filtered['message_content'], strlen($command))) return $this->civ13->reply($message, 'Missing ban ckey! Please use the format `{server}ban ckey; duration; reason`');
                        if (! $split_message = explode('; ', $message_content)) return $this->civ13->reply($message, 'Invalid format! Please use the format `{server}ban ckey; duration; reason`');
                        if (! $split_message[0]) return $this->civ13->reply($message, 'Missing ban ckey! Please use the format `ban ckey; duration; reason`');
                        if (! $split_message[1]) return $this->civ13->reply($message, 'Missing ban duration! Please use the format `ban ckey; duration; reason`');
                        if (! $split_message[2]) return $this->civ13->reply($message, 'Missing ban reason! Please use the format `ban ckey; duration; reason`');
                        if (! str_ends_with($split_message[2], '.')) $split_message[2] .= '.';
                        $maxlen = 150 - strlen(" Appeal at {$this->civ13->discord_formatted}");
                        if (strlen($split_message[2]) > $maxlen) return $this->civ13->reply($message, "Ban reason is too long! Please limit it to `$maxlen` characters.");
                        $arr = ['ckey' => $split_message[0], 'duration' => $split_message[1], 'reason' => $split_message[2] . " Appeal at {$this->civ13->discord_formatted}"];
                        $result = $this->civ13->ban($arr, $this->civ13->verifier->getVerifiedItem($message->author)['ss13'], $gameserver);
                        if ($member = $this->civ13->verifier->getVerifiedMember('id', $split_message[0]))
                            if (! $member->roles->has($this->civ13->role_ids['Banished']))
                                $member->addRole($this->civ13->role_ids['Banished'], $result);
                        return $this->civ13->reply($message, $result);
                    },
                    ['Admin'])
                ->offsetSet("{$gameserver->key}unban",
                    function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
                    {
                        if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Missing unban ckey! Please use the format `{server}unban ckey`');
                        if (is_numeric($ckey)) {
                            if (! $item = $this->civ13->verifier->getVerifiedItem($ckey)) return $this->civ13->reply($message, "No data found for Discord ID `$ckey`.");
                            $ckey = $item['ckey'];
                        }
                        
                        $this->civ13->unban($ckey, $admin = $this->civ13->verifier->getVerifiedItem($message->author)['ss13'], $gameserver);
                        $result = "**$admin** unbanned **$ckey** from **{$gameserver->name}**";
                        if ($member = $this->civ13->verifier->getVerifiedMember('id', $ckey))
                            if ($member->roles->has($this->civ13->role_ids['Banished']))
                                $member->removeRole($this->civ13->role_ids['Banished'], $result);
                        return $this->civ13->reply($message, $result);
                    },
                    ['Admin'])
                ->offsetSet("{$gameserver->key}configexists",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        isset($gameserver->key)
                            ? $message->react("👍")
                            : $message->react("👎"),
                    ['Ambassador'])
                ->offsetSet("{$gameserver->key}host",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $message->react("⏱️")->then(fn() => $gameserver->Host($message)),
                    ['Ambassador'])
                ->offsetSet("{$gameserver->key}kill",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $message->react("⏱️")->then(fn() => $gameserver->Kill($message))
                    , ['Ambassador'])
                ->offsetSet("{$gameserver->key}restart",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $message->react("⏱️")->then(fn() => $gameserver->Restart($message)),
                    ['Ambassador'])
                ->offsetSet("{$gameserver->key}mapswap",
                    function (Message $message, string $command, array $message_filtered) use (&$gameserver): PromiseInterface
                    {
                        $split_message = explode("{$gameserver->key}mapswap ", $message_filtered['message_content']);
                        if (! isset($split_message[1])) return $message->react("❌")->then(fn () => $this->civ13->reply($message, 'You need to include the name of the map.'));
                        return $gameserver->MapSwap($split_message[1], (isset($this->civ13->verifier)) ? ($this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $this->civ13->discord->username) : $this->civ13->discord->username)->then(
                            fn ($result) => $message->react("👍")->then(fn() => $this->civ13->reply($message, $result)),
                            fn (\Throwable $error) => $message->react($error instanceof FileNotFoundException ? "🔥" : "👎")->then(fn() => $this->civ13->reply($message, $error->getMessage()))
                        );
                    }, ['Ambassador'])
                ->offsetSet("{$gameserver->key}sportsteam",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface => // I don't know what this is supposed to be used for anymore but the file exists, is empty, and can't be read from.
                        $gameserver->sportsteam()->then(
                            fn($content) => $message->reply(MessageBuilder::new()->setContent('Sports Teams')->addfileFromContent("{$gameserver->key}_sports_teams.txt", $content)),
                            fn(\Throwable $error) => $message->react("🔥")->then(fn() => $this->civ13->reply($message, $error->getMessage()))
                        ),
                    ['Ambassador', 'Admin'])
                ->offsetSet("{$gameserver->key}panic",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $this->civ13->reply($message, "Panic bunker is now " . (($gameserver->panic_bunker = ! $gameserver->panic_bunker) ? 'enabled' : 'disabled')),
                    ['Ambassador'])
                ->offsetSet("{$gameserver->key}fixembedtimer",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $message->react("⏱️")->then(fn() => $gameserver->currentRoundEmbedTimer($message))->then(
                            fn() => $message->react("👍"),
                            fn(\Throwable $error) => $message->react("👎")->then(fn() => $this->civ13->reply($message, $error->getMessage()))
                        ),
                    ['Owner', 'Chief Technical Officer'])
                ->offsetSet("{$gameserver->key}updatecurrentroundembedmessagebuilder",
                    fn(Message $message, string $command, array $message_filtered): PromiseInterface =>
                        $message->react("⏱️")->then(fn() => $gameserver->updateCurrentRoundEmbedMessageBuilder())->then(
                            fn() => $message->react("👍"),
                            fn(\Throwable $error) => $message->react("👎")->then(fn() => $this->civ13->reply($message, $error->getMessage()))
                        ),
                    ['Owner', 'Chief Technical Officer']);
        }
        
        $this->__declareListener();
    }

    /**
     * Declares the listener for handling incoming messages.
     * If no message handlers are found, it logs a debug message and returns.
     * Otherwise, it sets up an event listener for the 'message' event and handles the message.
     *
     * @return void
     */
    private function __declareListener()
    {
        if (! $this->messageHandler->first()) {
            $this->logger->debug('No message handlers found!');
            return;
        }

        $this->civ13->discord->on('message', function (Message $message): void
        {
            if ($message->author->bot || $message->webhook_id) return; // Ignore bots and webhooks (including slash commands) to prevent infinite loops and other issues
            if (! $this->handle($message, $message_filtered = $this->civ13->filterMessage($message))) { // This section will be deprecated in the future
                if (! empty($this->civ13->functions['message'])) foreach ($this->civ13->functions['message'] as $func) $func($this->civ13, $message, $message_filtered); // Variable functions
                //else $this->logger->debug('No message variable functions found!');
            }
        });
    }

    /**
     * Magic method to dynamically call methods on the MessageHandler object.
     *
     * @param string $name The name of the method being called.
     * @param array $arguments The arguments passed to the method.
     * @return mixed The result of the method call.
     * @throws \BadMethodCallException If the method does not exist.
     */
    public function __call(string $name, array $arguments)
    { // Forward calls to the MessageHandler object (generateHelp, offsetGet, offsetSet, offsetExists, etc.)
        if (method_exists($this->messageHandler, $name)) return call_user_func_array([$this->messageHandler, $name], $arguments);
        throw new \BadMethodCallException("Method {$name} does not exist.");
    }
}