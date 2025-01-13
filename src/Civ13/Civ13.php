<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Byond\Byond;
use Civ13\Exceptions\PartException;
use Civ13\Moderator;
use Civ13\PromiseMiddleware;
use Civ13\Slash;
use Discord\Discord;
use Discord\Builders\MessageBuilder;
use Discord\Helpers\BigInt;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Guild\Role;
use Discord\Parts\Thread\Thread;
use Discord\Parts\User\Activity;
use Discord\Parts\User\Member;
//use Discord\Repository\EntitlementRepository;
//use Discord\Repository\SKUsRepository;
use Discord\Stats;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use React\Filesystem\AdapterInterface;
use React\Filesystem\Factory as FilesystemFactory;
use React\Promise\PromiseInterface;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Socket\SocketServer;
use ReflectionFunction;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function React\Promise\reject;
use function React\Promise\resolve;
use function React\Promise\all;

enum CommandPrefix: string
{
    case COMMAND_SYMBOL = 'command_symbol';
    case MENTION_WITH_EXCLAMATION = 'mention_with_exclamation';
    case MENTION = 'mention';
    
    public static function getPrefix(self $prefix, string $discordId, string $commandSymbol): ?string {
        return match ($prefix) {
            self::COMMAND_SYMBOL => $commandSymbol,
            self::MENTION_WITH_EXCLAMATION => "<@!{$discordId}>",
            self::MENTION => "<@{$discordId}>",
            default => null,
        };
    }
}

enum CPUUsage: string
{
    case Windows = 'Windows';
    case Linux = 'Linux';
    case Unknown = 'Unknown';

    public static function fromPHPOSFamily(): self
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => self::Windows,
            'Linux' => self::Linux,
            default => self::Unknown,
        };
    }

    public function __invoke(): string
    {
        return match ($this) {
            self::Windows => self::getWindowsUsage(),
            self::Linux => self::getLinuxUsage(),
            self::Unknown => "Unrecognized operating system!",
            default => "Unsupported operating system!",
        };
    }

    private static function getWindowsUsage(): string
    {
        return 'CPU Usage: ' . round(trim(shell_exec('powershell -command "Get-Counter -Counter \'\\Processor(_Total)\\% Processor Time\' | Select-Object -ExpandProperty CounterSamples | Select-Object -ExpandProperty CookedValue"')), 2) . '%';
    }

    private static function getLinuxUsage(): string
    {
        return 'CPU Usage: ' . round(sys_getloadavg()[0] * 100 / shell_exec("nproc"), 2) . '%';
    }
}

class Civ13
{
    const maps = '/code/__defines/maps.dm'; // Found in the cloned git repo, (e.g. '/home/emoney/civ13/civ13-git/code/__defines/maps.dm')

    const log_basedir = '/data/logs';
    const playernotes_basedir = '/data/player_saves';
    
    const discord2ooc = '/SQL/discord2ooc.txt';
    const discord2admin = '/SQL/discord2admin.txt';
    const discord2dm = '/SQL/discord2dm.txt';
    const discord2ban = '/SQL/discord2ban.txt';
    const discord2unban = '/SQL/discord2unban.txt';
    const admins = '/SQL/admins.txt';
    const whitelist = '/SQL/whitelist.txt';
    const bans = '/SQL/bans.txt';
    const playerlogs = '/SQL/playerlogs.txt';
    const factionlist = '/SQL/factionlist.txt';
    const sportsteams = '/SQL/sports_teams.txt';
    const awards = '/SQL/awards.txt';
    const awards_br = '/SQL/awards_br.txt';

    const updateserverabspaths = '/scripts/updateserverabspaths.py';
    const serverdata = '/serverdata.txt';
    const killsudos = '/scripts/killsudos.py';
    const killciv13 = '/scripts/killciv13.py';
    const mapswap = '/scripts/mapswap.py';

    const dmb = '/civ13.dmb';
    const ooc_path = '/ooc.log';
    const asay_path = '/admin.log';
    const ranking_path = '/ranking.txt';

    const insults_path = 'insults.txt';
    const status = 'status.txt';

    /** @var array<string> */
    const array faction_teams = ['Red Faction', 'Blue Faction'];
    /** @var array<string> */
    const array faction_admins = ['Faction Organizer'];
    /** @var array<string|null> */
    public readonly array $faction_ids;

    public bool $ready = false;
    public array $options = [];
    
    public Byond $byond;
    public Moderator $moderator;
    public Verifier $verifier;

    public string $welcome_message = '';
    
    public \Closure $onFulfilledDefault;
    public \Closure $onRejectedDefault;

    public Slash $slash;
    /** @var HttpServiceManager&HttpHandler */
    public HttpServiceManager $httpServiceManager;
    /** @var HttpServiceManager&MessageHandler */
    public MessageServiceManager $messageServiceManager;
    public CommandServiceManager $commandServiceManager;
    
    public string $webserver_url = 'www.valzargaming.com'; // The URL of the webserver that the bot pulls server information from

    public StreamSelectLoop $loop;
    public Discord $discord;
    public Browser $browser;
    public AdapterInterface $filesystem;
    public Logger $logger;
    public Stats $stats;

    public string $filecache_path = '';
    
    public array $softbanned = []; // List of ckeys and discord IDs that are not allowed to go through the verification process
    public array $paroled = []; // List of ckeys that are no longer banned but have been paroled
    public array $ages = []; // $ckey => $age, temporary cache to avoid spamming the Byond REST API, but we don't want to save it to a file because we also use it to check if the account still exists
    public array $ip_data = [];
    public const IP_DATA_TTL = 604800; // 1 week
    public string $minimum_age = '-21 days'; // Minimum age of a ckey
    public array $permitted = []; // List of ckeys that are permitted to use the verification command even if they don't meet the minimum account age requirement or are banned with another ckey
    public array $blacklisted_regions =[
    '77.124', '77.125', '77.126', '77.127', '77.137.', '77.138.', '77.139.', '77.238.175', '77.91.69', '77.91.71', '77.91.74', '77.91.79', '77.91.88', // Region
    '77.75.145.', // Known evaders
    ];
    public array $blacklisted_countries = ['IL', 'ISR'];

    /** @var Timerinterface[] */
    public array $timers = [];
    public array $serverinfo = []; // Collected automatically by serverinfo_timer
    public array $players = []; // Collected automatically by serverinfo_timer
    public array $seen_players = []; // Collected automatically by serverinfo_timer
    public int $playercount_ticker = 0;

    public readonly string $gitdir;  // The base directory of the git repository.
    /** @var Gameserver[] */
    public array $gameservers = [];
    /** @var Gameserver[] */
    public array $enabled_gameservers = [];
    public bool $moderate = true; // Whether or not to moderate the servers using the ooc_badwords list
    public array $ooc_badwords = [];
    public array $ooc_badwords_warnings = []; // Array of [$ckey]['category'] => integer] for how many times a user has recently infringed for a specific category
    public array $ic_badwords = [];
    public array $ic_badwords_warnings = []; // Array of [$ckey]['category'] => integer] for how many times a user has recently infringed for a specific category
    public bool $legacy = true; // If true, the bot will use the file methods instead of the SQL ones
    
    public array $functions = array(
        'init' => [],
        'ready_slash' => [],
        'messages' => [],
        'misc' => [],
    );
    public array $server_funcs_uncalled = []; // List of callable functions that are available for use by other functions, but otherwise not called via a message command
    
    public string $command_symbol = '@Civilizationbot'; // The symbol that the bot will use to identify commands if it is not mentioned
    public string $owner_id = '196253985072611328'; // Taislin's Discord ID
    public string $technician_id = '116927250145869826'; // Valithor Obsidion's Discord ID
    public string $embed_footer = ''; // Footer for embeds, this is set in the ready event
    public string $civ13_guild_id = '468979034571931648'; // Guild ID for the Civ13 server
    public string $civ_token = ''; // Token for use with $verify_url, this is not the same as the bot token and should be kept secret

    public string $github = 'https://github.com/VZGCoders/Civilizationbot'; // Link to the bot's github page
    public string $discord_invite = 'https://civ13.com/discord'; // Link to the Civ13 Discord server
    public string $discord_formatted = 'civ13.com slash discord'; // Formatted for posting in-game (cannot contain html special characters like / or &, blame the current Python implementation)
    public string $rules = 'civ13.com slash rules'; // Link to the server rules
    public string $verify_url = 'http://valzargaming.com:8080/verified/'; // Where the bot submit verification of a ckey to and where it will retrieve the list of verified ckeys from
    public string $serverinfo_url = ''; // Where the bot will retrieve server information from
    public bool $webserver_online = true; // Whether the serverinfo webserver is online (not to be confused with the verification server)
    public bool $verifier_online = true;
    
    public array $folders = [];
    public array $files = [];
    public array $ips = [];
    public array $ports = [];
    public array $channel_ids = [];
    public array $role_ids = [];
    
    public array $tests = []; // Staff application test templates
    public bool $panic_bunker = false; // If true, the bot will server ban anyone who is not verified when they join the server
    public array $panic_bans = []; // List of ckeys that have been banned by the panic bunker in the current runtime

    public Message $restart_message;

    private string $constructed_file;

    private PromiseMiddleware $then;

