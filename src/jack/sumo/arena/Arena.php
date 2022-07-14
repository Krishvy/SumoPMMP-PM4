<?php

declare(strict_types=1);

namespace jack\sumo\arena;

use pocketmine\block\VanillaBlocks;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\player\GameMode;
use pocketmine\world\World;
use pocketmine\world\Position;
use pocketmine\player\Player;
use pocketmine\block\tile\Tile;
use jack\sumo\event\PlayerArenaWinEvent;
use jack\sumo\event\PlayerEquipEvent;
use jack\sumo\math\Vector3;
use jack\sumo\Sumo;

/**
 * Class Arena
 * @package onevsone\arena
 */
class Arena implements Listener {

    const MSG_MESSAGE = 0;
    const MSG_TIP = 1;
    const MSG_POPUP = 2;
    const MSG_TITLE = 3;

    const PHASE_LOBBY = 0;
    const PHASE_GAME = 1;
    const PHASE_RESTART = 2;

    /** @var OneVsOne $plugin */
    public $plugin;

    /** @var ArenaScheduler $scheduler */
    public $scheduler;

    /** @var int $phase */
    public $phase = 0;

    /** @var array $data */
    public $data = [];

    /** @var bool $setting */
    public $setup = false;

    /** @var Player[] $players */
    public $players = [];

    /** @var Player[] $toRespawn */
    public $toRespawn = [];

    /** @var World $level */
    public $level = null;

    /**
     * Arena constructor.
     * @param OneVsOne $plugin
     * @param array $arenaFileData
     */
    public function __construct(Sumo $plugin, array $arenaFileData) {
        $this->plugin = $plugin;
        $this->data = $arenaFileData;
        $this->setup = !$this->enable(\false);

        $this->plugin->getScheduler()->scheduleRepeatingTask($this->scheduler = new ArenaScheduler($this), 20);

        if($this->setup) {
            if(empty($this->data)) {
                $this->createBasicData();
            }
        }
        else {
            $this->loadArena();
        }
    }

    /**
     * @param Player $player
     */
    public function joinToArena(Player $player) {
        if(!$this->data["enabled"]) {
            $player->sendMessage("§cThe game is in configurating!");
            return;
        }

        if(count($this->players) >= $this->data["slots"]) {
            $player->sendMessage("§cThe game is full!");
            return;
        }

        if($this->inGame($player)) {
            $player->sendMessage("§cYou are already in the queue/game!");
            return;
        }

        $selected = false;
        for($lS = 1; $lS <= $this->data["slots"]; $lS++) {
            if(!$selected) {
                if(!isset($this->players[$index = "spawn-{$lS}"])) {
                    $player->teleport(Position::fromObject(Vector3::fromString($this->data["spawns"][$index]), $this->level));
                    $this->players[$index] = $player;
                    $selected = true;
                }
            }
        }

        $this->broadcastMessage("§l§bGame §r§l>> §r§ePlayer {$player->getName()} entered the game! §7[".count($this->players)."/{$this->data["slots"]}]");

        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->getEffects()->add(new EffectInstance(VanillaEffects::REGENERATION(), (99999999*20), (3), (false)));

        $player->setGamemode(GameMode::ADVENTURE());
        $player->setHealth(20);
        $player->getHungerManager()->setFood(20);

    }

    /**
     * @param Player $player
     * @param string $quitMsg
     * @param bool $death
     */
    public function disconnectPlayer(Player $player, string $quitMsg = "", bool $death = \false) {
        switch ($this->phase) {
            case Arena::PHASE_LOBBY:
                $index = "";
                foreach ($this->players as $i => $p) {
                    if($p->getId() == $player->getId()) {
                        $index = $i;
                    }
                }
                if($index != "") {
                    unset($this->players[$index]);
                }
                break;
            default:
                unset($this->players[$player->getName()]);
                break;
        }

        $player->getEffects()->clear();

        $player->setGamemode($this->plugin->getServer()->getGamemode());

        $player->setHealth(20);
        $player->getHungerManager()->setFood(20);
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();

        $player->teleport($this->plugin->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation());

        if(!$death) {
            $this->broadcastMessage("§l§bGame §r§l>> §r§ePlayer {$player->getName()} Left the game. §7[".count($this->players)."/{$this->data["slots"]}]");
        }

        if($quitMsg != "") {
            $player->sendMessage("§l§bGame §r§l>> §r§e$quitMsg");
        }
    }

