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

use pocketmine\inventory\ArmorInventory;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\ArmorEquipElytraSound;

/**
 * TODO: elytra durability isn't consumed while gliding yet
 */
class Elytra extends Durable{

	public function getMaxStackSize() : int{
		return 1;
	}

	public function getMaxDurability() : int{
		return 432;
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		$armorInventory = $player->getArmorInventory();
		$existing = $armorInventory->getItem(ArmorInventory::SLOT_CHEST);
		$thisCopy = clone $this;
		$new = $thisCopy->pop();
		$armorInventory->setItem(ArmorInventory::SLOT_CHEST, $new);
		$player->getInventory()->setItemInHand($existing);
		$player->broadcastSound(new ArmorEquipElytraSound());
		if(!$thisCopy->isNull()){
			$returnedItems[] = $thisCopy;
		}
		return ItemUseResult::SUCCESS;
	}
}
