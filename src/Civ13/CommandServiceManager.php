<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Discord\Discord;
use Discord\Builders\MessageBuilder;
use Discord\Helpers\RegisteredCommand;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\User\Member;
use Discord\Parts\Permissions\RolePermission;
use Discord\Repository\Guild\GuildCommandRepository;
use Discord\Repository\Interaction\GlobalCommandRepository;
use Monolog\Logger;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\StreamSelectLoop;
use React\Http\Message\Response as HttpResponse;
use React\Promise\PromiseInterface;
use ReflectionFunction;

use function React\Promise\resolve;
use function React\Promise\reject;

class CommandServiceManager
{
    public Discord $discord;
    public Logger $logger;
    public StreamSelectLoop $loop;

    public Civ13 $civ13;
    public HttpServiceManager $httpServiceManager;
    public MessageServiceManager $messageServiceManager;

    public array $global_commands = [];
    public array $guild_commands = [];

    private readonly bool $setup;

    public function __construct(Discord &$discord, HttpServiceManager &$httpServiceManager, MessageServiceManager &$messageServiceManager, Civ13 &$civ13) {
        $this->civ13 =& $civ13;
        $this->discord =& $discord;
        $this->logger =& $civ13->logger;
        $this->httpServiceManager =& $httpServiceManager;
        $this->messageServiceManager =& $messageServiceManager;
        $this->loop =& $civ13->loop;
        $this->afterConstruct();
    }

    /*
    * This function is called after the constructor is finished.
    * It is used to load the files, start the timers, and start handling events.
    */
    private function afterConstruct(): void
    {
        $fn = function() {
            $this->logger->info('Setting up CommandServiceManager...');
            $this->setup();
            if ($application_commands = $this->discord->__get('application_commands'))
                $this->logger->debug('[APPLICATION COMMAND LIST] ' . PHP_EOL . '`' . implode('`, `', array_map(fn($command) => $command->getName(), $application_commands)) . '`');
        };
        $this->civ13->ready
            ? $fn()
            : $this->discord->once('init', fn() => $fn());
    }
    /**
     * Sets up the bot by updating commands, guild commands, and declaring listeners.
     * This method should be called in the scope of $this->discord->on('init', fn() => $this->setup());
     */
    private function setup(): PromiseInterface
    {
        if (isset($this->setup)) {
            $this->logger->warning($err = 'Setup already called');
            return reject(new \LogicException($err));
        }
        $this->loadCommands();
        $this->loadDefaultHelpCommand();
        $this->setupMessageCommands();
        $this->setupInteractionCommands();
        $this->setupHTTPCommands();
        $this->logger->info(json_encode($this->global_commands));
        $this->logger->info(json_encode($this->guild_commands));
        $this->setup = true;
        $this->logger->info('CommandServiceManager setup complete');
        $this->logger->info($this->httpServiceManager->httpHandler->generateHelp());
        return resolve(null);
    }

