<?php

declare(strict_types=1);

namespace jack\sumo\arena;

use pocketmine\scheduler\Task;
use jack\sumo\math\Time;
use jack\sumo\math\Vector3;
use pocketmine\world\Position;
use pocketmine\world\sound\AnvilUseSound;
use pocketmine\world\sound\ClickSound;
use pocketmine\world\World;

/**
 * Class ArenaScheduler
 * @package onevsone\arena
 */
class ArenaScheduler extends Task {

    /** @var Arena $plugin */
    protected $plugin;

    /** @var int $startTime */
    public $startTime = 5;

    /** @var float|int $gameTime */
    public $gameTime = 10 * 60;

    /** @var int $restartTime */
    public $restartTime = 5;

    /** @var array $restartData */
    public $restartData = [];

    /**
     * ArenaScheduler constructor.
     * @param Arena $plugin
     */
    public function __construct(Arena $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * @param int $currentTick
     */
    public function onRun(): void
    {
        $this->reloadSign();

        if($this->plugin->setup) return;

        switch ($this->plugin->phase) {
            case Arena::PHASE_LOBBY:
                if(count($this->plugin->players) >= 2) {
                    $this->plugin->broadcastMessage("§eGame starting in §4 " . Time::calculateTime($this->startTime) . " §esec.", Arena::MSG_TIP);
                    $this->startTime--;
                    if($this->startTime == 0) {
                        $this->plugin->startGame();
                        foreach ($this->plugin->players as $player) {
                            $this->plugin->level->addSound($player->getLocation()->asVector3(), new AnvilUseSound());
                        }
                    }
                    else {
                        foreach ($this->plugin->players as $player) {
                            $this->plugin->level->addSound($player->getLocation()->asVector3(), new ClickSound());
                        }
                    }
                }
                else {
                    $this->plugin->broadcastMessage("§cWaiting for second player!", Arena::MSG_TIP);
                    $this->startTime = 5;
                }
                break;
            case Arena::PHASE_GAME:
                $this->plugin->broadcastMessage("§eThe game ends in§4 " . Time::calculateTime($this->gameTime) . "", Arena::MSG_TIP);
                if($this->plugin->checkEnd()) $this->plugin->startRestart();
                $this->gameTime--;
                break;
            case Arena::PHASE_RESTART:
                $this->plugin->broadcastMessage("§eRestarting in §4 {$this->restartTime} §esec.", Arena::MSG_TIP);
                $this->restartTime--;

                switch ($this->restartTime) {
                    case 0:

                        foreach ($this->plugin->players as $player) {
                            $player->teleport($this->plugin->plugin->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation());

                            $player->getInventory()->clearAll();
                            $player->getArmorInventory()->clearAll();
                            $player->getCursorInventory()->clearAll();

                            $player->getHungerManager()->setFood(20);
                            $player->setHealth(20);

                            $player->setGamemode($this->plugin->plugin->getServer()->getGamemode());
                        }
                        $this->plugin->loadArena(true);
                        $this->reloadTimer();
                        break;
                }
                break;
        }
    }

    public function reloadSign() {
        if(!is_array($this->plugin->data["joinsign"]) || empty($this->plugin->data["joinsign"])) return;

        $signPos = Position::fromObject(Vector3::fromString($this->plugin->data["joinsign"][0]), $this->plugin->plugin->getServer()->getWorldManager()->getWorldByName($this->plugin->data["joinsign"][1]));

        if(!$signPos->getWorld() instanceof World) return;

        $signText = [
            "§6§lSumo",
            "§7[ §3? §7/ §3? §7]",
            "§cSetup Mode",
            "§cWait for few sec..."
        ];

        if($signPos->getWorld()->getTile($signPos) === null) return;

        if($this->plugin->setup) {
            /** @var Sign $sign */
            $sign = $signPos->getWorld()->getTile($signPos);
            $sign->setText($signText[0], $signText[1], $signText[2], $signText[3]);
            return;
        }

        $signText[1] = "§7[ §3" . count($this->plugin->players) . " §7/§3 " . $this->plugin->data["slots"] . " §7]";

        switch ($this->plugin->phase) {
            case Arena::PHASE_LOBBY:
                if(count($this->plugin->players) >= $this->plugin->data["slots"]) {
                    $signText[2] = "§cFull";
                    $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                }
                else {
                    $signText[2] = "§2Join";
                    $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                }
                break;
            case Arena::PHASE_GAME:
                $signText[2] = "§cGame in progress";
                $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                break;
            case Arena::PHASE_RESTART:
                $signText[2] = "§cRestarting...";
                $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                break;
        }

        /** @var Sign $sign */
        $sign = $signPos->getWorld()->getTile($signPos);
        $sign->setText($signText[0], $signText[1], $signText[2], $signText[3]);
    }

    public function reloadTimer() {
        $this->startTime = 5;
        $this->gameTime = 10 * 60;
        $this->restartTime = 5;
    }
}