    /**
     * Creates a Civ13 client instance.
     * 
     * @param array $options An array of options for configuring the client.
     * @param array $server_settings An array of configurations for the game servers.
     * @throws E_USER_ERROR If the code is not running in a CLI environment.
     * @throws E_USER_WARNING If the ext-gmp extension is not loaded.
     */
    public function __construct(array $options = [], array $server_settings = [])
    {
        if (php_sapi_name() !== 'cli') trigger_error('DiscordPHP will not run on a webserver. Please use PHP CLI to run a DiscordPHP bot.', E_USER_ERROR);

        // x86 need gmp extension for big integer operation
        if (PHP_INT_SIZE === 4 && ! BigInt::init()) trigger_error('ext-gmp is not loaded. Permissions will NOT work correctly!', E_USER_WARNING);

        // Set the file that the object was constructed in
        $this->constructed_file = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'];

        $this->logger =  $options['logger'] ?? $this->discord->getLogger() ?? new Logger(self::class, [new StreamHandler('php://stdout', Level::Info)]);
        $options = $this->resolveOptions($options);
        $this->options =& $options;
        if (isset($options['discord']) && ($options['discord'] instanceof Discord)) $this->discord =& $options['discord'];
        elseif (isset($options['discord_options']) && is_array($options['discord_options'])) $this->discord = new Discord($options['discord_options']);
        else $this->logger->error('No Discord instance or options passed in options!');
        $this->loop = $options['loop'] ?? $this->discord->getLoop();

        $this->browser = $options['browser'];
        $this->filesystem = $options['filesystem'];
        $this->stats = $options['stats'];
        
        $this->filecache_path = getcwd() . '/json/';
        if (isset($options['filecache_path']) && is_string($options['filecache_path'])) {
            if (! str_ends_with($options['filecache_path'], '/')) $options['filecache_path'] .= '/';
            $this->filecache_path = $options['filecache_path'];
        }
        if (! is_dir($this->filecache_path)) mkdir($this->filecache_path, 0664, true);
        
        if (isset($options['command_symbol']) && $options['command_symbol']) $this->command_symbol = $options['command_symbol'];
        if (isset($options['owner_id'])) $this->owner_id = $options['owner_id'];
        if (isset($options['technician_id'])) $this->technician_id = $options['technician_id'];
        if (isset($options['verify_url'])) $this->verify_url = $options['verify_url'];
        if (isset($options['discord_formatted'])) $this->discord_formatted = $options['discord_formatted'];
        if (isset($options['rules'])) $this->rules = $options['rules'];
        if (isset($options['gitdir'])) $this->gitdir = $options['gitdir'];
        if (isset($options['github'])) $this->github = $options['github'];
        if (isset($options['discord_invite'])) $this->discord_invite = $options['discord_invite'];
        if (isset($options['civ13_guild_id'])) $this->civ13_guild_id = $options['civ13_guild_id'];
        if (isset($options['civ_token'])) $this->civ_token = $options['civ_token'];
        if (isset($options['serverinfo_url'])) $this->serverinfo_url = $options['serverinfo_url'];
        if (isset($options['webserver_url'])) $this->webserver_url = $options['webserver_url'];
        if (isset($options['legacy']) && is_bool($options['legacy'])) $this->legacy = $options['legacy'];
        if (isset($options['moderate']) && is_bool($options['moderate'])) $this->moderate = $options['moderate'];
        if (isset($options['ooc_badwords']) && is_array($options['ooc_badwords'])) $this->ooc_badwords = $options['ooc_badwords'];
        if (isset($options['ic_badwords']) && is_array($options['ic_badwords'])) $this->ic_badwords = $options['ic_badwords'];

        if (isset($options['minimum_age']) && is_string($options['minimum_age'])) $this->minimum_age = $options['minimum_age'];
        if (isset($options['blacklisted_regions']) && is_array($options['blacklisted_regions'])) $this->blacklisted_regions = $options['blacklisted_regions'];
        if (isset($options['blacklsited_countries']) && is_array($options['blacklisted_countries'])) $this->blacklisted_countries = $options['blacklisted_countries'];
        
        if (isset($options['functions'])) foreach (array_keys($options['functions']) as $key1) foreach ($options['functions'][$key1] as $key2 => $func) $this->functions[$key1][$key2] = $func;
        else $this->logger->warning('No functions passed in options!');
        
        if (isset($options['files'])) foreach ($options['files'] as $key => $path) $this->files[$key] = $path;
        else $this->logger->warning('No files passed in options!');
        if (isset($options['channel_ids'])) foreach ($options['channel_ids'] as $key => $id) $this->channel_ids[$key] = $id;
        else $this->logger->warning('No channel_ids passed in options!');
        if (isset($options['role_ids'])) foreach ($options['role_ids'] as $key => $id) $this->role_ids[$key] = $id;
        else $this->logger->warning('No role_ids passed in options!');

        $this->afterConstruct($options, $server_settings);
    }
    /**
     * This method is called after the object is constructed.
     * It initializes various properties, starts timers, and starts handling events.
     *
     * @param array $options An array of options.
     * @param array $server_options An array of server options.
     * @return void
     */
    private function afterConstruct(array $options = [], array $server_settings = []): void
    {
        $this->__loadOrInitializeVariables();
        new Moderator($this);
        new Verifier($this, $options);
        foreach ($server_settings as $gameserver_settings) new Gameserver($this, $gameserver_settings);
        $this->byond = new Byond();
        $this->httpServiceManager = new HttpServiceManager($this);
        $this->messageServiceManager = new MessageServiceManager($this);
        $this->discord->on('init', function () {
            $this->ready = true;
            $this->logger->info("Logged in as {$this->discord->username} {$this->discord->user}");
            $this->logger->info('------');
            //$this->commandServiceManager = new CommandServiceManager($this->discord, $this->httpServiceManager, $this->messageServiceManager, $this);
            $this->__UpdateDiscordVariables();
            $this->loop->addTimer(5, fn() => $this->slash = new Slash($this));
            $this->declareListeners();
            $this->bancheckTimer(); // Start the unban timer and remove the role from anyone who has been unbanned
            foreach ($this->functions['init'] as $func) $func($this);
        });
    }
    /**
     * Resolves the given options array by validating and setting default values for each option.
     *
     * @param array $options An array of options to be resolved.
     * @return array The resolved options array.
     */
    private function resolveOptions(array &$options = []): array
    {
        $resolver = new OptionsResolver();

        $resolver
            ->setDefined([
                'welcome_message',
                'logger',
                'onFulfilledDefault',
                'onRejectedDefault',
                'folders',
                'files',
                'channel_ids',
                'role_ids',
                'functions',
                'loop',
                'browser',
                'filesystem',
                'civ13_guild_id',
                'civ_token',
                'command_symbol',
                'discord',
                'discord_formatted',
                'discord_invite',
                'gitdir',
                'github',
                'http_key',
                'http_port',
                'http_whitelist',
                'ic_badwords',
                'legacy',
                'moderate',
                'ooc_badwords',
                'owner_id',
                'rules',
                'server_settings',
                'socket',
                'stats',
                'technician_id',
                'verify_url',
                'web_address',
                'webapi',
                'webserver_url',
            ])
            ->setDefaults([
                'welcome_message' => '',
                'logger' => null,
                'onFulfilledDefault' => null,
                'onRejectedDefault' => null,
                'folders' => [],
                'files' => [],
                'channel_ids' => [],
                'role_ids' => [],
                'functions' => [],
                'loop' => Loop::get(),
                'browser' => null,
                'filesystem' => null,
                'civ13_guild_id' => '',
                'civ_token' => '',
                'command_symbol' => '@Civilizationbot',
                'discord' => null,
                'discord_formatted' => 'civ13.com slash discord',
                'discord_invite' => 'https://civ13.com/discord',
                'gitdir' => '',
                'github' => 'https://github.com/VZGCoders/Civilizationbot',
                'http_key' => '',
                'http_port' => 0,
                'http_whitelist' => [],
                'ic_badwords' => [],
                'legacy' => true,
                'moderate' => true,
                'ooc_badwords' => [],
                'owner_id' => '196253985072611328',
                'rules' => 'civ13.com slash rules',
                'server_settings' => [],
                'socket' => null,
                'stats' => null,
                'technician_id' => '116927250145869826',
                'verify_url' => 'http://valzargaming.com:8080/verified/',
                'web_address' => '',
                'webapi' => null,
                'webserver_url' => 'www.valzargaming.com',
            ])
            ->setAllowedTypes('welcome_message', 'string')
            ->setAllowedTypes('logger', ['null', Logger::class])
            ->setAllowedTypes('onFulfilledDefault', ['null', 'callable'])
            ->setAllowedTypes('onRejectedDefault', ['null', 'callable'])
            ->setAllowedTypes('folders', 'array')
            ->setAllowedTypes('files', 'array')
            ->setAllowedTypes('channel_ids', 'array')
            ->setAllowedTypes('role_ids', 'array')
            ->setAllowedTypes('functions', 'array')
            ->setAllowedTypes('loop', LoopInterface::class)
            ->setAllowedTypes('browser', ['null', Browser::class])
            ->setAllowedTypes('filesystem', ['null', AdapterInterface::class])
            ->setAllowedTypes('civ13_guild_id', 'string')
            ->setAllowedTypes('civ_token', 'string')
            ->setAllowedTypes('command_symbol', 'string')
            ->setAllowedTypes('discord', ['null', Discord::class])
            ->setAllowedTypes('discord_formatted', 'string')
            ->setAllowedTypes('discord_invite', 'string')
            ->setAllowedTypes('gitdir', 'string')
            ->setAllowedTypes('github', 'string')
            ->setAllowedTypes('http_key', 'string')
            ->setAllowedTypes('http_port', 'int')
            ->setAllowedTypes('http_whitelist', 'array')
            ->setAllowedTypes('ic_badwords', 'array')
            ->setAllowedTypes('legacy', 'bool')
            ->setAllowedTypes('moderate', 'bool')
            ->setAllowedTypes('ooc_badwords', 'array')
            ->setAllowedTypes('owner_id', 'string')
            ->setAllowedTypes('rules', 'string')
            ->setAllowedTypes('server_settings', 'array')
            ->setAllowedTypes('socket', ['null', 'resource', SocketServer::class])
            ->setAllowedTypes('stats', ['null', Stats::class])
            ->setAllowedTypes('technician_id', 'string')
            ->setAllowedTypes('verify_url', 'string')
            ->setAllowedTypes('web_address', 'string')
            ->setAllowedTypes('webapi', ['null', 'resource', HttpServer::class])
            ->setAllowedTypes('webserver_url', 'string');

        $options = $resolver->resolve($options);

        $this->welcome_message = $options['welcome_message'];

        if (! $options['logger']) {
            $streamHandler = new StreamHandler('php://stdout', Level::Info);
            $streamHandler->setFormatter(new LineFormatter(null, null, true, true));
            $options['logger'] = new Logger(self::class, [$streamHandler]);
        }
        $this->logger = $options['logger'];

        $onFulfilledDefaultValid = false;
        if ($options['onFulfilledDefault']) {
            if ($reflection = new ReflectionFunction($options['onFulfilledDefault'])) {
                if ($returnType = $reflection->getReturnType()) {
                    if ($returnType->getName() !== 'void') {
                        $this->onFulfilledDefault = $options['onFulfilledDefault'];
                        $onFulfilledDefaultValid = true;
                    }
                }
            }
        }
        if (! $onFulfilledDefaultValid) $this->onFulfilledDefault = function ($result) {
            return $result;
            // This will be useful for debugging promises that are not resolving as expected.
            $output = 'Promise resolved with type of: `' . gettype($result) . '`';
            if (is_object($result)) {
                $output .= ' and class of: `' . get_class($result) . '`';
                $output .= ' with properties: `' . implode('`, `', array_keys(get_object_vars($result))) . '`';
                if (isset($result->scriptData)) $output .= " and scriptData of: `{$result->scriptData}`";
                $output .= PHP_EOL;
                ob_start();
                var_dump($result);
                $output .= ob_get_clean();
            }
            $this->logger->debug($output);
            return $result;
        };
        $onRejectedDefaultValid = false;
        if ($options['onRejectedDefault'])
            if ($reflection = new ReflectionFunction($options['onRejectedDefault']))
                if ($returnType = $reflection->getReturnType())
                    if ($returnType->getName() === 'void') {
                        $this->onRejectedDefault = $options['onRejectedDefault'];
                        $onRejectedDefaultValid = true;
                    }
        if (! $onRejectedDefaultValid) $this->onRejectedDefault = function(\Throwable $reason): void {
            $this->logger->error("Promise rejected with reason: `$reason`");
        };
        $this->then = new PromiseMiddleware($this->onFulfilledDefault, $this->onRejectedDefault);

        foreach ($options['folders'] as $key => $value) if (! is_string($value) || ! is_dir($value) || ! @mkdir($value, 0664, true)) {
            $this->logger->warning("`$value` is not a valid folder path!");
            unset($options['folders'][$key]);
        }

        foreach ($options['files'] as $key => $value) if (! is_string($value) || ! @touch($value)) {
            $this->logger->warning("`$value` is not a valid file path!");
            unset($options['files'][$key]);
        }

        foreach ($options['channel_ids'] as $key => $value) if (! is_numeric($value)) {
            $this->logger->warning("`$value` is not a valid channel id!");
            unset($options['channel_ids'][$key]);
        }

        foreach ($options['role_ids'] as $key => $value)  if (! is_numeric($value)) {
            $this->logger->warning("`$value` is not a valid role id!");
            unset($options['role_ids'][$key]);
        }

        foreach ($options['functions'] as $key => $array) {
            if (! is_array($array)) {
                $this->logger->warning("`$key` is not a valid function array!");
                unset($options['functions'][$key]);
                continue;
            }
            foreach ($array as $func) if (! is_callable($func)) {
                $this->logger->warning("`$func` is not a valid function!");
                unset($options['functions'][$key]);
            }
        }

        $options['browser'] = $options['browser'] ?? new Browser($options['loop']);
        $options['filesystem'] = $options['filesystem'] ?? FileSystemFactory::create($options['loop']);

        return $options;
    }
    /**
     * Loads or initializes the variables used by the Civ13 class.
     * This method loads the values from JSON files or initializes them if the files do not exist.
     * It also handles the interruption of rounds if the bot was restarted during a round.
     * This process can take a while, so it should be called before the Discord client is ready.
     */
    private function __loadOrInitializeVariables(): void
    {
        if (! $tests = $this->VarLoad('tests.json')) {
            $tests = [];
            $this->VarSave('tests.json', $tests);
        }
        $this->tests = $tests;
        if (! $paroled = $this->VarLoad('paroled.json')) {
            $paroled = [];
            $this->VarSave('paroled.json', $paroled);
        }
        $this->paroled = $paroled;
        if (! $permitted = $this->VarLoad('permitted.json')) {
            $permitted = [];
            $this->VarSave('permitted.json', $permitted);
        }
        $this->permitted = $permitted;
        if (! $softbanned = $this->VarLoad('softbanned.json')) {
            $softbanned = [];
            $this->VarSave('softbanned.json', $softbanned);
        }
        $this->softbanned = $softbanned;
        if (! $panic_bans = $this->VarLoad('panic_bans.json')) {
            $panic_bans = [];
            $this->VarSave('panic_bans.json', $panic_bans);
        }
        $this->panic_bans = $panic_bans;
        if (! $ooc_badwords_warnings = $this->VarLoad('ooc_badwords_warnings.json')) {
            $ooc_badwords_warnings = [];
            $this->VarSave('ooc_badwords_warnings.json', $ooc_badwords_warnings);
        }
        $this->ooc_badwords_warnings = $ooc_badwords_warnings;
        if (! $ic_badwords_warnings = $this->VarLoad('ic_badwords_warnings.json')) {
            $ic_badwords_warnings = [];
            $this->VarSave('ic_badwords_warnings.json', $ic_badwords_warnings);
        }
        $this->ic_badwords_warnings = $ic_badwords_warnings;

        if (! $ages = $this->VarLoad('ages.json')) {
            $ages = [];
            $this->VarSave('ages.json', $ages);
        }
        $this->ages = $ages;
        if (! $ip_data = $this->VarLoad('ip_data.json')) {
            $ip_data = [];
            $this->VarSave('ip_data.json', $ip_data);
        }
        $this->ip_data = $ip_data;
        if (! $this->serverinfo_url) $this->serverinfo_url = "http://{$this->webserver_url}/servers/serverinfo.json"; // Default to VZG unless passed manually in config
        $this->embed_footer = $this->github 
        ? $this->github . PHP_EOL
        : '';
        $this->faction_ids = array_values(array_filter(array_map(fn($key) => $this->role_ids[$key] ?? null, Civ13::faction_teams)));
    }
    /**
     * Loads or initializes the variables used by the Civ13 class.
     * These variables rely on the Discord client being ready.
     */
    private function __UpdateDiscordVariables(): void
    {
        $this->embed_footer .= "{$this->discord->username}#{$this->discord->discriminator} by valithor" . PHP_EOL;
    }

