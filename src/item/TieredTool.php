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

namespace pocketmine\item;

use pocketmine\block\VanillaBlocks;

abstract class TieredTool extends Tool{
	protected ToolTier $tier;

	/**
	 * @param string[] $enchantmentTags
	 */
	public function __construct(ItemIdentifier $identifier, string $name, ToolTier $tier, array $enchantmentTags = []){
		parent::__construct($identifier, $name, $enchantmentTags);
		$this->tier = $tier;
	}

	public function getMaxDurability() : int{
		return $this->tier->getMaxDurability();
	}

	public function getTier() : ToolTier{
		return $this->tier;
	}

	protected function getBaseMiningEfficiency() : float{
		return $this->tier->getBaseEfficiency();
	}

	public function getEnchantability() : int{
		return $this->tier->getEnchantability();
	}

	public function getFuelTime() : int{
		if($this->tier === ToolTier::WOOD){
			return 200;
		}

		return 0;
	}

	public function isFireProof() : bool{
		return $this->tier === ToolTier::NETHERITE;
	}

	public function isValidAnvilRepairMaterial(Item $material) : bool{
		return match($this->tier){
			ToolTier::WOOD => self::isPlanks($material),
			ToolTier::STONE => VanillaBlocks::COBBLESTONE()->asItem()->equals($material, false, false),
			ToolTier::COPPER => $material->getTypeId() === ItemTypeIds::COPPER_INGOT,
			ToolTier::IRON => $material->getTypeId() === ItemTypeIds::IRON_INGOT,
			ToolTier::GOLD => $material->getTypeId() === ItemTypeIds::GOLD_INGOT,
			ToolTier::DIAMOND => $material->getTypeId() === ItemTypeIds::DIAMOND,
			ToolTier::NETHERITE => $material->getTypeId() === ItemTypeIds::NETHERITE_INGOT
		};
	}

	private static function isPlanks(Item $material) : bool{
		foreach([
			VanillaBlocks::ACACIA_PLANKS(),
			VanillaBlocks::BAMBOO_PLANKS(),
			VanillaBlocks::BIRCH_PLANKS(),
			VanillaBlocks::CHERRY_PLANKS(),
			VanillaBlocks::CRIMSON_PLANKS(),
			VanillaBlocks::DARK_OAK_PLANKS(),
			VanillaBlocks::JUNGLE_PLANKS(),
			VanillaBlocks::MANGROVE_PLANKS(),
			VanillaBlocks::OAK_PLANKS(),
			VanillaBlocks::PALE_OAK_PLANKS(),
			VanillaBlocks::SPRUCE_PLANKS(),
			VanillaBlocks::WARPED_PLANKS(),
		] as $planks){
			if($planks->asItem()->equals($material, false, false)){
				return true;
			}
		}

		return false;
	}
}
