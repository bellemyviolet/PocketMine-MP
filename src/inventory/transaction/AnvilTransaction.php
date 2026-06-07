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

use pocketmine\block\Anvil;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\Durable;
use pocketmine\item\EnchantedBook;
use pocketmine\item\enchantment\AvailableEnchantmentRegistry;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\Rarity;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\sound\AnvilBreakSound;
use pocketmine\world\sound\AnvilUseSound;
use function count;
use function intdiv;
use function max;
use function mb_strlen;
use function min;
use function strlen;

class AnvilTransaction extends InventoryTransaction{
	private const MAX_RENAME_LENGTH_CHARS = 32;
	private const MAX_RENAME_LENGTH_BYTES = 96;
	private const TOO_EXPENSIVE_COST = 40;
	private const NAME_ONLY_MAX_COST = 39;

	public function __construct(
		Player $source,
		private readonly Block $holder,
		private readonly AnvilTransactionResult $calculation,
		array $actions = []
	){
		parent::__construct($source, $actions);
	}

	public function getResult() : Item{
		return $this->calculation->getResult();
	}

	private static function validateRename(string $rename) : void{
		Utils::checkUTF8($rename);
		if(strlen($rename) > self::MAX_RENAME_LENGTH_BYTES || mb_strlen($rename, "UTF-8") > self::MAX_RENAME_LENGTH_CHARS){
			throw new \InvalidArgumentException("Anvil rename must be at most " . self::MAX_RENAME_LENGTH_CHARS . " characters and " . self::MAX_RENAME_LENGTH_BYTES . " bytes");
		}
	}

	public static function calculateResult(Item $input, Item $material, ?string $rename) : ?AnvilTransactionResult{
		if($input->isNull()){
			return null;
		}
		if($rename !== null){
			self::validateRename($rename);
		}

		$result = clone $input;
		$inputRepairCost = $input->getAnvilRepairCost();
		$materialRepairCost = $material->isNull() ? 0 : $material->getAnvilRepairCost();
		$priorWorkCost = $inputRepairCost + $materialRepairCost;
		$operationCost = 0;
		$renamed = false;
		$materialChanged = false;
		$inputConsumedCount = $input->getCount();
		$materialConsumedCount = 0;

		if($rename !== null){
			if($rename === ""){
				if($input->hasCustomName()){
					$result->clearCustomName();
					$operationCost += 1;
					$renamed = true;
				}
			}elseif($rename !== $input->getCustomName()){
				$result->setCustomName($rename);
				$operationCost += 1;
				$renamed = true;
			}
		}

		if(!$material->isNull() && $input->getCount() === 1){
			if($result instanceof Durable && $result->getDamage() > 0 && $result->isValidAnvilRepairMaterial($material)){
				$repairPerMaterial = max(1, intdiv($result->getMaxDurability(), 4));
				$damage = $result->getDamage();
				while($materialConsumedCount < $material->getCount() && $damage > 0){
					$damage = max(0, $damage - $repairPerMaterial);
					++$materialConsumedCount;
					++$operationCost;
				}

				if($materialConsumedCount > 0){
					$result->setDamage($damage);
					$result->setCount(1);
					$inputConsumedCount = 1;
					$materialChanged = true;
				}
			}

			if(
				$materialConsumedCount === 0 &&
				$result instanceof Durable &&
				$material instanceof Durable &&
				self::isSameItemType($result, $material)
			){
				$remainingDurability = ($result->getMaxDurability() - $result->getDamage()) +
					($material->getMaxDurability() - $material->getDamage()) +
					intdiv($result->getMaxDurability() * 12, 100);
				$newDamage = max(0, $result->getMaxDurability() - min($result->getMaxDurability(), $remainingDurability));

				if($newDamage < $result->getDamage()){
					$result->setDamage($newDamage);
					$result->setCount(1);
					$inputConsumedCount = 1;
					$materialConsumedCount = 1;
					$operationCost += 2;
					$materialChanged = true;
				}
			}

			if(self::canMergeEnchantments($result, $material)){
				$sourceIsBook = $material instanceof EnchantedBook;
				$appliedEnchantments = 0;
				foreach($material->getEnchantments() as $sourceEnchantment){
					$mergedEnchantment = self::mergeEnchantment($result, $sourceEnchantment);
					if($mergedEnchantment === null){
						continue;
					}

					$result->addEnchantment($mergedEnchantment);
					$operationCost += self::getEnchantmentMergeCost($mergedEnchantment->getType(), $mergedEnchantment->getLevel(), $sourceIsBook);
					++$appliedEnchantments;
				}

				if($appliedEnchantments > 0){
					$result->setCount(1);
					$inputConsumedCount = 1;
					$materialConsumedCount = max($materialConsumedCount, 1);
					$materialChanged = true;
				}
			}
		}

		if(!$renamed && !$materialChanged){
			return null;
		}

		$renameOnly = $renamed && !$materialChanged;
		$xpCost = $priorWorkCost + $operationCost;
		if($renameOnly){
			$xpCost = min($xpCost, self::NAME_ONLY_MAX_COST);
		}

		$result->setAnvilRepairCost(max($inputRepairCost, $materialRepairCost) * 2 + 1);

		$consumed = [];
		$consumed[] = (clone $input)->setCount($inputConsumedCount);
		if($materialConsumedCount > 0){
			$consumed[] = (clone $material)->setCount($materialConsumedCount);
		}

		return new AnvilTransactionResult($result, $consumed, $xpCost, $renameOnly);
	}

