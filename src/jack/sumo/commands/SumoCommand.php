<?php

declare(strict_types=1);

namespace jack\sumo\commands;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;
use jack\sumo\arena\Arena;
use jack\sumo\Sumo;

/**
 * Class OneVsOneCommand
 * @package onevsone\commands
 */
class SumoCommand extends Command {

    /** @var OneVsOne $plugin */
    protected $plugin;

    /**
     * OneVsOneCommand constructor.
     * @param OneVsOne $plugin
     */
    public function __construct(Sumo $plugin) {
        $this->plugin = $plugin;
        parent::__construct("sumo", "Sumo commands", null, ["sumo"]);
    }

    /**
     * @param CommandSender $sender
     * @param string $commandLabel
     * @param array $args
     * @return mixed|void
     */
    public function execute(CommandSender $sender, string $commandLabel, array $args) {
        if(!isset($args[0])) {
            $sender->sendMessage("§cUsage: §7/sumo help");
            return;
        }

        switch ($args[0]) {
            case "help":
                if(!$sender->hasPermission("sumo.cmd.help")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }
                $sender->sendMessage("§aSumo commands:\n" .
                    "§7/sumo help : Displays list of Sumo commands\n".
                    "§7/sumo make : Create Sumo arena\n".
                    "§7/sumo delete : Remove Sumo arena\n".
                    "§7/sumo set : Set Sumo arena\n".
                    "§7/sumo arenas : Displays list of arenas\n" .
                    "§7/sumo join : Join a random sumo arena");

                break;
            case "make":
                if(!$sender->hasPermission("sumo.cmd.create")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }
                if(!isset($args[1])) {
                    $sender->sendMessage("§cUsage: §7/sumo make <arenaName>");
                    break;
                }
                if(isset($this->plugin->arenas[$args[1]])) {
                    $sender->sendMessage("§cArena $args[1] already exists!");
                    break;
                }
                $this->plugin->arenas[$args[1]] = new Arena($this->plugin, []);
                $sender->sendMessage("§aArena $args[1] created!");
                break;
            case "delete":
                if(!$sender->hasPermission("sumo.cmd.remove")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }
                if(!isset($args[1])) {
                    $sender->sendMessage("§cUsage: §7/sumo delete <arenaName>");
                    break;
                }
                if(!isset($this->plugin->arenas[$args[1]])) {
                    $sender->sendMessage("§cArena $args[1] was not found!");
                    break;
                }

                /** @var Arena $arena */
                $arena = $this->plugin->arenas[$args[1]];

                foreach ($arena->players as $player) {
                    $player->teleport($this->plugin->getServer()->getWorldManager()->getDefaultWorld()->getSafeSpawn());
                }

                if(is_file($file = $this->plugin->getDataFolder() . "arenas" . DIRECTORY_SEPARATOR . $args[1] . ".yml")) unlink($file);
                unset($this->plugin->arenas[$args[1]]);

                $sender->sendMessage("§aArena removed!");
                break;
            case "set":
                if(!$sender->hasPermission("sumo.cmd.set")) {
                    $sender->sendMessage("§cYou have not permissions to use this command!");
                    break;
                }
                if(!$sender instanceof Player) {
                    $sender->sendMessage("§cThis command can be used only in-game!");
                    break;
                }
                if(!isset($args[1])) {
                    $sender->sendMessage("§cUsage: §7/sumo set <arenaName>");
                    break;
                }
                if(isset($this->plugin->setters[$sender->getName()])) {
                    $sender->sendMessage("§cYou are already in setup mode!");
                    break;
                }
                if(!isset($this->plugin->arenas[$args[1]])) {
                    $sender->sendMessage("§cArena $args[1] does not found!");
                    break;
                }
                $sender->sendMessage("§aYou joined the setup mode.\n".
                    "§7- Use §lhelp §r§7to display available commands\n"  .
                    "§7- or §ldone §r§7to leave setup mode");
                $this->plugin->setters[$sender->getName()] = $this->plugin->arenas[$args[1]];
                break;
            case "arenas":
                if(!$sender->hasPermission("sumo.cmd.arenas")) {
                    $sender->sendMessage("§cYou do not have permissions to use this command!");
                    break;
                }
                if(count($this->plugin->arenas) === 0) {
                    $sender->sendMessage("§cThere are 0 arenas.");
                    break;
                }
                $list = "§7Arenas:\n";
                foreach ($this->plugin->arenas as $name => $arena) {
                    if($arena->setup) {
                        $list .= "§7- $name : §cdisabled\n";
                    }
                    else {
                        $list .= "§7- $name : §aenabled\n";
                    }
                }
                $sender->sendMessage($list);
                break;
            case "join":
                if(!$sender->hasPermission("sumo.cmd.join")) {
                    $sender->hasPermission("§cYou do not have permissions to use this command!");
                    break;
                }
                if(!$sender instanceof Player) {
                    $sender->sendMessage("§cThis command can be used only in game!");
                    break;
                }
                $this->plugin->joinToRandomArena($sender);
                break;
            default:
                if(!$sender->hasPermission("sumo.cmd.help")) {
                    $sender->sendMessage("§cYou do not have permissions to use this command!");
                    break;
                }
                $sender->sendMessage("§cUsage: §7/sumo help");
                break;
        }

    }



}
