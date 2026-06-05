<?php

declare(strict_types=1);

/** [BETTERPMMP-PATCH] */

namespace pocketmine\plugin;

use pocketmine\command\Command;
use pocketmine\event\RegisteredListener;
use pocketmine\permission\Permission;
use pocketmine\scheduler\TaskHandler;

final class PluginResources{

	/**
	 * @param RegisteredListener[] $handlers
	 * @param Command[]            $commands
	 * @param Permission[]         $permissions
	 * @param TaskHandler[]        $schedulerTasks
	 */
	public function __construct(
		private array $handlers = [],
		private array $commands = [],
		private array $permissions = [],
		private array $schedulerTasks = []
	){}

	/** @return RegisteredListener[] */
	public function getHandlers() : array{
		return $this->handlers;
	}

	/** @return Command[] */
	public function getCommands() : array{
		return $this->commands;
	}

	/** @return Permission[] */
	public function getPermissions() : array{
		return $this->permissions;
	}

	/** @return TaskHandler[] */
	public function getSchedulerTasks() : array{
		return $this->schedulerTasks;
	}

	/** @param TaskHandler[] $tasks */
	public function setSchedulerTasks(array $tasks) : void{
		$this->schedulerTasks = $tasks;
	}
}