    public function startGame() {
        $players = [];
        foreach ($this->players as $player) {
            $players[$player->getName()] = $player;
        }


        $this->players = $players;
        $this->phase = 1;

        $this->broadcastMessage("§6The game started!", self::MSG_TITLE);
    }

    public function startRestart() {
        $player = null;
        foreach ($this->players as $p) {
            $player = $p;
        }

        if($player === null || (!$player instanceof Player) || (!$player->isOnline())) {
            $this->phase = self::PHASE_RESTART;
            return;
        }

        $player->sendTitle("§bY§ao§6u §dw§4o§1n§6!");
        (new PlayerArenaWinEvent($this->plugin, $player, $this))->call();
        $this->plugin->getServer()->broadcastMessage("§l§bSumo §r§l>> §r§ePlayer {$player->getName()} won the game in map: {$this->level->getFolderName()}!");
        $this->phase = self::PHASE_RESTART;
    }

    /**
     * @param Player $player
     * @return bool $isInGame
     */
    public function inGame(Player $player): bool {
        switch ($this->phase) {
            case self::PHASE_LOBBY:
                $inGame = false;
                foreach ($this->players as $players) {
                    if($players->getId() == $player->getId()) {
                        $inGame = true;
                    }
                }
                return $inGame;
            default:
                return isset($this->players[$player->getName()]);
        }
    }

    /**
     * @param string $message
     * @param int $id
     * @param string $subMessage
     */
    public function broadcastMessage(string $message, int $id = 0, string $subMessage = "") {
        foreach ($this->players as $player) {
            switch ($id) {
                case self::MSG_MESSAGE:
                    $player->sendMessage($message);
                    break;
                case self::MSG_TIP:
                    $player->sendTip($message);
                    break;
                case self::MSG_POPUP:
                    $player->sendPopup($message);
                    break;
                case self::MSG_TITLE:
                    $player->sendTitle($message, $subMessage);
                    break;
            }
        }
    }

    /**
     * @return bool $end
     */
    public function checkEnd(): bool {
        return count($this->players) <= 1;
    }

    /**
     * @param PlayerMoveEvent $event
     */
    public function onMove(PlayerMoveEvent $event) {
        if($this->phase != self::PHASE_LOBBY) return;
        $player = $event->getPlayer();
        if($this->inGame($player)) {
            $index = null;
            foreach ($this->players as $i => $p) {
                if($p->getId() == $player->getId()) {
                    $index = $i;
                }
            }
            if($event->getPlayer()->getLocation()->asVector3()->distance(Vector3::fromString($this->data["spawns"][$index])) > 1) {
                // $event->cancel() will not work
                $player->teleport(Vector3::fromString($this->data["spawns"][$index]));
            }
        }
    }

