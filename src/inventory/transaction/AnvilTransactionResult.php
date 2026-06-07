<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\inventory\transaction;

use pocketmine\item\Item;
use pocketmine\utils\Utils;

/**
 * @internal
 */
final class AnvilTransactionResult{
	/** @var list<Item> */
	private array $consumedItems;

	/**
	 * @param Item[] $consumedItems
	 * @phpstan-param list<Item> $consumedItems
	 */
	public function __construct(
		private Item $result,
		array $consumedItems,
		private int $xpCost,
		private bool $renameOnly
	){
		$this->consumedItems = $consumedItems;
	}

	public function getResult() : Item{
		return clone $this->result;
	}

	/**
	 * @return Item[]
	 * @phpstan-return list<Item>
	 */
	public function getConsumedItems() : array{
		return Utils::cloneObjectArray($this->consumedItems);
	}

	public function getXpCost() : int{
		return $this->xpCost;
	}

	public function isRenameOnly() : bool{
		return $this->renameOnly;
	}
}
