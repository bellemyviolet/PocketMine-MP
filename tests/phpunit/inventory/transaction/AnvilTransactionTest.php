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

use PHPUnit\Framework\TestCase;
use pocketmine\item\Durable;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use function intdiv;
use function max;
use function min;

final class AnvilTransactionTest extends TestCase{
	public function testRename() : void{
		$input = VanillaItems::DIAMOND_SWORD();
		$result = AnvilTransaction::calculateResult($input, VanillaItems::AIR(), "Blade");

		self::assertNotNull($result);
		self::assertSame("Blade", $result->getResult()->getCustomName());
		self::assertSame(1, $result->getXpCost());
		self::assertSame(1, $result->getResult()->getAnvilRepairCost());
		self::assertTrue($result->isRenameOnly());
	}

	public function testRemoveName() : void{
		$input = VanillaItems::DIAMOND_SWORD()->setCustomName("Blade");
		$result = AnvilTransaction::calculateResult($input, VanillaItems::AIR(), "");

		self::assertNotNull($result);
		self::assertFalse($result->getResult()->hasCustomName());
		self::assertSame(1, $result->getXpCost());
		self::assertTrue($result->isRenameOnly());
	}

	public function testNoNameChangeReturnsNull() : void{
		$input = VanillaItems::DIAMOND_SWORD()->setCustomName("Blade");

		self::assertNull(AnvilTransaction::calculateResult($input, VanillaItems::AIR(), "Blade"));
		self::assertNull(AnvilTransaction::calculateResult(VanillaItems::DIAMOND_SWORD(), VanillaItems::AIR(), null));
	}

	public function testMaterialRepair() : void{
		$input = VanillaItems::DIAMOND_PICKAXE();
		$input->setDamage(800);

		$result = AnvilTransaction::calculateResult($input, VanillaItems::DIAMOND()->setCount(2), null);

		self::assertNotNull($result);
		$output = $result->getResult();
		self::assertInstanceOf(Durable::class, $output);
		self::assertSame(max(0, 800 - intdiv($input->getMaxDurability(), 4) * 2), $output->getDamage());
		self::assertSame(2, $result->getXpCost());
		$consumed = $result->getConsumedItems();
		self::assertCount(2, $consumed);
		self::assertSame(2, $consumed[1]->getCount());
	}

	public function testSameItemRepair() : void{
		$input = VanillaItems::DIAMOND_SWORD();
		$material = VanillaItems::DIAMOND_SWORD();
		$input->setDamage(1000);
		$material->setDamage(900);

		$result = AnvilTransaction::calculateResult($input, $material, null);

		self::assertNotNull($result);
		$output = $result->getResult();
		self::assertInstanceOf(Durable::class, $output);
		$remainingDurability = ($input->getMaxDurability() - 1000) +
			($material->getMaxDurability() - 900) +
			intdiv($input->getMaxDurability() * 12, 100);
		$expectedDamage = max(0, $input->getMaxDurability() - min($input->getMaxDurability(), $remainingDurability));
		self::assertSame($expectedDamage, $output->getDamage());
		self::assertSame(2, $result->getXpCost());
	}

	public function testEnchantmentMergeIncreasesMatchingLevel() : void{
		$input = VanillaItems::DIAMOND_SWORD()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 2));
		$book = VanillaItems::ENCHANTED_BOOK()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 2));

		$result = AnvilTransaction::calculateResult($input, $book, null);

		self::assertNotNull($result);
		self::assertSame(3, $result->getResult()->getEnchantmentLevel(VanillaEnchantments::SHARPNESS()));
		self::assertSame(3, $result->getXpCost());
	}

	public function testEnchantmentMergeCapsAtMaximumLevel() : void{
		$input = VanillaItems::DIAMOND_SWORD()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 4));
		$book = VanillaItems::ENCHANTED_BOOK()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 4));

		$result = AnvilTransaction::calculateResult($input, $book, null);

		self::assertNotNull($result);
		self::assertSame(5, $result->getResult()->getEnchantmentLevel(VanillaEnchantments::SHARPNESS()));
	}

	public function testIncompatibleEnchantmentsAreRejectedWhenNoOtherChangeApplies() : void{
		$input = VanillaItems::DIAMOND_CHESTPLATE()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::PROTECTION()));
		$book = VanillaItems::ENCHANTED_BOOK()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::FIRE_PROTECTION()));

		self::assertNull(AnvilTransaction::calculateResult($input, $book, null));
	}

	public function testEnchantedBookAppliesSecondaryEnchantment() : void{
		$book = VanillaItems::ENCHANTED_BOOK()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::FROST_WALKER()));

		$result = AnvilTransaction::calculateResult(VanillaItems::DIAMOND_BOOTS(), $book, null);

		self::assertNotNull($result);
		self::assertSame(1, $result->getResult()->getEnchantmentLevel(VanillaEnchantments::FROST_WALKER()));
	}

	public function testPriorRepairCostAndResultRepairCost() : void{
		$input = VanillaItems::DIAMOND_SWORD()
			->setAnvilRepairCost(3)
			->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS()));
		$book = VanillaItems::ENCHANTED_BOOK()
			->setAnvilRepairCost(5)
			->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS()));

		$result = AnvilTransaction::calculateResult($input, $book, null);

		self::assertNotNull($result);
		self::assertSame(10, $result->getXpCost());
		self::assertSame(11, $result->getResult()->getAnvilRepairCost());
	}

	public function testNameOnlyCostIsClamped() : void{
		$input = VanillaItems::DIAMOND_SWORD()->setAnvilRepairCost(100);
		$result = AnvilTransaction::calculateResult($input, VanillaItems::AIR(), "Blade");

		self::assertNotNull($result);
		self::assertSame(39, $result->getXpCost());
		self::assertTrue($result->isRenameOnly());
	}

	public function testRepairCostPersistsInNbt() : void{
		$item = VanillaItems::DIAMOND_SWORD()->setAnvilRepairCost(7);
		$deserialized = Item::nbtDeserialize($item->nbtSerialize());

		self::assertSame(7, $deserialized->getAnvilRepairCost());
		$deserialized->clearAnvilRepairCost();
		self::assertSame(0, $deserialized->getAnvilRepairCost());
	}
}
