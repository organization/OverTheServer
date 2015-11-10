<?php

namespace ifteam\OverTheServer;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\command\CommandSender;
use pocketmine\utils\Config;
use pocketmine\command\PluginCommand;
use pocketmine\utils\TextFormat;
use pocketmine\Player;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\protocol\Info;
use pocketmine\command\Command;
use pocketmine\event\player\PlayerQuitEvent;

class OverTheServer extends PluginBase implements Listener {
	private static $instance = null;
	public $messages, $db;
	public $m_version = 4;
	public $comatoseState = [ ];
	public $preventQuitEvent = [ ];
	public function onEnable() {
		@mkdir ( $this->getDataFolder () ); // 플러그인 폴더생성
		
		$this->initMessage (); // 기본언어메시지 초기화
		                       
		// YAML 형식의 DB생성 후 불러오기
		$this->db = (new Config ( $this->getDataFolder () . "pluginDB.yml", Config::YAML, [ ] ))->getAll ();
		
		// 플러그인의 인스턴스 정의
		if (self::$instance == null)
			self::$instance = $this;
			
			// 플러그인의 명령어 등록
		$this->registerCommand ( $this->get ( "overtheserver" ), "overtheserver.control", $this->get ( "overtheserver-desc" ), $this->get ( "overtheserver-help" ) );
		$this->registerCommand ( $this->get ( "serverconnect" ), "overtheserver.serverconnect", $this->get ( "serverconnect-desc" ), $this->get ( "serverconnect-help" ) );
		$this->registerCommand ( $this->get ( "serverlist" ), "overtheserver.serverlist", $this->get ( "serverlist-desc" ), $this->get ( "serverlist-help" ) );
		
		$this->getServer ()->getPluginManager ()->registerEvents ( $this, $this );
	}
	public static function getInstance() {
		return static::$instance;
	}
	public function onDataPacketSend(DataPacketSendEvent $event) {
		if ($this->isComatoseState ( $event->getPlayer ()->getName () )) {
			if (! $event->getPacket () instanceof StrangePacket)
				$event->setCancelled ();
		}
	}
	public function onDataPacketReceived(DataPacketReceiveEvent $event) {
		if ($event->getPacket ()->pid () == Info::LOGIN_PACKET) {
			if ($this->isComatoseState ( $event->getPlayer ()->getName () ))
				$this->setComatoseState ( $event->getPlayer ()->getName (), false );
		}
	}
	public function onCommand(CommandSender $player, Command $command, $label, array $args) {
		switch ($command) {
			case $this->get ( "overtheserver" ) :
				if (! isset ( $args [0] )) {
					$this->message ( $player, $this->get ( "overtheserver-help" ) );
					return true;
				}
				switch (strtolower ( $args [0] )) {
					case $this->get ( "add" ) :
						if (! isset ( $args [2] )) {
							$this->message ( $player, $this->get ( "overtheserver-help" ) );
							return true;
						}
						$this->db ["list"] [$args [1]] = $args [2];
						$this->message ( $player, $this->get ( "add-success" ) );
						break;
					case $this->get ( "del" ) :
						if (! isset ( $args [1] )) {
							$this->message ( $player, $this->get ( "overtheserver-help" ) );
							return true;
						}
						if (isset ( $this->db ["list"] [$args [1]] ))
							unset ( $this->db ["list"] [$args [1]] );
						$this->message ( $player, $this->get ( "del-success" ) );
						break;
				}
				break;
		}
		return true;
	}
	public function onPlayerQuitEvent(PlayerQuitEvent $event) {
		if (isset ( $this->comatoseState [strtolower ( $event->getPlayer ()->getName () )] )) {
			$this->setComatoseState ( strtolower ( $event->getPlayer ()->getName () ), false );
			unset ( $this->comatoseState [strtolower ( $event->getPlayer ()->getName () )] );
		}
	}
	public function onPreCommand(PlayerCommandPreprocessEvent $event) {
		if (\substr ( $event->getMessage (), 0, 1 ) != "/")
			return;
		
		$player = $event->getPlayer ();
		$args = explode ( " ", \substr ( $event->getMessage (), 1 ) );
		$command = \strtolower ( \array_shift ( $args ) );
		
		switch ($command) {
			case $this->get ( "serverconnect" ) :
				$event->setCancelled ();
				if (! isset ( $args [0] )) {
					$this->message ( $player, $this->get ( "serverconnect-help" ) );
					return true;
				}
				if (! isset ( $this->db ["list"] [$args [0]] )) {
					$this->message ( $player, $this->get ( "server-doesnt-exist" ) );
					return true;
				}
				if (! $player instanceof Player) {
					$this->message ( $player, $this->get ( "ingame-only" ) );
					return true;
				}
				$data = explode ( ":", $this->db ["list"] [$args [0]] );
				$player->dataPacket ( (new StrangePacket ( $data [0], $data [1] )) );
				
				$this->setComatoseState ( $player->getName (), true );
				
				$this->preventQuitEvent [strtolower ( $player->getName () )] = true;
				$this->getServer ()->getPluginManager ()->callEvent ( $ev = new PlayerQuitEvent ( $player, $this->get ( "cause-server-moved" ), \true ) );
				if ($player->loggedIn === \true and $ev->getAutoSave ())
					$player->save ();
				$this->getServer ()->broadcastMessage ( $ev->getQuitMessage () );
				$player->despawnFromAll ();
				$this->getServer ()->removeOnlinePlayer ( $player );
				
				break;
			case $this->get ( "serverlist" ) :
				$event->setCancelled ();
				$serverList = "";
				if (isset ( $this->db ["list"] ))
					foreach ( $this->db ["list"] as $index => $data )
						$serverList .= "[ " . $index . " ] ";
				$this->message ( $player, $this->get ( "print-all-server-list" ) );
				$this->message ( $player, $this->get ( "can-access-serverconnect" ) );
				$this->message ( $player, $serverList, "" );
				break;
		}
	}
	public function get($var) {
		if (isset ( $this->messages [$this->getServer ()->getLanguage ()->getLang ()] )) {
			$lang = $this->getServer ()->getLanguage ()->getLang ();
		} else {
			$lang = "eng";
		}
		return $this->messages [$lang . "-" . $var];
	}
	public function initMessage() {
		$this->saveResource ( "messages.yml", false );
		$this->messagesUpdate ( "messages.yml" );
		$this->messages = (new Config ( $this->getDataFolder () . "messages.yml", Config::YAML ))->getAll ();
	}
	public function messagesUpdate($targetYmlName) {
		$targetYml = (new Config ( $this->getDataFolder () . $targetYmlName, Config::YAML ))->getAll ();
		if (! isset ( $targetYml ["m_version"] )) {
			$this->saveResource ( $targetYmlName, true );
		} else if ($targetYml ["m_version"] < $this->m_version) {
			$this->saveResource ( $targetYmlName, true );
		}
	}
	public function registerCommand($name, $permission, $description = "", $usage = "") {
		$commandMap = $this->getServer ()->getCommandMap ();
		$command = new PluginCommand ( $name, $this );
		$command->setDescription ( $description );
		$command->setPermission ( $permission );
		$command->setUsage ( $usage );
		$commandMap->register ( $name, $command );
	}
	public function message(CommandSender $player, $text = "", $mark = null) {
		if ($mark === null)
			$mark = $this->get ( "default-prefix" );
		$player->sendMessage ( TextFormat::DARK_AQUA . $mark . " " . $text );
	}
	public function alert(CommandSender $player, $text = "", $mark = null) {
		if ($mark === null)
			$mark = $this->get ( "default-prefix" );
		$player->sendMessage ( TextFormat::RED . $mark . " " . $text );
	}
	public function onDisable() {
		$save = new Config ( $this->getDataFolder () . "pluginDB.yml", Config::YAML );
		$save->setAll ( $this->db );
		$save->save ();
	} // $comatoseState
	public function isComatoseState($playerName) {
		$playerName = strtolower ( $playerName );
		return isset ( $this->comatoseState [$playerName] );
	}
	public function setComatoseState($playerName, $isComa) {
		$playerName = strtolower ( $playerName );
		if ($isComa) {
			$this->comatoseState [$playerName] = true;
		} else {
			if (isset ( $this->comatoseState [$playerName] ))
				unset ( $this->comatoseState [$playerName] );
		}
	}
}

?>