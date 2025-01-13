<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use \Exception;
use Civ13\Civ13;
use Clue\React\Redis\Factory as Redis;
use Discord\Discord;
use Discord\Stats;
use Discord\Builders\MessageBuilder;
use Discord\Helpers\CacheConfig;
use Discord\WebSockets\Intents;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Loop;
use React\Filesystem\Factory as FilesystemFactory;
use React\Http\Browser;
use WyriHaximus\React\Cache\Redis as RedisCache;

define('CIVILIZATIONBOT_START', microtime(true));
ini_set('display_errors', 1);
error_reporting(E_ALL);

set_time_limit(0);
ignore_user_abort(1);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1'); // Unlimited memory usage
define('MAIN_INCLUDED', 1); // Token and SQL credential files may be protected locally and require this to be defined to access

//if (! $token_included = require getcwd() . '/token.php') // $token
    //throw new \Exception('Token file not found. Create a file named token.php in the root directory with the bot token.');
if (! $autoloader = require file_exists(__DIR__.'/vendor/autoload.php') ? __DIR__.'/vendor/autoload.php' : __DIR__.'/../../autoload.php')
    throw new \Exception('Composer autoloader not found. Run `composer install` and try again.');
function loadEnv(string $filePath = __DIR__ . '/.env'): void
{
    if (! file_exists($filePath)) throw new Exception("The .env file does not exist.");

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $trimmedLines = array_map('trim', $lines);
    $filteredLines = array_filter($trimmedLines, fn($line) => $line && ! str_starts_with($line, '#'));

    array_walk($filteredLines, function($line) {
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (! array_key_exists($name, $_ENV)) putenv(sprintf('%s=%s', $name, $value));
    });
}
loadEnv(getcwd() . '/.env');

$streamHandler = new StreamHandler('php://stdout', Level::Info);
$streamHandler->setFormatter(new LineFormatter(null, null, true, true, true));
$logger = new Logger('Civ13', [$streamHandler]);
file_put_contents('output.log', ''); // Clear the contents of 'output.log'
$logger->pushHandler(new StreamHandler('output.log', Level::Info));
$logger->info('Loading configurations for the bot...');

$discord = new Discord([
    'loop' => $loop = Loop::get(),
    'logger' => $logger,
    /*
    'cache' => new CacheConfig(
        $interface = new RedisCache(
            (new Redis($loop))->createLazyClient('127.0.0.1:6379'),
            'dphp:cache:
        '),
        $compress = true, // Enable compression if desired
        $sweep = false // Disable automatic cache sweeping if desired
    ),
    */
    'socket_options' => [
        'dns' => '8.8.8.8', // can change dns
    ],
    'token' => getenv('TOKEN'),
    'loadAllMembers' => true,
    'storeMessages' => true, // Because why not?
    'intents' => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS | Intents::MESSAGE_CONTENT,
]);

$stats = Stats::new($discord);
$browser = new Browser($loop);
$filesystem = FilesystemFactory::create($loop);
include 'variable_functions.php';

// TODO: Add a timer and a callable function to update these IP addresses every 12 hours
$civ13_ip = gethostbyname('www.civ13.com');
$vzg_ip = gethostbyname('www.valzargaming.com');
$val_ip = gethostbyname('www.valgorithms.com');
$http_whitelist = [$civ13_ip, $vzg_ip, $val_ip, '50.25.53.244'];

$webapi = null;
$socket = null;

