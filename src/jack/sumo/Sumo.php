<?php

declare(strict_types=1);

namespace jack\sumo;

use pocketmine\command\Command;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use jack\sumo\arena\Arena;
use jack\sumo\commands\SumoCommand;
use jack\sumo\math\Vector3;
use jack\sumo\provider\YamlDataProvider;

/**
 * Class OneVsOne
 * @package onevsone
 */
class Sumo extends PluginBase implements Listener {

    /** @var YamlDataProvider */
    public $dataProvider;

    /** @var EmptyArenaChooser $emptyArenaChooser */
    public $emptyArenaChooser;

    /** @var Command[] $commands */
    public $commands = [];

    /** @var Arena[] $arenas */
    public $arenas = [];

    /** @var Arena[] $setters */
    public $setters = [];

    /** @var int[] $setupData */
    public $setupData = [];

    protected function onLoad(): void{
        $this->dataProvider = new YamlDataProvider($this);
    }

    protected function onEnable(): void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->dataProvider->loadArenas();
        $this->emptyArenaChooser = new EmptyArenaChooser($this);
        $this->getServer()->getCommandMap()->register("sumo", $this->commands[] = new SumoCommand($this));
    }

    protected function onDisable(): void{
        $this->dataProvider->saveArenas();
    }

    /**
     * @param PlayerChatEvent $event
     */
    public function onChat(PlayerChatEvent $event) {
        $player = $event->getPlayer();

        if(!isset($this->setters[$player->getName()])) {
            return;
        }

        $event->cancel();
        $args = explode(" ", $event->getMessage());

        /** @var Arena $arena */
        $arena = $this->setters[$player->getName()];

        switch ($args[0]) {
            case "help":
                $player->sendMessage("§aSumo setup help (1/1):\n".
                "§7help : Displays list of available setup commands\n" .
                "§7level : Set arena level\n".
                "§7setspawn : Set arena spawns\n".
                "§7joinsign : Set arena joinsign\n".
                "§7enable : Enable the arena");
                break;
            case "level":
                if(!isset($args[1])) {
                    $player->sendMessage("§cUsage: §7level <levelName>");
                    break;
                }
                if(!$this->getServer()->getWorldManager()->isWorldGenerated($args[1])) {
                    $player->sendMessage("§cLevel $args[1] does not found!");
                    break;
                }
                $player->sendMessage("§aArena level updated to $args[1]!");
                $arena->data["level"] = $args[1];
                break;
            case "setspawn":
                if(!isset($args[1])) {
                    $player->sendMessage("§cUsage: §7setspawn <int: spawn>");
                    break;
                }
                if(!is_numeric($args[1])) {
                    $player->sendMessage("§cType number!");
                    break;
                }
                if((int)$args[1] > $arena->data["slots"]) {
                    $player->sendMessage("§cThere are only {$arena->data["slots"]} slots!");
                    break;
                }

                $arena->data["spawns"]["spawn-{$args[1]}"] = (new Vector3($player->getLocation()->getX(), $player->getLocation()->getY(), $player->getLocation()->getZ()))->__toString();
                $player->sendMessage("§a> Spawn $args[1] set to X: " . (string)round($player->getLocation()->getX()) . " Y: " . (string)round($player->getLocation()->getY()) . " Z: " . (string)round($player->getLocation()->getZ()));
                break;
            case "joinsign":
                $player->sendMessage("§aBreak block to set join sign!");
                $this->setupData[$player->getName()] = 0;
                break;
            case "enable":
                if(!$arena->setup) {
                    $player->sendMessage("§6Arena is already enabled!");
                    break;
                }
                if(!$arena->enable()) {
                    $player->sendMessage("§cCould not load arena, there are missing information!");
                    break;
                }
                $player->sendMessage("§aArena enabled!");
                break;
            case "done":
                $player->sendMessage("§aYou are successfully leaved setup mode!");
                unset($this->setters[$player->getName()]);
                if(isset($this->setupData[$player->getName()])) {
                    unset($this->setupData[$player->getName()]);
                }
                break;
            default:
                $player->sendMessage("§6You are in setup mode.\n".
                    "§7- use §lhelp §r§7to display available commands\n"  .
                    "§7- or §ldone §r§7to leave setup mode");
                break;
        }
    }

    /**
     * @param BlockBreakEvent $event
     */
    public function onBreak(BlockBreakEvent $event) {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        if(isset($this->setupData[$player->getName()])) {
            switch ($this->setupData[$player->getName()]) {
                case 0:
                    $this->setters[$player->getName()]->data["joinsign"] = [(new Vector3($block->getPosition()->getX(), $block->getPosition()->getY(), $block->getPosition()->getZ()))->__toString(), $block->getPosition()->getWorld()->getFolderName()];
                    $player->sendMessage("§aJoin sign updated!");
                    unset($this->setupData[$player->getName()]);
                    $event->cancel();
                    break;
            }
        }
    }

    /**
     * @param Player $player
     */
    public function joinToRandomArena(Player $player) {
        $arena = $this->emptyArenaChooser->getRandomArena();
        if(!is_null($arena)) {
            $arena->joinToArena($player);
            return;
        }
        $player->sendMessage("§cAll the arenas are full! Please wait...");
    }
}