    /**
     * Chains a callback to be executed when the promise is fulfilled or rejected.
     *
     * @param PromiseInterface $promise The promise to chain with.
     * @param callable|null $onFulfilled The callback to execute when the promise is fulfilled. If null, the default callback will be used.
     * @param callable|null $onRejected The callback to execute when the promise is rejected. If null, the default callback will be used.
     * @return PromiseInterface The new promise that will be fulfilled or rejected based on the result of the callback.
     */
    public function then(PromiseInterface $promise, ?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
    {
        return ($this->then)($promise, $onFulfilled, $onRejected);
        /*
        if (! $onRejected) $onRejectedDefault = function (\Throwable $reason) use ($promise, $onFulfilled): void
        { // TODO: Add a check for Discord disconnects and refire the promise
            $this->logger->error("Promise rejected with reason: `$reason`");
            if (str_starts_with($reason, 'Promise rejected with reason: `RuntimeException: Connection to tls://discord.com:443 timed out after 60 seconds (ETIMEDOUT)`')) { // Promise attempted to resolve while Discord was disconnected
                ob_start();
                var_dump($promise);
                $debug_string = ob_get_clean();
                $this->logger->error("Original promise dump: $debug_string");
                ob_start();
                var_dump($onFulfilled);
                $debug_string = ob_get_clean();
                $this->logger->error("onFulfilled callback dump: $debug_string");
            }
        };
        return $promise->then($onFulfilled ?? $this->onFulfilledDefault, $onRejected ?? $onRejectedDefault ?? $this->onRejectedDefault);
        */
    }
    public function deferUntilReady(callable $callback, ?string $function = null): void
    {
        $this->logger->info($function
            ? "Deferring callback until ready for event: $function"
            : "Deferring callback until ready for function: " . debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2)[1]['function'] ?? 'unknown'
        );
        $this->ready
            ? $callback()
            : $this->discord->once('init', $callback);
    }

    private function startsWithCommandPrefix(string $content): ?string
    {
        return array_reduce(CommandPrefix::cases(), fn($carry, $prefix) => $carry ?? (str_starts_with($content, $call = CommandPrefix::getPrefix($prefix, $this->discord->id, $this->command_symbol)) ? $call : null), null);
    }