    /**
     * Validates the command array.
     *
     * @param array $command The command to validate.
     * @return bool Returns true if the command callback is valid, false otherwise.
     */
    public function validateCommand(array $command): bool
    {
        $resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();
        $resolver->setRequired(['name']);
        $resolver->setDefaults([
            'message_handler' => null,
            'http_handler' => null,
            'interaction_handler' => null,
            'interaction_definer' => []
        ]);
        $resolver->setAllowedTypes('name', 'string');
        $resolver->setAllowedTypes('message_handler', ['null', MessageHandlerCallback::class]);
        $resolver->setAllowedTypes('http_handler', ['null', HttpHandlerCallback::class]);
        $resolver->setAllowedTypes('interaction_handler', ['null', 'callable']);
        $resolver->setAllowedTypes('interaction_definer', 'array');

        try {
            $command = $resolver->resolve($command);
        } catch (\Exception $e) {
            $this->logger->warning('Invalid command configuration: ' . $e->getMessage());
            return false;
        }

        if ($command['message_handler'] && !is_callable($command['message_handler'])) {
            $this->logger->warning("Invalid Message handler for `{$command['name']}`");
            return false;
        }

        if ($command['http_handler'] && !is_callable($command['http_handler'])) {
            $this->logger->warning("Invalid HTTP handler for `{$command['name']}`");
            return false;
        }

        if ($command['interaction_handler']) {
            $command['interaction_definer']['name'] = $command['name'];
            if (!is_callable($command['interaction_handler'])) {
                $this->logger->warning("Invalid interaction handler for `{$command['name']}`");
                return false;
            }
            $reflection = new ReflectionFunction($command['interaction_handler']);
            $returnType = $reflection->getReturnType();
            if (!$returnType || $returnType->getName() !== 'React\Promise\PromiseInterface') {
                $this->logger->warning("Invalid return type for `{$command['name']}`, found {$returnType->getName()} instead of PromiseInterface");
                return false;
            }
        }

        return true;
    }
    /**
     * Listens for a command and registers it with the specified callback and autocomplete callback.
     *
     * @param string $name The name of the command.
     * @param callable|null $callback The callback function to be executed when the command is triggered.
     * @param callable|null $autocomplete_callback The callback function to be executed for command autocomplete.
     * @return RegisteredCommand The registered command object.
     */
    private function listenCommand($name, ?callable $callback = null, ?callable $autocomplete_callback = null): RegisteredCommand
    {
        return $this->discord->listenCommand($name, $callback, $autocomplete_callback);
    }
    /**
     * Saves a command to the specified repository.
     *
     * @param GlobalCommandRepository|GuildCommandRepository $commands The repository to save the command to.
     * @param Command $command The command to save.
     * @return PromiseInterface<Command> A promise that resolves when the command is saved.
     */
    private function save(GlobalCommandRepository|GuildCommandRepository $commands, Command $command): PromiseInterface
    {
        return $this->civ13->then($commands->save($command));
    }