	private static function isSameItemType(Item $target, Item $source) : bool{
		return $target->getTypeId() === $source->getTypeId() && $target->getStateId() === $source->getStateId();
	}

	private static function canMergeEnchantments(Item $target, Item $source) : bool{
		return $source instanceof EnchantedBook ||
			$target instanceof EnchantedBook ||
			($target instanceof Durable && $source instanceof Durable && self::isSameItemType($target, $source));
	}

	private static function mergeEnchantment(Item $target, EnchantmentInstance $sourceEnchantment) : ?EnchantmentInstance{
		$type = $sourceEnchantment->getType();
		if(!($target instanceof EnchantedBook) && !AvailableEnchantmentRegistry::getInstance()->isAvailableForItem($type, $target)){
			return null;
		}

		foreach($target->getEnchantments() as $existingEnchantment){
			$existingType = $existingEnchantment->getType();
			if($existingType === $type){
				continue;
			}
			if(!$type->isCompatibleWith($existingType) || !$existingType->isCompatibleWith($type)){
				return null;
			}
		}

		$currentLevel = $target->getEnchantmentLevel($type);
		$sourceLevel = $sourceEnchantment->getLevel();
		if($currentLevel > 0){
			$newLevel = $currentLevel === $sourceLevel ? $currentLevel + 1 : max($currentLevel, $sourceLevel);
		}else{
			$newLevel = $sourceLevel;
		}
		$newLevel = min($newLevel, $type->getMaxLevel());
		if($newLevel <= $currentLevel){
			return null;
		}

		return new EnchantmentInstance($type, $newLevel);
	}

	private static function getEnchantmentMergeCost(Enchantment $type, int $level, bool $sourceIsBook) : int{
		$cost = $type->getAnvilCost() * $level;
		if(!$sourceIsBook && $type->getRarity() !== Rarity::COMMON){
			$cost *= 2;
		}

		return $cost;
	}

	/**
	 * @param Item[] $expectedItems
	 * @param Item[] $actualItems
	 * @phpstan-param list<Item> $expectedItems
	 * @phpstan-param list<Item> $actualItems
	 */
	private static function assertItemListMatches(array $expectedItems, array $actualItems, string $description) : void{
		$expectedItems = Utils::cloneObjectArray($expectedItems);
		$actualItems = Utils::cloneObjectArray($actualItems);

		foreach($expectedItems as $expectedKey => $expectedItem){
			foreach($actualItems as $actualKey => $actualItem){
				if($expectedItem->canStackWith($actualItem)){
					$amount = min($expectedItem->getCount(), $actualItem->getCount());
					$expectedItem->setCount($expectedItem->getCount() - $amount);
					$actualItem->setCount($actualItem->getCount() - $amount);
					if($actualItem->getCount() === 0){
						unset($actualItems[$actualKey]);
					}
					if($expectedItem->getCount() === 0){
						unset($expectedItems[$expectedKey]);
						break;
					}
				}
			}
		}

		if(count($expectedItems) > 0){
			throw new TransactionValidationException("Transaction did not include all expected $description items");
		}
		if(count($actualItems) > 0){
			throw new TransactionValidationException("Transaction included unexpected $description items");
		}
	}

	public function validate() : void{
		$this->squashDuplicateSlotChanges();
		if(count($this->actions) < 1){
			throw new TransactionValidationException("Transaction must have at least one action to be executable");
		}

		/** @var Item[] $createdItems */
		$createdItems = [];
		/** @var Item[] $consumedItems */
		$consumedItems = [];
		$this->matchItems($createdItems, $consumedItems);

		self::assertItemListMatches([$this->calculation->getResult()], $createdItems, "created");
		self::assertItemListMatches($this->calculation->getConsumedItems(), $consumedItems, "consumed");

		if($this->source->hasFiniteResources()){
			$cost = $this->calculation->getXpCost();
			if(!$this->calculation->isRenameOnly() && $cost >= self::TOO_EXPENSIVE_COST){
				throw new TransactionValidationException("Anvil operation is too expensive");
			}
			if($this->source->getXpManager()->getXpLevel() < $cost){
				throw new TransactionValidationException("Player's XP level is less than the anvil cost");
			}
		}
	}

	public function execute() : void{
		parent::execute();

		if($this->source->hasFiniteResources()){
			$cost = $this->calculation->getXpCost();
			if($cost > 0){
				$this->source->getXpManager()->subtractXpLevels($cost);
			}
		}

		$this->applyAnvilUseEffects();
	}

	private function applyAnvilUseEffects() : void{
		$position = $this->holder->getPosition();
		$world = $position->getWorld();
		$world->addSound($position, new AnvilUseSound());

		if($this->source->isCreative() || Utils::getRandomFloat() >= 0.12){
			return;
		}

		$currentBlock = $world->getBlock($position);
		if(!$currentBlock instanceof Anvil){
			return;
		}

		if($currentBlock->getDamage() >= Anvil::VERY_DAMAGED){
			$world->addSound($position, new AnvilBreakSound());
			$world->setBlock($position, VanillaBlocks::AIR());
			$world->addParticle($position, new BlockBreakParticle($currentBlock));
		}else{
			$world->setBlock($position, $currentBlock->setDamage($currentBlock->getDamage() + 1));
		}
	}
}