/* Format:
    'word' => 'bad word' // Bad word to look for
    'duration' => duration ['1 minute', '1 hour', '1 day', '1 week', '1 month', '999 years'] // Duration of the ban
    'reason' => 'reason' // Reason for the ban
    'category' => rule category ['racism/discrimination', 'toxic', 'advertisement'] // Used to group bad words together by category
    'method' => detection method ['exact', 'str_contains', 'str_ends_with', 'str_starts_with'] // Exact ignores partial matches, str_contains matches partial matches, etc.
    'warnings' => 1 // Number of warnings before a ban
*/
$ic_badwords = $ooc_badwords = [
    //['word' => 'badwordtestmessage', 'duration' => '1 minute', 'reason' => 'Violated server rule.', 'category' => 'test', 'method' => 'str_contains', 'warnings' => 1], // Used to test the system

    ['word' => 'beaner',      'duration' => '999 years',  'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
    ['word' => 'chink',       'duration' => '999 years',  'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
    ['word' => 'coon',        'duration' => '999 years',  'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'exact', 'warnings' => 1],
    ['word' => 'fag',         'duration' => '999 years',  'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
    ['word' => 'gook',        'duration' => '999 years',  'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
    ['word' => 'kike',        'duration' => '999 years',  'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
    ['word' => 'nigg',        'duration' => '999 years',  'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
    ['word' => 'nlgg',        'duration' => '999 years',  'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
    ['word' => 'niqq',        'duration' => '999 years',  'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
    ['word' => 'tranny',      'duration' => '999 years',  'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],

    ['word' => 'cunt',        'duration' => '1 minute',  'reason' => 'You must not be toxic or too agitated in any OOC communication channels.', 'category' => 'toxic', 'method' => 'exact', 'warnings' => 5],
    ['word' => 'retard',      'duration' => '1 minute',  'reason' => 'You must not be toxic or too agitated in any OOC communication channels.', 'category' => 'toxic', 'method' => 'exact', 'warnings' => 5],
    ['word' => 'stfu',        'duration' => '1 minute',  'reason' => 'You must not be toxic or too agitated in any OOC communication channels.', 'category' => 'toxic', 'method' => 'exact', 'warnings' => 5],
    ['word' => 'kys',         'duration' => '1 week',    'reason' => 'You must not be toxic or too agitated in any OOC communication channels.', 'category' => 'toxic', 'method' => 'exact', 'warnings' => 1], // This is more severe than the others, so ban after only one warning

    ['word' => 'penis',       'duration' => '999 years',  'reason' => 'There is a zero tolerance policy towards any type of lewdness.', 'category' => 'erp', 'method' => 'str_contains', 'warnings' => 1], // This is more severe than the others, so ban after only one warning
    ['word' => 'vagina',      'duration' => '999 years',  'reason' => 'There is a zero tolerance policy towards any type of lewdness.', 'category' => 'erp', 'method' => 'str_contains', 'warnings' => 1], // This is more severe than the others, so ban after only one warning
    ['word' => 'sex',         'duration' => '999 years',  'reason' => 'There is a zero tolerance policy towards any type of lewdness.', 'category' => 'erp', 'method' => 'exact', 'warnings' => 1], // This is more severe than the others, so ban after only one warning
    ['word' => 'cum',         'duration' => '999 years',  'reason' => 'There is a zero tolerance policy towards any type of lewdness.', 'category' => 'erp', 'method' => 'exact', 'warnings' => 1], // This is more severe than the others, so ban after only one warning

    ['word' => 'discord.gg',  'duration' => '999 years', 'reason' => 'You must not post unauthorized Discord invitation links in any OOC communication channels.', 'category' => 'advertisement', 'method' => 'str_contains', 'warnings' => 2],
    ['word' => 'discord.com', 'duration' => '999 years', 'reason' => 'You must not post unauthorized Discord invitation links in any OOC communication channels.', 'category' => 'advertisement', 'method' => 'str_contains', 'warnings' => 2],
    
    ['word' => 'RU',          'duration' => '999 years', 'reason' => 'только английский.', 'category' => 'language', 'method' => 'russian',  'warnings' => 2],
    ['word' => 'CN',          'duration' => '999 years', 'reason' => '仅英语.',             'category' => 'language', 'method' => 'chinese',  'warnings' => 2],
    ['word' => 'KR',          'duration' => '999 years', 'reason' => '영어로만 제공.',       'category' => 'language', 'method' => 'korean',   'warnings' => 2],
];
$options = array(
    'github' => 'https://github.com/VZGCoders/Civilizationbot',
    'command_symbol' => '@Civilizationbot',
    'owner_id' => '196253985072611328', // Taislin
    'technician_id' => '116927250145869826', // Valithor
    'civ13_guild_id' => '468979034571931648', // Civ13
    'discord_invite' => 'https://civ13.com/discord',
    'discord_formatted' => 'civ13.com slash discord',
    'rules' => 'civ13.com slash rules',
    'gitdir' => '/home/civ13/civ13-git', // Path to the git repository
    'legacy' => true, // Whether to use the filesystem or SQL database system
    'moderate' => true, // Whether to moderate in-game chat
    // The Verify URL is where verification requests are sent to and where the verification list is retrieved from
    // The website must return valid json when no parameters are passed to it and MUST allow POST requests including 'token', 'ckey', and 'discord'
    // Reach out to Valithor if you need help setting up your website
    'webserver_url' => 'www.valzargaming.com',
    'verify_url' => 'http://valzargaming.com:8080/verified/', // Leave this blank if you do not want to use the webserver, ckeys will be stored locally as provisional
    // 'serverinfo_url' => '', // URL of the serverinfo.json file, defaults to the webserver if left blank
    'ooc_badwords' => $ooc_badwords,
    'ic_badwords' => $ic_badwords,
    'folders' => array(
        // 'typespess_path' => '/home/civ13/civ13-typespess',
    ),
    'files' => array( // Server-specific file paths MUST start with the server name as defined in server_settings unless otherwise specified
        // 'typespess_launch_server_path' => '/home/civ13/civ13-typespess/scripts/launch_server.sh',
    ),
    'channel_ids' => array(
        'get-approved' => '690025163634376738', #get-approved
        'webserver-status' => '1106967195092783104', #webserver-{status}
        'verifier-status' => '1231988255470125117', #verifier-{status}
        'staff_bot' => '712685552155230278', // #staff-bot
        'parole_logs' => '985606778916048966', // #parole-logs (for tracking)
        'parole_notif' => '977715818731294790', // #parole-notif (for login/logout notifications)
        'email' => '1225600172336353430', // #email
        'ban_appeals' => '1019718839749062687' #ban-appeals
    ),
    'role_ids' => array( // The keys in this array must directly correspond to the expected role names and as defined in Gameserver.php. Do not alter these keys unless you know what you are doing.
        /* Discord Staff Roles */
        'Owner' => '468980650914086913', // Discord Server Owner
        'Chief Technical Officer' => '791450326455681034', // Debug Host / Database admin
        'Host' => '677873806513274880', // Server Host
        'Head Admin' => '487608503553490965', // Deprecation TBD
        //'Manager' => '496004389950193667', // Deprecated
        'Ambassador' => '792826030796308503', // High Staff
        //'Supervisor' => '561770271300911105', // Deprecated
        'Admin' => '468982360659066912',
        //'Moderator' => '823302316743589938', // Deprecated
        //'Mentor' => '469297467918254085', // Deprecated
        'Parolemin' => '743971427929030748', // Parole Admin
        /* Discord Player Roles */
        'Verified' => '468982790772228127', // Verified
        'Banished' => '710328377210306641', // Banned in-game
        'Permabanished' => '1126137099209425017', // Permanently banned in-game
        'Paroled' => '745336314689355796', // On parole
        
        /* Factions */
        'Red Faction' => '1132678312301428886', // Redmenia
        'Blue Faction' => '1132678353070067802', // Blugoslavia
        'Faction Organizer' => '1089060051425165362', // Admin / Faction Organizer
        /* Notification pings */
        'mapswap' => '1200520534262284288', // Map Swap Ping
        'round_start' => '1110597830403424328', // Round Start Ping
        '2+' => '981963719804346418', // LowPopStart
        '15+' => '981963721817620511', // 15+ Popping
        '30+' => '981963696895062106', // 30+ Popping
        /* Server pings (Deprecated) */
        //'tdm' => '753768519203684445',
        //'nomads' => '753768513671397427',
        //'pers' => '753768492834095235',
    ),
);
$options['welcome_message'] = "Welcome to the Civ13 Discord Server! Please read the rules and verify your account using the `/approveme` slash command. Failure to verify in a timely manner will result in an automatic removal from the server.";
/*
foreach (['а', 'б', 'в', 'г', 'д', 'е', 'ё', 'ж', 'з', 'и', 'й', 'к', 'л', 'м', 'н', 'о', 'п', 'р', 'с', 'т', 'у', 'ф', 'х', 'ц', 'ч', 'ш', 'щ', 'ъ', 'ы', 'ь', 'э', 'ю', 'я', 'і', 'ї', 'є'] as $char) { // // Ban use of Cyrillic characters
    $arr = ['word' => $char, 'duration' => '999 years', 'reason' => 'только английский.', 'category' => 'language', 'method' => 'str_contains', 'warnings' => 2];
    $options['ooc_badwords'][] = $arr;
    $options['ic_badwords'][] = $arr;
}
*/

// Write editable configurations to a single JSON file

//$json = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
//file_put_contents("config.json", $json);


// Load configurations from the JSON file
/*
$loadedData = [];
$json = file_get_contents("config.json");
$loadedData = json_decode($json, true);
foreach ($loadedData as $key => $value) $options[$key] = $value;
*/

//TODO: Move this to a separate file, like .env
$server_settings = [ // Server specific settings, listed in the order in which they appear on the VZG server list.
    'tdm' => [
        'supported' => true,
        'enabled' => true,
        'name' => 'TDM',
        //'key' => 'tdm',
        'ip' => $civ13_ip,
        'port' => '1714',
        'host' => 'Taislin',
        'panic_bunker' => false,
        'log_attacks' => false,
        'legacy' => true,
        'moderate' => true,
        'legacy_relay' => false,
        'basedir' => '/home/civ13/civ13-tdm',
        // Primary channels
        'discussion' => '799952134426591273',
        'playercount' => '1048777462898761789',
        // Chat relay channels
        'ooc' => '1107016184328622233',
        'lobby' => '1107021760483831988',
        'asay' => '1107016769169801216',
        'ic' => '1121531682198138920',
        // Log channels
        'transit' => '1107020747622326313',
        'adminlog' => '1107024305927225455',
        'debug' => '1106248157798600715',
        'garbage' => '1107018726307528735',
        'runtime' => '1107017103883632792',
        'attack' => '1107017830160936980',
    ],
    'nomads' => [ // This is the endpoint you'll add to config.txt, (e.g. WEBHOOK_ADDRESS http://127.0.0.1:55555/webhook/nomads)
        'supported' => true, // Whether the server is supported by the remote webserver
        'enabled' => true, // Whether the server should have commands handled by the bot
        'name' => 'Nomads', // Name of the server and the prefix of the playercount channel (e.g. nomads-999)
        //'key' => 'nomads', // This must match the top-level key in the server_settings array
        'ip' => $civ13_ip, // IP of the server
        'port' => '1715', // Port of the server
        'host' => 'Taislin', // Host of the server
        'panic_bunker' => true, // Panic mode will ban all users who are not verified
        'log_attacks' => true, // Only recommended to set to false to mitigate logging spam
        'legacy' => true, // Legacy mode will use the file system instead of an SQL database
        'moderate' => true, // Whether chat moderation is enabled
        'legacy_relay' => false, // How messages are relayed to the server
        'basedir' => '/home/civ13/civ13-rp', // Base directory of the server
        // Primary channels
        'discussion' => '799952084505067581', // #nomads
        'playercount' => '1048777424894185484', // nomads-#
        // Chat relay channels
        'ooc' => '1110001963405418616', // #ooc-nomads
        'lobby' => '1110001986134347856', // #lobby-nomads
        'asay' => '1110002005977604186', // #asay-nomads
        'ic' => '1121531739114852432', // #ic-nomads
        // Log channels
        'transit' => '1110002027469221989', // #transit-nomads
        'adminlog' => '1110002047123738624', // #adminlog-nomads
        'debug' => '1106248132779593758', // #debug-nomads (debugging)
        'garbage' => '1110002493259251752', // #garbage-nomads
        'runtime' => '1110002671936602132', // #runtime-nomads
        'attack' => '1110002697383448648', // #attack-nomads
    ],
    'pers' => [
        'supported' => true,
        'enabled' => false,
        'name' => 'Persistence',
        //'key' => 'pers',
        'ip' => $vzg_ip,
        'port' => '1716',
        'host' => 'ValZarGaming',
        'panic_bunker' => true,
        'log_attacks' => true,
        'legacy' => true,
        'moderate' => true,
        'legacy_relay' => false,
        'basedir' => '/home/valithor/VPS/civ13-rp',
        // Primary channels
        'discussion' => '799951945346711552',
        'playercount' => '1090788345082298369',
        // Chat relay channels
        'ooc' => '1139614228408455388',
        'lobby' => '1139614248222343282',
        'asay' => '1139614266299785278',
        'ic' => '1139614281512529941',
        // Log channels
        'transit' => '1139614542700216420',
        'adminlog' => '1139614564577722448',
        'debug' => '1139614582931984575',
        'garbage' => '1139614596789964820',
        'runtime' => '1139614629081915492',
        'attack' => '1139614643954921593',
    ],
];
foreach ($server_settings as $key => $value) $server_settings[$key]['key'] = $key; // Key is intended to be a shortname for the full server, so defining both a full name and short key are required. Individual server settings will also get passed around and lose their primary key, so we need to reassign it.

$hidden_options = [
    'loop' => $loop,
    'discord' => $discord,
    'browser' => $browser,
    'filesystem' => $filesystem,
    'logger' => $logger,
    'stats' => $stats,

    'webapi' => &$webapi,
    'socket' => &$socket,
    'web_address' => getenv('web_address') ?: 'www.civ13.com',
    'http_port' => intval(getenv('http_port')) ?: 55555, // 25565 for testing on Windows
    'http_key' => getenv('WEBAPI_TOKEN') ?: 'CHANGEME',
    'http_whitelist' => $http_whitelist,
    'civ_token' => getenv('CIV_TOKEN') ?: 'CHANGEME',
    'server_settings' => $server_settings, // Server specific settings, listed in the order in which they appear on the VZG server list.
    'functions' => array(
        'init' => [
            // 'on_ready' => $on_ready,
            'status_changer_timer' => $status_changer_timer,
            'status_changer_random' => $status_changer_random,
        ],
        'misc' => [ // Custom functions
            //
        ],
    ),
];
$options = array_merge($options, $hidden_options);

$civ13 = null;
$global_error_handler = function (int $errno, string $errstr, ?string $errfile, ?int $errline) use (&$civ13, &$logger, &$testing) {
    /** @var ?Civ13 $civ13 */
    if (
        $civ13 && // If the bot is running
        ($channel = $civ13->discord->getChannel($civ13->channel_ids['staff_bot']))
        // fsockopen
        && ! str_ends_with($errstr, 'Connection timed out') 
        && ! str_ends_with($errstr, '(Connection timed out)')
        && ! str_ends_with($errstr, 'Connection refused') // Usually happens if the verifier server doesn't respond quickly enough
        && ! str_contains($errstr, '(Connection refused)') // Usually happens in localServerPlayerCount
        //&& ! str_ends_with($errstr, 'Network is unreachable')
        //&& ! str_ends_with($errstr, '(Network is unreachable)')

        // Connectivity issues
        && ! str_ends_with($errstr, 'No route to host') // Usually happens if the verifier server is down
        && ! str_ends_with($errstr, 'No address associated with hostname') // Either the DNS or the VPS is acting up
        && ! str_ends_with($errstr, 'Temporary failure in name resolution') // Either the DNS or the VPS is acting up
        && ! str_ends_with($errstr, 'Bad Gateway') // Usually happens if the verifier server's PHP-CGI is down
        //&& ! str_ends_with($errstr, 'HTTP request failed!')

        //&& ! str_contains($errstr, 'Undefined array key')
    )
    {
        $msg = sprintf("[%d] Fatal error on `%s:%d`: %s\nBacktrace:\n```\n%s\n```", $errno, $errfile, $errline, $errstr, implode("\n", array_map(fn($trace) => "{$trace['file']}:{$trace['line']} {$trace['function']}", debug_backtrace())));
        $logger->error($msg);
        if (isset($civ13->technician_id) && $tech_id = $civ13->technician_id) $msg = "<@{$tech_id}>, $msg";
        if (! $testing) $civ13->sendMessage($channel, $msg);
    }
};
set_error_handler($global_error_handler);

use React\Socket\SocketServer;
use React\Http\HttpServer;
use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
$socket = new SocketServer(sprintf('%s:%s', '0.0.0.0', getenv('http_port') ?: 55555), [], $loop);
/**
 * Handles the HTTP request using the HttpServiceManager.
 *
 * @param ServerRequestInterface $request The HTTP request object.
 * @return Response The HTTP response object.
 */
$webapi = new HttpServer($loop, function (ServerRequestInterface $request) use (&$civ13): Response
{
    /** @var ?Civ13 $civ13 */
    if (! $civ13 || ! $civ13 instanceof Civ13 || ! $civ13->httpServiceManager instanceof HttpServiceManager) return new Response(Response::STATUS_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain'], 'Service Unavailable');
    if (! $civ13->ready) return new Response(Response::STATUS_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain'], 'Service Not Yet Ready');
    return $civ13->httpServiceManager->handle($request);
});
/**
 * This code snippet handles the error event of the web API.
 * It logs the error message, file, line, and trace, and handles specific error cases.
 * If the error message starts with 'Received request with invalid protocol version', it is ignored.
 * If the error message starts with 'The response callback', it triggers a restart process.
 * The restart process includes sending a message to a specific Discord channel and closing the socket connection.
 * After a delay of 5 seconds, the script is restarted by calling the 'restart' function and closing the Discord connection.
 *
 * @param Exception $e The exception object representing the error.
 * @param \Psr\Http\Message\RequestInterface|null $request The HTTP request object associated with the error, if available.
 * @param object $civ13 The main object of the application.
 * @param object $socket The socket object.
 * @param bool $testing Flag indicating if the script is running in testing mode.
 * @return void
 */
$webapi->on('error', function (Exception $e, ?\Psr\Http\Message\RequestInterface $request = null) use (&$civ13, &$logger, &$socket) {
    if (
        str_starts_with($e->getMessage(), 'Received request with invalid protocol version')
    ) return; // Ignore this error, it's not important
    $error = "[WEBAPI] {$e->getMessage()} [{$e->getFile()}:{$e->getLine()}] " . str_replace('\n', PHP_EOL, $e->getTraceAsString());
    $logger->error("[WEBAPI] $error");
    if ($request) $logger->error('[WEBAPI] Request: ' .  preg_replace('/(?<=key=)[^&]+/', '********', $request->getRequestTarget()));
    if (str_starts_with($e->getMessage(), 'The response callback')) {
        $logger->info('[WEBAPI] ERROR - RESTART');
        /** @var ?Civ13 $civ13 */
        if (! $civ13) return;
        if (! getenv('testing') && isset($civ13->channel_ids['staff_bot']) && $channel = $civ13->discord->getChannel($civ13->channel_ids['staff_bot'])) {
            $builder = MessageBuilder::new()
                ->setContent('Restarting due to error in HttpServer API...')
                ->addFileFromContent('httpserver_error.txt', preg_replace('/(?<=key=)[^&]+/', '********', $error));
            $channel->sendMessage($builder);
        }
        $socket->close();
        if (! isset($civ13->timers['restart'])) $civ13->timers['restart'] = $civ13->discord->getLoop()->addTimer(5, fn() => $civ13->restart());
    }
});

$civ13 = new Civ13($options, $server_settings);
$civ13->run();