    /**
     * Filters the message and extracts relevant information.
     *
     * @param Message $message The message to filter.
     * @return array An array containing the filtered message content, the lowercased message content, and a flag indicating if the message was called.
     */
    public function filterMessage(Message $message): array
    {
        if (! $message->guild || $message->guild->owner_id !== $this->owner_id) return ['message_content' => '', 'message_content_lower' => '', 'called' => false]; // Only process commands from a guild that Taislin owns
        
        $call = $this->startsWithCommandPrefix($message->content);
        $message_content = $call ? trim(substr($message->content, strlen($call))) : $message->content;

        return [
            'message_content' => $message_content,
            'message_content_lower' => strtolower($message_content),
            'called' => $call ? true : false
        ];
    }
    /**
     * Sanitizes the input (either a ckey or a Discord snowflake) by removing specific characters and converting it to lowercase.
     *
     * @param string $input The input string to be sanitized.
     * @return string The sanitized input string.
     */
    public static function sanitizeInput(string $input): string
    {
        return trim(str_replace(['<@!', '<@&', '<@', '>', '.', '_', '-', '+', ' '], '', strtolower($input)));
    }
    /**
     * Check that all required roles are properly declared in the bot's config and exist in the guild.
     *
     * @param array $required_roles An array of required role names.
     * @return bool Returns true if all required roles exist, false otherwise.
     */
    public function hasRequiredConfigRoles(array $required_roles = []): bool
    {
        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) { $this->logger->error('Guild ' . $this->civ13_guild_id . ' is missing from the bot'); return false; }
        if ($diff = array_diff($required_roles, array_keys($this->role_ids))) { $this->logger->error('Required roles are missing from the `role_ids` config', $diff); return false; }
        foreach ($required_roles as $role) if (! isset($this->role_ids[$role]) || ! $guild->roles->get('id', $this->role_ids[$role])) { $this->logger->error("$role role is missing from the guild"); return false; }
        return true;
    }

    /**
     * Runs the Discord loop.
     *
     * @return void
     *
     * @throws \Discord\Exceptions\IntentException
     * @throws \Discord\Exceptions\SocketException
     */
    public function run(): void
    {
        $this->logger->info('Starting Discord loop');
        if (!(isset($this->discord))) $this->logger->warning('Discord not set!');
        else $this->discord->run();
    }
    /**
     * Stops the bot and logs the shutdown message.
     *
     * @return void
     */
    public function stop(bool $closeLoop = true): void
    {
        $this->logger->info('Shutting down');
        if (isset($this->httpServiceManager->socket)) $this->httpServiceManager->socket->close();
        if (isset($this->discord)) $this->discord->close(false);
        if ($closeLoop) $this->loop->stop();
    }
    /**
     * Restarts the application by first stopping it and then calling the OS restart function.
     *
     * @return PromiseInterface<resource> A promise that resolves with the process resource on success, or rejects with a MissingSystemPermissionException on failure.
     * @throws MissingSystemPermissionException If the system does not have the required permissions to restart the bot.
     */
    public function restart(?string $file = null): PromiseInterface
    {
        $this->stop();
        return OSFunctions::restart($file ?? $this->constructed_file);
    }

    public function CPU(): string
    {
        return (CPUUsage::fromPHPOSFamily())();
    }

    /*
     * This function is used to change the bot's status on Discord
     */
    public function statusChanger(Activity $activity, string $state = 'online'): void
    {
        $this->discord->updatePresence($activity, false, $state);
    }
    /**
     * Removes specified roles from a member.
     *
     * @param Member $member The member object from which the roles will be removed.
     * @param Collection<Role>|array<Role|string|int>|Role|string|int $roles An array of role IDs to be removed.
     * @param bool $patch Determines whether to use patch mode or not. If true, the member's roles will be updated using setRoles method. If false, the member's roles will be updated using removeRole method.
     * @return PromiseInterface<Member> A promise that resolves to the updated member object.
     */
    public function removeRoles(Member $member, Collection|array|Role|string|int $roles, bool $patch = true): PromiseInterface
    {
        if (! $role_ids = array_filter(self::__rolesToIdArray($roles), fn($role_id) => $member->roles->has($role_id))) return resolve($member);
        return $patch
            ? ((($new_roles = $member->roles->filter(fn(Role $role) => ! in_array($role->id, $role_ids))->toArray()) !== $member->roles) ? $member->setRoles($new_roles) : resolve($member))
            : all(array_map(fn($role) => $member->removeRole($role->id), $role_ids))
                ->then(fn() => $member->guild->members->get('id', $member->id));
    }
    /**
     * Adds specified roles to a member.
     *
     * @param Member $member The member object to which the roles will be added.
     * @param Collection<Role>|array<Role|string|int>|Role|string|int $roles An array of role IDs to be added.
     * @param bool $patch Determines whether to use patch mode or not. If true, the member's roles will be updated using setRoles method. If false, the member's roles will be updated using addRole method.
     * @return PromiseInterface<Member> A promise that resolves to the updated member object.
     */
    public function addRoles(Member $member, Collection|array|Role|string|int $roles, bool $patch = true): PromiseInterface
    {
        if (! $role_ids = array_filter(self::__rolesToIdArray($roles), fn($role_id) => $member->roles->has($role_id))) return resolve($member);
        return $patch
            ? $member->setRoles(array_merge(array_values($member->roles->map(fn($role) => $role->id)->toArray()), $role_ids))
            : all(array_map(fn($role) => $member->addRole($role->id), $role_ids))
                ->then(fn() => $member->guild->members->get('id', $member->id));
    }
    /**
     * Updates specifiec roles for a member.
     *
     * @param Member $member The member object to which will have its roles updated.
     * @param Collection<Role>|array<Role|string|int>|Role|string|int $roles An array of role IDs to be added.
     * @param Collection<Role>|array<Role|string|int>|Role|string|int $roles An array of role IDs to be removed.
     * @return PromiseInterface<Member> A promise that resolves to the updated member object.
     */
    public function setRoles(Member $member, Collection|array|Role|string|int $add_roles = [], Collection|array|Role|string|int $remove_roles = []): PromiseInterface
    {
        if (! ($add_roles = self::__rolesToIdArray($add_roles)) && ! ($remove_roles = self::__rolesToIdArray($remove_roles))) return resolve($member);
        foreach ($add_roles as &$role_id) if ($member->roles->has($role_id)) unset($role_id);
        foreach ($remove_roles as &$role_id) if (! $member->roles->has($role_id)) unset($role_id);
        if (! $updated_roles = array_diff(array_merge(array_values($member->roles->map(fn($role) => $role->id)->toArray()), $add_roles), $remove_roles)) return resolve($member);
        return $member->setRoles($updated_roles);
    }
    /**
     * Convert roles to an array of role IDs.
     *
     * @param Collection<Role>|array<Role|string|int>|Role|string|int $roles The roles to convert.
     * @return array<string>|array<null> The array of role IDs, or an empty array if the conversion fails.
     */
    private static function __rolesToIdArray(Collection|array|Role|string|int $roles): array
    {
        if ($roles instanceof Collection && $roles->first() instanceof Role) return $roles->map(fn($role) => $role->id)->toArray();
        if (! $roles instanceof Collection && is_array($roles) && $roles[0] instanceof Role) return array_map(fn($role) => $role->id, $roles);
        if (is_array($roles)) return array_map('strval', $roles);
        if ($roles instanceof Role) return [$roles->id];
        if (is_string($roles) || is_int($roles)) return ["$roles"];
        throw new \InvalidArgumentException('Invalid roles array'); // This should never happen
    }
    /**
     * Sends a message to the specified channel.
     *
     * @param Channel|Thread|string $channel The channel to send the message to. Can be a channel ID or a Channel object.
     * @param string $content The content of the message.
     * @param string $file_name The name of the file to attach to the message. Default is 'message.txt'.
     * @param bool $prevent_mentions Whether to prevent mentions in the message. Default is false.
     * @return PromiseInterface<Message> A PromiseInterface representing the asynchronous operation, or null if the channel is not found.
     * @throws PartException If the channel is not found.
     */
    public function sendMessage(Channel|Thread|string $channel, string $content, string $file_name = 'message.txt', bool $prevent_mentions = false): PromiseInterface
    {
        // $this->logger->debug("Sending message to {$channel->name} ({$channel->id}): {$message}");
        if (is_string($channel) && ! $channel = $this->discord->getChannel($channel)) {
            $this->logger->error($err = "Channel not found for sendMessage");
            return reject(new PartException($err));
        }
        $builder = MessageBuilder::new();
        if ($prevent_mentions) $builder->setAllowedMentions(['parse'=>[]]);
        if (strlen($content)<=2000) return $channel->sendMessage($builder->setContent($content));
        if (strlen($content)<=4096) return $channel->sendMessage($builder->addEmbed($this->createEmbed()->setDescription($content)));
        return $channel->sendMessage($builder->addFileFromContent($file_name, $content));
    }
    /**
     * Sends a message as a reply to another message.
     *
     * @param Message $message The original message to reply to.
     * @param string $content The content of the reply message.
     * @param string $file_name The name of the file to attach to the reply message (default: 'message.txt').
     * @param bool $prevent_mentions Whether to prevent mentions in the reply message (default: false).
     * @return PromiseInterface<Message> A promise that resolves to the sent reply message, or null if the reply message could not be sent.
     */
    public function reply(Message|Thread $message, string $content, string $file_name = 'message.txt', bool $prevent_mentions = false): PromiseInterface
    {
        $builder = MessageBuilder::new();
        if ($prevent_mentions) $builder->setAllowedMentions(['parse'=>[]]);
        if (strlen($content)<=2000) return $message->reply($builder->setContent($content));
        if (strlen($content)<=4096) return $message->reply($builder->addEmbed($this->createEmbed()->setDescription($content)));
        return $message->reply($builder->addFileFromContent($file_name, $content));
    }
    /**
     * Sends an embed message to a channel.
     *
     * @param Channel|Thread|string $channel The channel to send the message to.
     * @param string $content The content of the message.
     * @param Embed $embed The embed object to send.
     * @param bool $prevent_mentions (Optional) Whether to prevent mentions in the message. Default is false.
     * @return PromiseInterface<Message>|null A promise that resolves to the sent message, or null if the channel is not found.
     */
    public function sendEmbed(Channel|Thread|string $channel, Embed $embed, string $content, bool $prevent_mentions = false): ?PromiseInterface
    {
        if (is_string($channel) && ! $channel = $this->discord->getChannel($channel)) {
            $this->logger->error($err = "Channel not found for sendEmbed");
            return reject(new PartException($err));
        }
        $builder = MessageBuilder::new();
        if ($prevent_mentions) $builder->setAllowedMentions(['parse'=>[]]);
        // $this->logger->debug("Sending message to {$channel->name} ({$channel->id}): {$message}");
        return $channel->sendMessage($builder->setContent($content)->addEmbed($embed->setFooter($this->embed_footer)));
    }
    public function createEmbed(?bool $footer = true, int $color = 0xE1452D): Embed
    {
        $embed = new Embed($this->discord);
        if ($footer) $embed->setFooter($this->embed_footer);
        return $embed
            ->setColor($color)
            ->setTimestamp()
            ->setURL('');
    }
    /**
     * Sends an out-of-character (OOC) message.
     *
     * @param string $message The message to send.
     * @param string $sender The sender of the message.
     * @param string|int|null $server_key Server for the message (optional).
     * @return bool Returns true if the message was sent successfully, false otherwise.
     */
    public function OOCMessage(string $message, string $sender, string|int|null $server_key = null): bool
    {
        if (is_null($server_key)) return array_reduce($this->enabled_gameservers, fn($carry, $server) => $carry || $server->OOCMessage($message, $sender), false);
        if (! isset($this->enabled_gameservers[$server_key])) return false;
        return $this->enabled_gameservers[$server_key]->OOCMessage($message, $sender);
    }
    /**
     * Sends an admin message to the server.
     *
     * @param string $message The message to send.
     * @param string $sender The sender of the message.
     * @param string|int|null $server_key Server for the message (optional).
     * @return bool Returns true if the message was sent successfully, false otherwise.
     */
    public function AdminMessage(string $message, string $sender, string|int|null $server_key = null): bool
    {
        if (is_null($server_key)) return array_reduce($this->enabled_gameservers, fn($carry, $server) => $carry || $server->AdminMessage($message, $sender), false);
        if (! isset($this->enabled_gameservers[$server_key])) return false;
        return $this->enabled_gameservers[$server_key]->AdminMessage($message, $sender);
    }
    /**
     * Sends a direct message to a recipient using the specified sender and message.
     *
     * @param string $recipient The recipient of the direct message.
     * @param string $message The content of the direct message.
     * @param string $sender The sender of the direct message.
     * @param string|int|null $server_key Server for sending the direct message (optional).
     * @return bool Returns true if the direct message was sent successfully, false otherwise.
     */
    public function DirectMessage(string $message, string $sender, string $recipient, string|int|null $server_key = null): bool
    {
        if (is_null($server_key)) return array_reduce($this->enabled_gameservers, fn($carry, $server) => $carry || $server->DirectMessage($message, $sender, $recipient), false);
        if (! isset($this->enabled_gameservers[$server_key])) return false;
        return $this->enabled_gameservers[$server_key]->DirectMessage($message, $sender, $recipient);
    }
    
    /**
     * Checks if a role has a higher position than the bot's role and has the permission to manage roles.
     *
     * @param string $role_id The ID of the role to check.
     * @param Guild $guild The guild object.
     * @return bool Returns true if the role has a higher position and has the permission to manage roles, false otherwise.
     */
    function checkRolePosition(string $role_id, Guild $guild): bool
    {
        if ($role_id == $guild->id) return false;
        if (! $bot = $guild->members->get('id', $this->discord->id)) return false;
        if (! $role = $guild->roles->get('id', $role_id)) return false;
        foreach ($bot->roles as $brole) if ($brole->position > $role->position && $brole->permissions->manage_roles) return true;
        return false;
    }
    /**
     * Compares the position of two Role objects and returns the result.
     *
     * @param Role $role The first Role object to compare.
     * @param Role $role2 The second Role object to compare.
     * @return int Returns -1 if $role is positioned before $role2, 0 if they have the same position, and 1 if $role is positioned after $role2.
     */
    function comparePositionTo(Role $role, Role $role2): int
    {
        if ($role->position === $role2->position) return $role2->id <=> $role->id;
        return $role->position <=> $role2->position;
    }
    /**
     * Returns the highest role from a collection of roles.
     *
     * @param Collection<Role> $roles The collection of roles.
     * @return Role|null The highest role, or null if the collection is empty.
     */
    function getHighestRole(Collection $roles): ?Role
    {
        return array_reduce($roles->toArray(), fn($prev, $role) => ($prev === null ? $role : ($this->comparePositionTo($role, $prev) > 0 ? $role : $prev)));
    }
    /**
     * Checks if a member has a specific rank.
     * The ranks are defined in the bot's config file.
     *
     * @param Member $member The member to check.
     * @param array $allowed_ranks The allowed ranks. Defaults to ['Owner', 'Chief Technical Officer', 'Ambassador'].
     * @return bool Returns true if the member has any of the allowed ranks, false otherwise.
     */
    function hasRank(Member $member, array $allowed_ranks = ['Owner', 'Chief Technical Officer', 'Ambassador']): bool
    {
        $resolved_ranks = array_map(fn($rank) => isset($this->role_ids[$rank]) ? $this->role_ids[$rank] : null, $allowed_ranks);
        return count(array_filter($resolved_ranks, fn($rank) => $member->roles->has($rank))) > 0;
    }
    /**
     * Retrieves the Role object based on the given input.
     *
     * @param string $input The input to search for the Role.
     * @return Role|null The Role object if found, or null if not found.
     */
    public function getRole(string $input): ?Role
    {
        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return null;
        return is_numeric($input = self::sanitizeInput($input))
            ? $guild->roles->get('id', $input)
            : $guild->roles->get('name', $input);
    }

    private function declareListeners(): void
    {
        $this->discord->on('GUILD_MEMBER_ADD', function (Member $member): void
        {
            ! empty($this->functions['GUILD_MEMBER_ADD']) && array_walk($this->functions['GUILD_MEMBER_ADD'], fn($func) => $func($this, $member));
        });

        $this->discord->on('GUILD_MEMBER_REMOVE', function (Member $member): void
        {
            ! empty($this->functions['GUILD_MEMBER_REMOVE']) && array_walk($this->functions['GUILD_MEMBER_REMOVE'], fn($func) => $func($this, $member));
        });

        $this->discord->on('GUILD_MEMBER_UPDATE', function (Member $member, Discord $discord, ?Member $member_old): void
        {
            ! empty($this->functions['GUILD_MEMBER_UPDATE']) && array_walk($this->functions['GUILD_MEMBER_UPDATE'], fn($func) => $func($this, $member));
        });

        $this->discord->on('GUILD_CREATE', function (Guild $guild): void
        {
            ! empty($this->functions['GUILD_CREATE']) && array_walk($this->functions['GUILD_CREATE'], fn($func) => $func($this, $guild));
        });
    }
    
    /**
     * These functions are used to save and load data to and from files.
     * Please maintain a consistent schema for directories and files
     *
     * The bot's $filecache_path should be a folder named json inside of either cwd() or __DIR__
     * getcwd() should be used if there are multiple instances of this bot operating from different source directories but share the same bot files (NYI)
     * __DIR__ should be used if the json folder should be expected to always be in the same folder as this file, but only if this bot is not installed inside of /vendor/
     *
     * The recommended schema is to follow DiscordPHP's Redis schema, but replace : with ;
     * dphp:cache:Channel:115233111977099271:1001123612587212820 would become dphp;cache;Channel;115233111977099271;1001123612587212820.json
     * In the above example the first set of numbers represents the guild_id and the second set of numbers represents the channel_id
     * Similarly, Messages might be cached like dphp;cache;Message;11523311197709927;234582138740146176;1014616396270932038.json where the third set of numbers represents the message_id
     * This schema is recommended because the expected max length of the file name will not usually exceed 80 characters, which is far below the NTFS character limit of 255,
     * and is still generic enough to easily automate saving and loading files using data served by Discord
     *
     * Windows users may need to enable long path in Windows depending on whether the length of the installation path would result in subdirectories exceeding 260 characters
     * Click Window key and type gpedit.msc, then press the Enter key. This launches the Local Group Policy Editor
     * Navigate to Local Computer Policy > Computer Configuration > Administrative Templates > System > Filesystem
     * Double click Enable NTFS long paths
     * Select Enabled, then click OK
     *
     * If using Windows 10/11 Home Edition, the following commands need to be used in an elevated command prompt before continuing with gpedit.msc
     * FOR %F IN ("%SystemRoot%\servicing\Packages\Microsoft-Windows-GroupPolicy-ClientTools-Package~*.mum") DO (DISM /Online /NoRestart /Add-Package:"%F")
     * FOR %F IN ("%SystemRoot%\servicing\Packages\Microsoft-Windows-GroupPolicy-ClientExtensions-Package~*.mum") DO (DISM /Online /NoRestart /Add-Package:"%F")
     */
    
    /**
     * Saves an associative array to a file in JSON format.
     *
     * @param string $filename The name of the file to save to.
     * @param array $assoc_array The associative array to be saved.
     * @return bool Returns true if the data was successfully saved, false otherwise.
     */
    public function VarSave(string $filename, array $assoc_array = []): bool
    {
        return OSFunctions::VarSave($this->filecache_path, $filename, $assoc_array, $this->logger);
    }
    /**
     * Loads an associative array from a file that was saved in JSON format.
     *
     * @param string $filename The name of the file to load from.
     * @return array|null Returns the associative array that was loaded, or null if the file does not exist or could not be loaded.
     */
    public function VarLoad(string $filename = ''): ?array
    {
        return OSFunctions::VarLoad($this->filecache_path, $filename, $this->logger);
    }
    /**
     * This function is used to navigate a file tree and find a file
     *
     * @param string $basedir The directory to start in
     * @param array $subdirs An array of subdirectories to navigate
     * @return array Returns an array with the first element being a boolean indicating if the file was found, and the second element being either an array of files in the directory or the path to the file if it was found
     */
    public function FileNav(string $basedir, array $subdirs): array
    {
        $scandir = scandir($basedir);
        unset($scandir[1], $scandir[0]);
        if (! $subdir = array_shift($subdirs)) return [false, $scandir];
        if (! in_array($subdir = trim($subdir), $scandir)) return [false, $scandir, $subdir];
        if (is_file("$basedir/$subdir")) return [true, "$basedir/$subdir"];
        return $this->FileNav("$basedir/$subdir", $subdirs);
    }

    /**
     * Retrieves the age associated with the given ckey.
     *
     * @param string $ckey The ckey to retrieve the age for.
     * @return string|false The age associated with the ckey, or false if not found.
     */
    public function getByondAge(string $ckey): string|false
    {
        if (isset($this->ages[$ckey])) return $this->ages[$ckey];
        if (! isset($this->byond)) {
            $this->logger->warning('BYOND object not set!');
            return false;
        }
        if ($age = Byond::getJoined($ckey)) {
            $this->ages[$ckey] = $age;
            $this->VarSave('ages.json', $this->ages);
            return $age;
        }
        return false;
    }
    /**
     * This function is used to determine if a BYOND account is old enough to play on the server.
     *
     * @param string $age The age of the BYOND account in the format "YYYY-MM-DD".
     * @return bool Returns true if the account is old enough, false otherwise.
     */
    public function checkByondAge(string $age): bool
    {
        return strtotime($age) <= strtotime($this->minimum_age);
    }
    /**
     * Sends a message containing the list of bans for all servers.
     *
     * @param Message $message The message object.
     * @param ?string $key The key of the gameserver to list bans for.
     * @return PromiseInterface
     */
    public function listbans(Message $message, ?string $key = null): PromiseInterface 
    {
        $servers = $key ? [$this->enabled_gameservers[$key] ?? null] : $this->enabled_gameservers;
        return ($banlists = array_reduce(array_filter($servers), fn($carry, $gameserver) => $gameserver->merge_banlist($carry), []))
            ? $message->reply(
                array_reduce(
                    array_keys($banlists),
                    fn($builder, $key) => $builder->addFileFromContent("{$key}_bans.txt", $banlists[$key]),
                    MessageBuilder::new()
                )->setContent('Ban lists for: ' . implode(', ', array_keys($banlists)))
              )
            : $message->react("🔥")->then(fn() => $this->logger->warning("Unable to list bans for servers: " . implode(', ', array_keys($banlists))));
    }
    /**
     * Every 12 hours, this function checks if a user is banned and removes the banished role from them if they are not.
     * It loops through all the members in the guild and checks if they have the banished role.
     * If they are not been banned, it removes the banished role from them.
     * If the staff_bot channel exists, it sends a message to the channel indicating that the banished role has been removed from the member.
     *
     * @return bool Returns TimerInterface if the function executes successfully, false otherwise.
     */
    public function bancheckTimer(): TimerInterface|false
    {
        // We don't want the persistence server to do this function
        if (! $this->enabled_gameservers) return false; // This function should only run if there are servers to check
        if (! array_reduce($this->enabled_gameservers, function ($carry, $gameserver) { // Check if the ban files exist and create them if they don't
            if (! @file_exists($path = $gameserver->basedir . self::bans) || ! @touch($path)) {
                $this->logger->warning("unable to open `$path`");
                return $carry;
            }
            return true;
        }, false)) return false;
        $this->__bancheckTimer();
        if (! isset($this->timers['bancheck_timer']) || ! isset($this->timers['bancheck_timer']) instanceof TimerInterface) $this->timers['bancheck_timer'] = $this->discord->getLoop()->addPeriodicTimer(43200, fn() => $this->bancheckTimer());
        return $this->timers['bancheck_timer'];
    }
    private function __bancheckTimer(): void
    {
        if (! isset($this->verifier) && isset($this->timers['bancheck_timer'])) {
            ! $this->timers['bancheck_timer'] instanceof TimerInterface ?: $this->loop->cancelTimer($this->timers['bancheck_timer']);
            unset($this->timers['bancheck_timer']);
            return;
        }
        if ($cacheconfig = $this->discord->getCacheConfig()) {
            $interface = $cacheconfig->interface;
            $this->logger->info('Cache type: ' . get_class($interface));
            if ($interface instanceof \React\Cache\CacheInterface) { // It's too expensive to check bans
                $this->logger->info('Redis cache is being used, cancelling periodic banchecks.');
                $this->loop->cancelTimer($this->timers['bancheck_timer']);
                unset($this->timers['bancheck_timer']);
                return;
            }
        }
        $this->logger->debug('Running periodic bancheck...');
        array_walk($this->enabled_gameservers, fn($gameserver) => $gameserver->cleanupLogs());
        if (isset($this->role_ids['Banished']) && $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) foreach ($guild->members as $member) {
            if (! $item = $this->verifier->getVerifiedMemberItems()->get('discord', $member->id)) continue;
            if (! isset($item['ss13'])) continue;
            //$this->logger->debug("Checking bans for {$item['ss13']}...");
            if (($banned = $this->bancheck($item['ss13'], true, true)) && ! ($member->roles->has($this->role_ids['Banished']) || $member->roles->has($this->role_ids['Permabanished']))) {
                $member->addRole($this->role_ids['Banished'], 'bancheck timer');
                if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, "Added the banished role to $member.");
            } elseif (! $banned && ($member->roles->has($this->role_ids['Banished']) || $member->roles->has($this->role_ids['Permabanished']))) {
                $member->removeRole($this->role_ids['Banished'], 'bancheck timer');
                $member->removeRole($this->role_ids['Permabanished'], 'bancheck timer');
                if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, "Removed the banished role from $member.");
            }
        }
        $this->logger->debug('Periodic bancheck complete.');
    }
    /**
     * Determines whether a ckey is currently banned from the server.
     *
     * This function is called when a user is verified to determine whether they should be given the banished role or have it taken away.
     * It checks the nomads_bans.txt and tdm_bans.txt files for the ckey.
     *
     * @param string $ckey The ckey to check for banishment.
     * @param bool $bypass (optional) If set to true, the function will not add or remove the banished role from the user.
     * @return bool Returns true if the ckey is found in either ban file, false otherwise.
     */
    public function bancheck(string $ckey, bool $bypass = false, bool $use_cache = false): bool
    {
        if (! $ckey = self::sanitizeInput($ckey)) return false;
        $banned = array_reduce($this->enabled_gameservers, fn($carry, $gameserver) => $carry || $gameserver->bancheck($ckey, false, $use_cache), false);
        if ($bypass || ! isset($this->verifier) || ! $member = $this->verifier->getVerifiedMember($ckey)) return $banned;
        if ($banned && ! $member->roles->has($this->role_ids['Banished']) && ! $member->roles->has($this->role_ids['Admin'])) $member->addRole($this->role_ids['Banished'], "bancheck ($ckey)");
        elseif (! $banned &&  $member->roles->has($this->role_ids['Banished'])) $member->removeRole($this->role_ids['Banished'], "bancheck ($ckey)");
        return $banned;
    }
    /**
     * Checks if any of the provided keys are banned, excluding a specific key if provided.
     *
     * @param array $ckeys An array of keys to check.
     * @param string|null $exclude A key to exclude from the check, or null to include all keys.
     * @return bool True if any key (excluding the specified key) is banned, otherwise false.
     */
    public function altbancheck(array $ckeys, ?string $exclude = null): bool
    {
        return array_reduce($ckeys, fn($carry, $key) => $carry || ($key !== $exclude && $this->bancheck($key)), false);
    }
    public function permabancheck(string $ckey, bool $bypass = false): bool
    {
        if (! $ckey = self::sanitizeInput($ckey)) return false;
        $permabanned = array_reduce($this->enabled_gameservers, fn($carry, $gameserver) => $carry || $gameserver->permabancheck($ckey), false);
        if ($bypass || ! isset($this->verifier) || ! $member = $this->verifier->getVerifiedMember($ckey)) return $permabanned;
        if ($permabanned && ! $member->roles->has($this->role_ids['Permabanished']) && ! $member->roles->has($this->role_ids['Admin'])) $member->setRoles([$this->role_ids['Banished'], $this->role_ids['Permabanished']], "permabancheck ($ckey)");
        elseif (! $permabanned && $member->roles->has($this->role_ids['Permabanished'])) $member->removeRole($this->role_ids['Permabanished'], "permabancheck ($ckey)");
        return $permabanned;
    }
    /**
     * Paroles or unparoles a player identified by their ckey.
     *
     * @param string $ckey The ckey of the player.
     * @param string $admin The admin who is performing the action.
     * @param bool $state The state of the player's parole. Default is true.
     * @return array The updated list of paroled players.
     */
    public function paroleCkey(string $ckey, string $admin, bool $state = true): array
    {
        if ($state) $this->paroled[$ckey] = $admin;
        else unset($this->paroled[$ckey]);
        $this->VarSave('paroled.json', $this->paroled);
        return $this->paroled;
    }
    public function __panicBan(string $ckey): void
    {
        if (! $this->bancheck($ckey, true)) {
            foreach ($this->enabled_gameservers as &$gameserver) {
                if (! $gameserver->panic_bunker) continue;
                $gameserver->ban(['ckey' => $ckey, 'duration' => '1 hour', 'reason' => "The server is currently restricted. You must come to Discord and link your byond account before you can play: {$this->discord_formatted}"]);
                $this->panic_bans[$ckey] = true;                
            }
            $this->VarSave('panic_bans.json', $this->panic_bans);
        }
    }
    public function __panicUnban(string $ckey): void
    {
        foreach ($this->enabled_gameservers as &$gameserver) {
            if (! $gameserver->panic_bunker) continue;
            $gameserver->unban($ckey);
            unset($this->panic_bans[$ckey]);
        }
        $this->VarSave('panic_bans.json', $this->panic_bans);
    }
    /*
     * These functions determine which of the above methods should be used to process a ban or unban
     * Ban functions will return a string containing the results of the ban
     * Unban functions will return nothing, but may contain error-handling messages that can be passed to $logger->warning()
     */
    public function ban(array $array /* = ['ckey' => '', 'duration' => '', 'reason' => ''] */, ?string $admin = null, string|int|null $server = null, bool $permanent = false): string
    {
        if (! isset($array['ckey'])) return "You must specify a ckey to ban.";
        if (! is_numeric($array['ckey']) && ! is_string($array['ckey'])) return "The ckey must be a Byond username or Discord ID.";
        if (! isset($array['duration'])) return "You must specify a duration to ban for.";
        if ($array['duration'] === '999 years') $permanent = true;
        if (! isset($array['reason'])) return "You must specify a reason for the ban.";
        $array['ckey'] = self::sanitizeInput($array['ckey']);
        if (is_numeric($array['ckey'])) {
            if (isset($this->verifier) && ! $item = $this->verifier->get('discord', $array['ckey'])) return "Unable to find a ckey for <@{$array['ckey']}>. Please use the ckey instead of the Discord ID.";
            $array['ckey'] = $item['ss13'];
        }
        if (isset($this->verifier) && $member = $this->verifier->getVerifiedMember($array['ckey'])) if (! $member->roles->has($this->role_ids['Banished'])) {
            if (! $permanent) $member->addRole($this->role_ids['Banished'], "Banned for {$array['duration']} with the reason {$array['reason']}");
            else $member->setRoles([$this->role_ids['Banished'], $this->role_ids['Permabanished']], "Banned for {$array['duration']} with the reason {$array['reason']}");
        }
        $return = '';
        if (is_null($server)) foreach ($this->enabled_gameservers as &$gameserver) $return .= $gameserver->ban($array, $admin, $permanent);
        elseif (isset($this->enabled_gameservers[$server])) $return .= $this->enabled_gameservers[$server]->ban($array, $admin, $permanent);
        else $return .= "Invalid server specified for ban.";
        return $return;
    }
    public function unban(string $ckey, ?string $admin = null, string|array|null $gameserver = null): PromiseInterface
    {
        $admin ??= $this->discord->username;
        if (is_null($gameserver)) foreach ($this->enabled_gameservers as &$gameserver) $this->unban($ckey, $admin, $gameserver->key);
        elseif(isset($this->enabled_gameservers[$gameserver])) $this->enabled_gameservers[$gameserver]->unban($ckey, $admin);
        else {
            $this->logger->warning($err = "Invalid server specified for unban.");
            return reject(new \InvalidArgumentException($err));
        }
        if (isset($this->verifier) && $member = $this->verifier->getVerifiedMember($ckey)) {
            if ($member->roles->has($this->role_ids['Banished'])) $member->removeRole($this->role_ids['Banished'], "Unbanned by $admin");
            if ($member->roles->has($this->role_ids['Permabanished'])) $member->removeRole($this->role_ids['Permabanished'], "Unbanned by $admin")->then(fn() => $member->addRole($this->role_ids['Verified'], "Unbanned by $admin"));
        }
        return resolve(null);
    }

    /**
     * Retrieves IP data, checking for expiration based on TTL.
     *
     * @param string $ip The IP address to retrieve data for.
     * @return array The IP data.
     */
    public function getIpData(string $ip): array
    {
        $currentTime = time();
        if (isset($this->ip_data[$ip])) 
            if ((($currentTime) - $this->ip_data[$ip]['timestamp']) <= Civ13::IP_DATA_TTL)
                return $this->ip_data[$ip];
        $ip_data = IPToCountryResolver::Online($ip);
        $ip_data['timestamp'] = $currentTime;
        $this->ip_data[$ip] = $ip_data;
        $this->VarSave('ip_data.json', $this->ip_data);
        return $ip_data;
    }

    /**
     * Retrieves information about a given ckey.
     *
     * @param string $ckey The ckey to retrieve information for.
     * @return array An array containing the ckeys, ips, cids, banned status, altbanned status, verification status, and associated discords. (array[array, array, array, bool, bool, bool])
     */
    public function ckeyinfo(string $ckey, int $max_iterations = 10): array
    {
        if (! $ckey = self::sanitizeInput($ckey)) return [null, null, null, false, false];
        if (! $collectionsArray = $this->getCkeyLogCollections($ckey)) return [null, null, null, false, false];
        if ($item = $this->verifier->getVerifiedItem($ckey)) $ckey = $item['ss13'];
         // Get the ckey's primary identifiers
        $ckeys = [$ckey];
        $ips   = [];
        $cids  = [];
        foreach (['playerlogs', 'bans'] as $type) foreach ($collectionsArray[$type] as $log) {
            if (isset($log['ip'])  && ! in_array($log['ip'],  $ips))  $ips[]  = $log['ip'];
            if (isset($log['cid']) && ! in_array($log['cid'], $cids)) $cids[] = $log['cid'];
        }
        for ($i = 0; $i < $max_iterations; $i++) { // Iterate through the player logs and ban logs to find all known ckeys, ips, and cids
            $found_ckeys = [];
            $found_ips   = [];
            $found_cids  = [];
            if ( // If no new values are found, break the loop early
                   ! $this->__processLogs($this->playerlogsToCollection(), $found_ckeys, $found_ips, $found_cids, $ckeys, $ips, $cids)
                && ! $this->__processLogs($this->bansToCollection(),       $found_ckeys, $found_ips, $found_cids, $ckeys, $ips, $cids)
            ) break;
            $ckeys = array_unique(array_merge($ckeys, $found_ckeys));
            $ips   = array_unique(array_merge($ips,   $found_ips  ));
            $cids  = array_unique(array_merge($cids,  $found_cids ));
        }

        return [
            'ckeys'     => $ckeys,
            'ips'       => $ips,
            'cids'      => $cids,
            'banned'    => $this->bancheck($ckey),
            'altbanned' => array_reduce($ckeys, fn($carry, $key) => $carry || ($key !== $ckey && $this->bancheck($key)), false),
            'discords'  => array_filter(array_map(fn($key) => $this->verifier->get('ss13', $key)['discord'] ?? null, $ckeys)),
            'verified'  => ! empty($discords)
        ];
    }
    private function __processLogs($logs, &$found_ckeys, &$found_ips, &$found_cids, $ckeys, $ips, $cids): bool
    {
        $found = false;
        $ckeys_set = array_flip($ckeys);
        $ips_set = array_flip($ips);
        $cids_set = array_flip($cids);
        foreach ($logs as $log) if (isset($ckeys_set[$log['ckey']]) || isset($ips_set[$log['ip']]) || isset($cids_set[$log['cid']])) {
            if (! isset($ckeys_set[$log['ckey']])) {
                $found_ckeys[] = $log['ckey'];
                $ckeys_set[$log['ckey']] = true;
                $found = true;
            }
            if (! isset($ips_set[$log['ip']])) {
                $found_ips[] = $log['ip'];
                $ips_set[$log['ip']] = true;
                $found = true;
            }
            if (! isset($cids_set[$log['cid']])) {
                $found_cids[] = $log['cid'];
                $cids_set[$log['cid']] = true;
                $found = true;
            }
        }
        return $found;
    }
    /**
     * Updates the provided arrays of ckeys, ips, cids, and dates with new values found in the logs.
     * This function iterates through the logs to find all known ckeys, ips, and cids, and updates the provided arrays accordingly.
     * It also ensures that no duplicates are added and prevents infinite loops by limiting the recursion depth.
     *
     * @param \Traversable  $logs               The logs to be processed.
     * @param array        &$ckeys              Reference to the array of ckeys to be updated.
     * @param array        &$ips                Reference to the array of ips to be updated.
     * @param array        &$cids               Reference to the array of cids to be updated.
     * @param array        &$dates              Reference to the array of dates to be updated.
     * @param array        &$found_ckeys        Reference to the array of found ckeys to be updated.
     * @param array        &$found_ips          Reference to the array of found ips to be updated.
     * @param array        &$found_cids         Reference to the array of found cids to be updated.
     * @param array        &$found_dates        Reference to the array of found dates to be updated.
     * @param bool          $update_found_ckeys Flag to determine if found ckeys should be updated.
     * @param int           $i                  The current recursion depth.
     * @param bool          $found              Flag to indicate if new values were found in the current iteration.
     *
     * @return void
     */
    public static function updateCkeyinfoVariables(
        \Traversable $logs,
        array &$ckeys, array &$ips, array &$cids, array &$dates,
        array &$found_ckeys, array &$found_ips, array &$found_cids, array &$found_dates,
        bool $update_found_ckeys = true,
        int $i = 0, bool $found = false // Passed internally within the function, not to be provided by the user
        ): void
    {
        // Iterate through logs to find all known ckeys, ips, and cids
        foreach ($logs as $log) if (isset($ckeys[$log['ckey']]) || isset($ips[$log['ip']]) || isset($cids[$log['cid']])) {
            if (isset($log['ckey']) === $update_found_ckeys)
            if (isset($log['ckey']) && ! isset($ckeys[$log['ckey']], $found_ckeys[$log['ckey']])) { $found_ckeys[$log['ckey']] = Civ13::sanitizeInput($log['ckey']); $found = true; }
            if (isset($log['ip'  ]) && ! isset($ips  [$log['ip'  ]], $found_ips  [$log['ip'  ]])) { $found_ips  [$log['ip'  ]] = $log['ip'  ]; $found = true; }
            if (isset($log['cid' ]) && ! isset($cids [$log['cid' ]], $found_cids [$log['cid' ]])) { $found_cids [$log['cid' ]] = $log['cid' ]; $found = true; }
            if (isset($log['date']) && ! isset($dates[$log['date']], $found_dates[$log['date']])) { $found_dates[$log['date']] = $log['date']; }
        }

        if ($ckeys !== $found_ckeys) $ckeys = array_unique(array_merge($ckeys, $found_ckeys));
        if ($ips   !== $found_ips  ) $ips   = array_unique(array_merge($ips,   $found_ips  ));
        if ($cids  !== $found_cids ) $cids  = array_unique(array_merge($cids,  $found_cids ));
        if ($dates !== $found_dates) $dates = array_unique(array_merge($dates, $found_dates));

        if (++$i > 10 || ! $found) return; // Helps to prevent infinite loops, just in case
        self::updateCkeyinfoVariables($logs, $ckeys, $ips, $cids, $dates, $found_ckeys, $found_ips, $found_cids, $found_dates, $update_found_ckeys, $i, $found); // Recursively call the function until no new ckeys, ips, or cids are found
    }
    public function ckeyinfoEmbed(string $ckey, ?array $ckeyinfo = null): Embed
    {
        if (! $ckeyinfo) $ckeyinfo = $this->ckeyinfo($ckey);
        $embed = $this->createEmbed()->setTitle($ckey);
        if (isset($this->verifier) && $user = $this->verifier->getVerifiedUser($ckey)) $embed->setAuthor("{$user->username} ({$user->id})", $user->avatar);
        if (! empty($ckeyinfo['ckeys']))    $embed->addFieldValues('Ckeys',   implode(', ', array_map(fn($ckey) => isset($this->ages[$ckey]) ? "$ckey ({$this->ages[$ckey]})" : $ckey, $ckeyinfo['ckeys'])));
        if (! empty($ckeyinfo['discords'])) $embed->addfieldValues('Discord', implode(', ', array_map(fn($id) => $id ? "<@{$id}>" : $id, $ckeyinfo['discords'])), true);
        if (! empty($ckeyinfo['ips']))      $embed->addFieldValues('IPs',     implode(', ', $ckeyinfo['ips']), true);
        if (! empty($ckeyinfo['cids']))     $embed->addFieldValues('CIDs',    implode(', ', $ckeyinfo['cids']), true);
        if (! empty($ckeyinfo['ips']))      $embed->addFieldValues('Regions', implode(', ', array_unique(array_map(fn($ip) => $this->getIpData($ip)['countryCode'] ?? 'unknown', $ckeyinfo['ips']))), true);
        $embed->addfieldValues('Verified',         $ckeyinfo['verified']  ? 'Yes' : 'No');
        $embed->addfieldValues('Currently Banned', $ckeyinfo['banned']    ? 'Yes' : 'No', true);
        $embed->addfieldValues('Alt Banned',       $ckeyinfo['altbanned'] ? 'Yes' : 'No', true);
        $embed->addfieldValues('Ignoring banned alts or new account age', isset($this->permitted[$ckey]) ? 'Yes' : 'No', true);
        return $embed;
    }
    /**
     * Allows a ckey to bypass the panic bunker.
     *
     * @param string $ckey The ckey to permit or revoke access for.
     * @param bool $allow Whether to allow or revoke access for the ckey.
     * @return array The updated list of permitted ckeys.
     */
    public function permitCkey(string $ckey, bool $allow = true): array
    {
        if ($allow) $this->permitted[$ckey] = true;
        else unset($this->permitted[$ckey]);
        $this->VarSave('permitted.json', $this->permitted);
        return $this->permitted;
    }
    /**
     * Soft bans a user by adding their ckey to the softbanned array or removes them from it if $allow is false.
     * 
     * @param string $ckey The key of the user to be soft banned.
     * @param bool $allow Whether to add or remove the user from the softbanned array.
     * @return array The updated softbanned array.
     */
    public function softban(string $id, bool $allow = true): array
    {
        if ($allow) $this->softbanned[$id] = true;
        else unset($this->softbanned[$id]);
        $this->VarSave('softbanned.json', $this->softbanned);
        return $this->softbanned;
    }

    public function bansToCollection($log_collection = new Collection([], 'increment'), int $increment = 0): Collection
    {
        foreach ($this->enabled_gameservers as &$gameserver) {
            if (! @file_exists($file_path = $gameserver->basedir . self::bans) || ! $file_contents = @file_get_contents($file_path)) {
                $this->logger->warning("Unable to open '{$file_path}'");
                continue;
            }

            foreach (explode('|||', str_replace(PHP_EOL, '', $file_contents)) as $item) {
                if ($ban = $this->banArrayToAssoc(explode(';', $item))) {
                    $ban['increment'] = ++$increment;
                    $log_collection->pushItem($ban);
                }
            }
        }

        return $log_collection;
    }

    public function playerlogsToCollection(&$log_collection = new Collection([], 'increment'), int &$increment = 0): Collection
    {
        foreach ($this->enabled_gameservers as &$gameserver) {
            if (! @file_exists($file_path = $gameserver->basedir . self::playerlogs) || ! $file_contents = @file_get_contents($file_path)) {
                $this->logger->warning("Unable to open '{$file_path}'");
                continue;
            }
            foreach (explode('|', str_replace(PHP_EOL, '', $file_contents)) as $item) {
                if ($log = $this->playerlogArrayToAssoc(explode(';', $item))) {
                    $log['increment'] = ++$increment;
                    $log_collection->pushItem($log);
                }
            }
        }
        return $log_collection;
    }

    /*
     * Creates a Collection from the bans file
     * Player logs are formatting by the following:
     *   0 => Ban Type
     *   1 => Job
     *   2 => Ban UID
     *   3 => Reason
     *   4 => Banning admin
     *   5 => Date when banned
     *   6 => timestamp?
     *   7 => when expires
     *   8 => banned ckey
     *   9 => banned cid
     *   10 => ip
     */
    public function banArrayToAssoc(array $item): ?array
    {
        if (count($item) !== 11) return null;

        $ban = [];
        $ban['type'] = $item[0];
        $ban['job'] = $item[1];
        $ban['uid'] = $item[2];
        $ban['reason'] = $item[3];
        $ban['admin'] = $item[4];
        $ban['date'] = $item[5];
        $ban['timestamp'] = $item[6];
        $ban['expires'] = $item[7];
        $ban['ckey'] = $item[8];
        $ban['cid'] = $item[9];
        $ban['ip'] = $item[10];

        return $ban;
    }
    public function banArrayToObj(array $ban): ?Ban
    {
        if (count($ban) !== 11) return null;
        return new Ban(implode(';', $ban));
    }
    /*
     * Creates a Collection from the playerlogs file
     * Player logs are formatting by the following:
     *   0 => Ckey
     *   1 => IP
     *   2 => CID
     *   3 => UID?
     *   4 => Date
     */
    public function playerlogArrayToAssoc(array $item): ?array
    {
        if (count($item) !== 5) return null;

        $playerlog = [];
        $playerlog['ckey'] = $item[0];
        $playerlog['ip'] = $item[1];
        $playerlog['cid'] = $item[2];
        $playerlog['uid'] = $item[3];
        $playerlog['date'] = $item[4];

        return $playerlog;
    }
    public function getCkeyLogCollections(string $ckey): ?array
    {
        return (
                ($playerlog = $this->playerlogsToCollection()->filter(fn(array $item) => $item['ckey'] === $ckey))
                && ($bans = $this->bansToCollection()->filter(fn(array $item) => $playerlog->get('ckey', $item['ckey']) || $playerlog->get('ip', $item['ip']) || $playerlog->get('cid', $item['cid'])))
            ) ? ['playerlogs' => $playerlog, 'bans' => $bans] : [];
    }

    public function statusChannelUpdate(string $channel, bool $status): ?PromiseInterface
    {
        if (! $channel = $this->discord->getChannel($this->channel_ids['webserver-status'])) return null;
        [$webserver_name, $reported_status] = explode('-', $channel->name);
        if ($reported_status === ($status = $status ? 'online' : 'offline')) return null;        
        //if ($status === 'offline') $msg .= PHP_EOL . "Webserver technician <@{$this->technician_id}> has been notified.";
        $channel->name = "{$webserver_name}-{$status}";
        return $this->then(
            $channel->guild->channels->save($channel),
            fn(Channel $channel) => $this->sendMessage($this->discord->getChannel($channel->id), "Webserver is now **{$status}**.")
        );
    }
    /**
     * Fetches server information from the specified URL.
     *
     * @return array The server information as an associative array.
     * @deprecated Use Gameserver properties and functions instead.
     */
    public function serverinfoFetch(): array
    {
        if (! $data_json = @json_decode(@file_get_contents($this->serverinfo_url, false, stream_context_create(['http' => ['connect_timeout' => 5]])),  true)) {
            $this->statusChannelUpdate($this->channel_ids['webserver-status'], $this->webserver_online = false);
            return [];
        }
        $this->statusChannelUpdate($this->channel_ids['webserver-status'], $this->webserver_online = true);
        return $this->serverinfo = $data_json;
    }
    /**
     * Updates the whitelist based on the member roles.
     *
     * @param array|null $required_roles The required roles for whitelisting. Default is ['Verified'].
     * @return bool Returns true if the whitelist update is successful, false otherwise.
     */
    public function whitelistUpdate(?array $required_roles = ['Verified']): bool
    {
        return array_reduce($this->enabled_gameservers, fn($carry, $gameserver) => $gameserver->whitelistUpdate($required_roles) || $carry, false);
    }
    /**
     * Updates the faction list based on the required roles.
     *
     * @param array|null $required_roles The required roles for updating the faction list.
     * @return bool Returns true if the faction list is successfully updated, false otherwise.
     */
    public function factionlistUpdate(?array $required_roles = null): bool
    {
        return array_reduce($this->enabled_gameservers, fn($carry, $gameserver) => $gameserver->factionlistUpdate() || $carry, false);
    }
    /**
     * Updates admin lists with required roles and permissions.
     *
     * @param array $required_roles An array of required roles and their corresponding permissions. (Defined in Gameserver.php)
     * @return bool Returns true if the update was successful, false otherwise.
     */
    public function adminlistUpdate(?array $required_roles = null): bool
    {
        return array_reduce($this->enabled_gameservers, fn($carry, $gameserver) => $gameserver->adminlistUpdate($required_roles) || $carry, false);
    }

    // Magic Methods
    public function __destruct()
    {
        foreach ($this->timers as $timer) $this->loop->cancelTimer($timer);
    }
    public function __toString(): string
    {
        return self::class;
    }
}