    /**
     * @param PlayerExhaustEvent $event
     */
    public function onExhaust(PlayerExhaustEvent $event) {
        $player = $event->getPlayer();

        if(!$player instanceof Player) return;

        if($this->inGame($player) && $this->phase == self::PHASE_LOBBY) {
            $event->cancel(true);
        }
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function onInteract(PlayerInteractEvent $event) {
        $player = $event->getPlayer();
        $block = $event->getBlock();

        if($this->inGame($player) && $event->getBlock()->getId() == VanillaBlocks::CHEST()->getId() && $this->phase == self::PHASE_LOBBY) {
            $event->cancel(true);
            return;
        }

        if(!$block->getPosition()->getWorld()->getTile($block->getPosition()->asVector3()) instanceof Tile) {
            return;
        }

        $signPos = Position::fromObject(Vector3::fromString($this->data["joinsign"][0]), $this->plugin->getServer()->getWorldManager()->getWorldByName($this->data["joinsign"][1]));

        if((!$signPos->equals($block->getPosition()->asVector3())) || $signPos->getWorld()->getId() != $block->getPosition()->getWorld()->getId()) {
            return;
        }

        if($this->phase == self::PHASE_GAME) {
            $player->sendMessage("§cThe game is already running.");
            return;
        }
        if($this->phase == self::PHASE_RESTART) {
            $player->sendMessage("§cThe game is restarting!");
            return;
        }

        if($this->setup) {
            return;
        }

        $this->joinToArena($player);
    }

    /**
     * @param PlayerDeathEvent $event
     */
    public function onDeath(PlayerDeathEvent $event) {
        $player = $event->getPlayer();

        if(!$this->inGame($player)) return;

        foreach ($event->getDrops() as $item) {
            $player->getWorld()->dropItem($player->getLocation()->asVector3(), $item);
        }
        $this->toRespawn[$player->getName()] = $player;
        $this->disconnectPlayer($player, "", true);
        $this->broadcastMessage("§l§bGame §r§l>> §r§e{$this->plugin->getServer()->getLanguage()->translate($event->getDeathMessage())} §7[".count($this->players)."/{$this->data["slots"]}]");
        $event->setDeathMessage("");
        $event->setDrops([]);
    }

    /**
     * @param PlayerRespawnEvent $event
     */
    public function onRespawn(PlayerRespawnEvent $event) {
        $player = $event->getPlayer();
        if(isset($this->toRespawn[$player->getName()])) {
            $event->setRespawnPosition($this->plugin->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation());
            unset($this->toRespawn[$player->getName()]);
        }
    }

    /**
     * @param PlayerQuitEvent $event
     */
    public function onQuit(PlayerQuitEvent $event) {
        if($this->inGame($event->getPlayer())) {
            $this->disconnectPlayer($event->getPlayer());
        }
    }

    public function onWorldChange(EntityTeleportEvent $event) {
        $player = $event->getEntity();
        if(!$player instanceof Player) return;
        if($this->inGame($player)) {
            $this->disconnectPlayer($player, "You quit the game.");
        }
    }

    /**
     * @param bool $restart
     */
    public function loadArena(bool $restart = false) {
        if(!$this->data["enabled"]) {
            $this->plugin->getLogger()->error("Can not load arena: Arena is not enabled!");
            return;
        }

        if(!$restart) {
            $this->plugin->getServer()->getPluginManager()->registerEvents($this, $this->plugin);

            if(!$this->plugin->getServer()->getWorldManager()->isWorldLoaded($this->data["level"])) {
                $this->plugin->getServer()->getWorldManager()->loadWorld($this->data["level"]);
            }

            $this->level = $this->plugin->getServer()->getWorldManager()->getWorldByName($this->data["level"]);
        }

        else {
            $this->scheduler->reloadTimer();
        }

        if(!$this->level instanceof World) $this->level = $this->plugin->getServer()->getWorldManager()->getWorldByName($this->data["level"]);

        $this->phase = static::PHASE_LOBBY;
        $this->players = [];
    }

    /**
     * @param bool $loadArena
     * @return bool $isEnabled
     */
    public function enable(bool $loadArena = true): bool {
        if(empty($this->data)) {
            return false;
        }
        if($this->data["level"] == null) {
            return false;
        }
        if(!$this->plugin->getServer()->getWorldManager()->isWorldGenerated($this->data["level"])) {
            return false;
        }
        else {
            if(!$this->plugin->getServer()->getWorldManager()->isWorldLoaded($this->data["level"]))
                $this->plugin->getServer()->getWorldManager()->loadWorld($this->data["level"]);
            $this->level = $this->plugin->getServer()->getWorldManager()->getWorldByName($this->data["level"]);
        }
        if(!is_int($this->data["slots"])) {
            return false;
        }
        if(!is_array($this->data["spawns"])) {
            return false;
        }
        if(count($this->data["spawns"]) != $this->data["slots"]) {
            return false;
        }
        if(!is_array($this->data["joinsign"])) {
            return false;
        }
        if(count($this->data["joinsign"]) !== 2) {
            return false;
        }
        $this->data["enabled"] = true;
        $this->setup = false;
        if($loadArena) $this->loadArena();
        return true;
    }

    private function createBasicData() {
        $this->data = [
            "level" => null,
            "slots" => 2,
            "spawns" => [],
            "enabled" => false,
            "joinsign" => []
        ];
    }

    public function __destruct() {
        unset($this->scheduler);
    }
}