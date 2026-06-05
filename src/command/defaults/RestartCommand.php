<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\permission\DefaultPermissionNames;

/** [BETTERPMMP-PATCH] */
class RestartCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"restart",
			"Restart the server"
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_STOP);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		// Use the directory 5 levels above this file (source/src/command/defaults/ → server root)
		// to ensure the flag is always written to the same place start.cmd checks.
		$restartFlag = dirname(__FILE__, 5) . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'restart.flag';
		file_put_contents($restartFlag, '1');

		Command::broadcastCommandMessage($sender, "§eServer is restarting...");

		$sender->getServer()->shutdown();
		return true;
	}
}