    private function populateCommands(array $array = []): array
    {
        $array[] = [
            'name'                              => 'ping',                                                                          // Name of the command.
            'alias'                             => ['pong'],                                                                        // Aliases for the command.
            'guilds'                            => [],                                                                              // Global if empty, otherwise specify guild ids.
            'general_usage'                     => 'Replies with Pong!',                                                            // Used when generating the help message/embed/file/etc. used in this class.
            'message_method'                    => 'str_starts_with',                                                               // The method to use when determining if the function should be triggered ('str_starts_with', 'str_contains', 'str_ends_with', 'exact')
            'message_usage'                     => 'Replies with Pong!',                                                            // Instructions for proper usage of the message handler. (NYI. Currently placed the description property, but never called on. Will be added to the 'help' command from the generateHelp() function in a future update.)
            'message_role_permissions'          => [],                                                                              // Empty array means everyone can use it, otherwise an array of names of roles as defined in the configuration. (e.g. ['Owner', 'Ambassador', 'Admin'])
            'message_handler' => new MessageHandlerCallback(function (Message $message, string $command, array $message_filtered): PromiseInterface
            {
                return $message->reply('Pong!');
            }),
            'http_usage'                        => 'Replies with HTTP status code 200.',                                            // Instructions for proper usage of the http handler. (NYI. Currently placed the description property, but never called on. May be added as an endpoint to an existing 'help' endpoint or to improve error messages due to bad user input in a future update.)
            'http_method'                       => 'exact',                                                                         // The method to use when determining if the function should be triggered ('str_starts_with', 'str_contains', 'str_ends_with', 'exact')
            'http_whitelisted'                  => false,                                                                           // Whether the endpoint should be restricted to localhost and whitelisted IPs.
            'http_limit'                        => null,                                                                            // The maximum number of requests allowed within the time window.
            'http_window'                       => null,                                                                            // The time window in seconds.
            'http_handler' => new HttpHandlerCallback(function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
            {
                return new HttpResponse(HttpResponse::STATUS_OK);
            }),
            'interaction_definer' => [
                'description'                   => 'Replies with Pong!',
                'dm_permission'                 => false,                   // Whether the command can be used in DMs.
                'default_member_permissions'    => null,                    // Default member permissions. (e.g. (string) new RolePermission($this->discord, ['view_audit_log' => true]))
            ],
            'interaction_handler' => function (Interaction $interaction): PromiseInterface
            {
                return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Pong!'), true);
            },
        ];
        return $array;
    }
    private function loadDefaultHelpCommand(): void
    {
        $help = [
            'name'                              => 'help',                                                                          // Name of the command.
            'alias'                             => ['assist'],                                                                  // Aliases for the command.
            'guilds'                            => [],                                                                              // Global if empty, otherwise specify guild ids.
            'general_usage'                     => 'Replies with information about a command (or all if none specified).',          // Used when generating the help message/embed/file/etc. used in this class.
            'message_method'                    => 'str_starts_with',                                                               // The method to use when determining if the function should be triggered ('str_starts_with', 'str_contains', 'str_ends_with', 'exact')
            'message_usage'                     => 'Replies with information about a command (or all if none specified).',          // Instructions for proper usage of the message handler. (NYI. Currently placed the description property, but never called on. Will be added to the 'help' command from the generateHelp() function in a future update.)
            'message_role_permissions'          => [],                                                                              // Empty array means everyone can use it, otherwise an array of names of roles as defined in the configuration. (e.g. ['Owner', 'Ambassador', 'Admin'])
            'message_handler' => new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command_name): PromiseInterface
            {
                if (! $desired_command_name = trim(substr($message_filtered['message_content_lower'], strlen($command_name)))) return $message->reply($this->getHelpMessageBuilder());
                if (isset($this->guild_commands[$message->guild_id]) && $this->guild_commands[$message->guild_id] && isset($this->guild_commands[$message->guild_id][$desired_command_name]) && $this->guild_commands[$message->guild_id][$desired_command_name]) return $message->reply($this->getHelpString($message->guild_id, $desired_command_name));
                if (isset($this->global_commands[$desired_command_name])) return $message->reply($this->getHelpString(null, $desired_command_name));
                return $message->reply("Command `$desired_command_name` not found!");
            }),
            'http_usage'                        => 'Replies with information about an endpoint (or all if none specified).',        // Instructions for proper usage of the http handler. (NYI. Currently placed the description property, but never called on. May be added as an endpoint to an existing 'help' endpoint or to improve error messages due to bad user input in a future update.)
            'http_method'                       => 'exact',                                                                         // The method to use when determining if the function should be triggered ('str_starts_with', 'str_contains', 'str_ends_with', 'exact')
            'http_whitelisted'                  => false,                                                                           // Whether the endpoint should be restricted to localhost and whitelisted IPs.
            'http_limit'                        => null,                                                                            // The maximum number of requests allowed within the time window.
            'http_window'                       => null,                                                                            // The time window in seconds.
            'http_handler' => new HttpHandlerCallback(function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
            {
                return HttpResponse::plaintext($this->getHelpString());
            }),
            'interaction_definer' => [
                'description'                   => 'Replies with information about an interaction (or all if none specified).',     // Instructions for proper usage of the interaction handler. Currently used as the the description.
                'dm_permission'                 => false,                                                                           // Whether the command can be used in DMs.
                'default_member_permissions'    => null,                                                                            // Default member permissions. (e.g. (string) new RolePermission($this->discord, ['view_audit_log' => true]))
            ],
            'interaction_handler' => function (Interaction $interaction): PromiseInterface {
                return $interaction->respondWithMessage($this->getHelpMessageBuilder(), true);
            },
        ];
        if ($this->isUnique($help)) $this->global_commands['help'] = $help;
    }
    /**
     * Loads the commands by populating the global and guild commands arrays.
     *
     * @return void
     */
    private function loadCommands(): void
    {   
        foreach ($this->populateCommands() as $command) {
            if (! $this->isUnique($command)) continue;
            if (! isset($command['guilds']) || ! $command['guilds']) {
                $this->global_commands[$command['name']] = $command;
                $names = [$command['name']];
                //$names = array_merge($names, isset($command['alias']) ? $command['alias'] : []);
                foreach ($names as $name) {
                    $command['name'] = $name;
                    $this->global_commands[$name] = $command;
                }
                continue;
            }
            foreach ($command['guilds'] as $guild_id) {
                $names = [$command['name']];
                //$names = array_merge($names, isset($command['alias']) ? $command['alias'] : []);
                foreach ($names as $name) $this->guild_commands[$guild_id][$name] = $command;
            }
        }
    }
    
    private function setupMessageCommands(): void
    {
        foreach ($this->global_commands as $global_command) $this->createMessageCommand($global_command);
        foreach ($this->guild_commands as $guild_command) $this->createMessageCommand($guild_command);
    }
    public function createMessageCommand(array $command): bool
    {
        if (! isset($command['message_handler'])) {
            $this->logger->warning("Invalid Message handler for `{$command['name']}` command");
            return false;
        }
        $names = array_merge([$command['name']], $command['alias'] ?? []);
        foreach ($names as $name) {
            $this->messageServiceManager->offsetSet(
                $name,
                $command['message_handler'],
                (isset($command['message_role_permissions']) && is_array($command['message_role_permissions'])) ? $command['message_role_permissions'] : [],
                (isset($command['message_method']) && is_string($command['message_method'])) ? $command['message_method'] : 'str_starts_with',
                (isset($command['message_usage']) && is_string($command['message_usage'])) ? $command['message_usage'] : '',
            );
        }
        return true;
    }

    /**
     * Checks if a command is unique.
     *
     * @param array $command The command to check.
     * @return bool Returns true if the command's name does not already exist in the global commands arrays, false otherwise.
     */
    private function isUnique($command): bool
    {
        $names[] = $command['name'];
        //$names = array_merge($names, isset($command['alias']) ? $command['alias'] : []);
        foreach ($names as $name) if (isset($this->global_commands[$name])) return false;
        return true;
    }

    /**
     * Sets up the interaction commands for the bot.
     * This method registers global and guild commands for the bot's Discord application.
     * It validates the commands and saves them if they don't exist.
     * It also sets up the command listeners for handling interactions.
     */
    private function setupInteractionCommands(): void
    {
        if ($this->global_commands) $this->discord->application->commands->freshen()->then(function (GlobalCommandRepository $commands): void
        {
            foreach ($this->global_commands as $command) if ($this->validateCommand($command)) {
                if (str_starts_with($command['name'], '/')) continue; // Skip slash commands for now
                if (! $commands->get('name', $command['name'])) $this->save($commands, $command['interaction_definer']);
                $this->listenCommand($command['name'], $command('interaction_handler'));
            }
            $this->logger->debug('[GLOBAL APPLICATION COMMAND LIST]' . PHP_EOL . '`' . implode('`, `', array_map(function($command) { return $command['name']; }, $this->global_commands)) . '`');
        });
        if ($this->guild_commands) foreach (array_keys($this->guild_commands) as $key) if ($guild = $this->discord->guilds->get('id', $key)) $guild->commands->freshen()->then(function (GuildCommandRepository $commands) use ($key)
        {
            foreach ($this->guild_commands[$key] as $command) if ($this->validateCommand($command)) {
                if (! $commands->get('name', $command['name'])) $this->save($commands, $command['interaction_definer']);
                $this->listenCommand($command['name'], $command['interaction_handler']);
            }
            foreach (array_keys($this->guild_commands) as $guild_id) $this->logger->debug("[GUILD APPLICATION COMMAND LIST FOR GUILD `$guild_id`]" . PHP_EOL . '`' . implode('`, `', array_map(function($command) { return $command['name']; }, $this->guild_commands[$guild_id])) . '`');
        });
    }
    private function setupHTTPCommands(): void
    {
        foreach ($this->global_commands as $global_command) if ($this->validateCommand($global_command)) $this->createHTTPCommand($global_command);
        foreach ($this->guild_commands as $guild_command) if ($this->validateCommand($guild_command)) $this->createHTTPCommand($guild_command);
    }
    public function createHTTPCommand(array $command): bool
    {
        if (! isset($command['http_handler'])) {
            $this->logger->warning('Invalid HTTP handler');
            return false;
        }
        $names = array_merge($command['name'], (isset($command['alias']) && is_array($command['alias'])) ? $command['alias'] : []);
        foreach ($names as $name) {
            $this->httpServiceManager->offsetSet(
                "/$name",
                $command['http_handler'],
                (isset($command['http_whitelisted']) && $command['http_whitelisted']),
                (isset($command['http_method']) && $command['http_method']) ? $command['http_method'] : 'exact',
                (isset($command['http_usage']) && $command['http_usage']) ? $command['http_usage'] : '',
            );
            if (isset($command['http_limit'], $command['http_window']) && is_numeric($command['http_limit']) && is_numeric($command['http_window']))
                $this->httpServiceManager->setRateLimit($command['name'], $command['http_limit'], $command['http_window']);
        }
        return true;
    }
    
    public function getHelpMessageBuilder(?string $guild_id = null, ?string $command = null, ?MessageBuilder $messagebuilder = new MessageBuilder()): MessageBuilder
    {
        if ($embed = $this->getHelpEmbed($guild_id, $command)) return $messagebuilder->addEmbed($embed->setFooter($this->civ13->embed_footer));
        return $messagebuilder->addFileFromContent('commands.txt', $this->getHelpString($guild_id, $command));
    }
    public function getHelpEmbed(?string $guild_id = null, ?string $command_name = null): Embed|false
    {
        if (! $description = $this->getGlobalHelpString($command_name) . $this->getGuildHelpString($guild_id, $command_name)) return false;
        if (strlen($description) > 4096) return false;
        return $this->civ13->createEmbed()
            ->setTitle('Commands List')
            ->setDescription($description);
    }
    public function getHelpString(?string $guild_id = null, ?string $command = null): string
    {
        return $this->getGlobalHelpString($command) . $this->getGuildHelpString($guild_id, $command);
    }
    public function getGlobalHelpString(?string $command_name = null, ?string $help = ''): string
    {
        if (! $this->global_commands) return $help;
        if (isset($this->global_commands[$command_name]) && $command = $this->global_commands[$command_name]) return $help .= "`{$command['name']}` - {$command['general_usage']}" . PHP_EOL;
        $help .= '# Global Commands' . PHP_EOL;
        foreach ($this->global_commands as $command) if (isset($command['general_usage'])) $help .= "`{$command['name']}` - {$command['general_usage']}" . PHP_EOL;
        return $help;
    }
    /**
     * Retrieves the help string for guild commands.
     *
     * @param string|null $guild_id The ID of the guild. Defaults to null.
     * @param string|null $command The name of the command. Defaults to null.
     * @param string $help The existing help string. Defaults to an empty string.
     * @param Guild|null $guild The guild object. Defaults to null.
     * @return string The help string for guild commands.
     */
    public function getGuildHelpString(?string $guild_id = null, ?string $command_name = null, ?string $help = '', ?Guild $guild = null): string
    {
        if (! $this->guild_commands) return $help;
        if ($guild_id && ! $guild = $this->discord->guilds->get('id', $guild_id)) return $help;
        $help .= '# Guild Commands' . PHP_EOL;
        if ($guild && isset($this->guild_commands[$guild_id]) && $this->guild_commands[$guild_id]) {
            if ($command_name && $command = $this->guild_commands[$command_name]) return $help .= "`{$command['name']}` - {$command['general_usage']}" . PHP_EOL;
            foreach ($this->guild_commands[$guild_id] as $command) if (isset($command['general_usage'])) $help .= "`{$command['name']}` - {$command['general_usage']}" . PHP_EOL;
            return $help;
        }
        foreach (array_keys($this->guild_commands) as $guild_id) {
            if (! $guild = $this->discord->guilds->get('id', $guild_id)) continue;
            if (! $this->guild_commands[$guild_id]) continue;
            $help .= "__{$guild->name} ({$guild_id})___" . PHP_EOL;
            foreach ($this->guild_commands[$guild_id] as $command) if (isset($command['general_usage'])) $help .= "`{$command['name']}` - {$command['general_usage']}" . PHP_EOL;
        }
        return $help;
    }

    private function __updateCommands(): void
    {
        /*$this->discord->application->commands->freshen()->then(function (GlobalCommandRepository $commands): void
        {
            if (! empty($this->civ13->functions['ready_slash'])) foreach (array_values($this->civ13->functions['ready_slash']) as $func) $func($this, $commands); // Will be deprecated in the future
            else $this->logger->debug('No ready slash functions found!');

            $names = [];
            foreach ($commands as $command) if ($command->name) $names[] = $command->name;
            if ($names) $this->logger->debug('[GLOBAL APPLICATION COMMAND LIST]' . PHP_EOL .  '`' . implode('`, `', $names) . '`');
        });*/
    }
    private function __updateGuildCommands(): void
    {
        /*$this->discord->guilds->get('id', $this->civ13->civ13_guild_id)->commands->freshen()->then(function (GuildCommandRepository $commands) {
            $names = [];
            foreach ($commands as $command) if ($command->name) $names[] = $command->name;
            if ($names) $this->logger->debug('[GUILD APPLICATION COMMAND LIST]' . PHP_EOL .  '`' . implode('`, `', $names) . '`');
        });*/
    }

    public function __toString(): string
    {
        return $this->getHelpString();
    }
}