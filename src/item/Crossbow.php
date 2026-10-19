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

use pocketmine\entity\animation\CrossbowChargedAnimation;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow as ArrowEntity;
use pocketmine\entity\projectile\CrossbowArrow;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\EntityShootCrossbowEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\inventory\Inventory;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\CompletedUsingItemPacket;
use pocketmine\player\Player;
use pocketmine\world\sound\BowShootSound;
use pocketmine\world\sound\CrossbowLoadingEndSound;
use pocketmine\world\sound\CrossbowLoadingStartSound;
use function cos;
use function deg2rad;
use function intdiv;
use function sin;

/**
 * Press and hold to load an arrow, then use again to shoot it.
 * TODO: firework rockets as ammo
 */
class Crossbow extends Tool implements Releasable{
	private const TAG_CHARGED_ITEM = "chargedItem"; //TAG_Compound

	private const BASE_CHARGE_TICKS = 25;
	private const QUICK_CHARGE_TICKS_PER_LEVEL = 5;
	private const ARROW_SPEED = 5.0;
	private const MULTISHOT_SPREAD_DEGREES = 10;

	public function getMaxDurability() : int{
		return 464;
	}

	public function getFuelTime() : int{
		return 200;
	}

	public function isCharged() : bool{
		return $this->getNamedTag()->getTag(self::TAG_CHARGED_ITEM) instanceof CompoundTag;
	}

	/**
	 * Sets the projectile loaded into the crossbow. Pass an air item to unload it.
	 *
	 * @return $this
	 */
	public function setChargedItem(Item $item) : self{
		if($item->isNull()){
			$this->getNamedTag()->removeTag(self::TAG_CHARGED_ITEM);
		}else{
			$this->getNamedTag()->setTag(self::TAG_CHARGED_ITEM, $item->nbtSerialize());
		}
		return $this;
	}

	/**
	 * Returns the number of ticks the crossbow must be held for to load it.
	 */
	public function getChargeTicks() : int{
		return self::BASE_CHARGE_TICKS - $this->getEnchantmentLevel(VanillaEnchantments::QUICK_CHARGE()) * self::QUICK_CHARGE_TICKS_PER_LEVEL;
	}

	private static function findArrowInventory(Player $player) : ?Inventory{
		$arrow = VanillaItems::ARROW();
		return match(true){
			$player->getOffHandInventory()->contains($arrow) => $player->getOffHandInventory(),
			$player->getInventory()->contains($arrow) => $player->getInventory(),
			default => null
		};
	}

	public function canStartUsingItem(Player $player) : bool{
		return !$this->isCharged() && (!$player->hasFiniteResources() || self::findArrowInventory($player) !== null);
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		if($this->isCharged()){
			if(!$this->shoot($player)){
				return ItemUseResult::FAIL;
			}
			if($player->hasFiniteResources()){
				$this->applyDamage(1 + $this->getEnchantmentLevel(VanillaEnchantments::MULTISHOT()) * 2);
			}
			$this->setChargedItem(VanillaItems::AIR());
			return ItemUseResult::SUCCESS;
		}

		if(!$this->canStartUsingItem($player)){
			return ItemUseResult::FAIL;
		}
		$player->broadcastSound(new CrossbowLoadingStartSound());
		return ItemUseResult::NONE;
	}

	public function onReleaseUsing(Player $player, array &$returnedItems) : ItemUseResult{
		if($this->isCharged()){
			return ItemUseResult::FAIL;
		}
		//the client finishes charging by itself, so allow for some latency
		$requiredTicks = $this->getChargeTicks() - intdiv($player->getNetworkSession()->getPing() ?? 0, 50);
		if($player->getItemUseDuration() < $requiredTicks){
			return ItemUseResult::FAIL;
		}

		$arrow = VanillaItems::ARROW();
		$inventory = self::findArrowInventory($player);
		if($player->hasFiniteResources()){
			if($inventory === null){
				return ItemUseResult::FAIL;
			}
			if(!$this->hasEnchantment(VanillaEnchantments::INFINITY())){
				//TODO: tipped arrows are still consumed when Infinity is applied
				$inventory->removeItem($arrow);
			}
		}

		$this->setChargedItem($arrow);
		$player->broadcastSound(new CrossbowLoadingEndSound());
		$player->broadcastAnimation(new CrossbowChargedAnimation($player));
		$player->getNetworkSession()->sendDataPacket(CompletedUsingItemPacket::create($this->getTypeId(), CompletedUsingItemPacket::ACTION_UNKNOWN));

		return ItemUseResult::SUCCESS;
	}

	protected function createArrow(Living $source, int $yawOffset, int $pierceCount) : CrossbowArrow{
		$location = $source->getLocation();
		$yaw = $location->yaw + $yawOffset * self::MULTISHOT_SPREAD_DEGREES;
		$pitch = $location->pitch;

		$xz = cos(deg2rad($pitch));
		$motion = (new Vector3(-$xz * sin(deg2rad($yaw)), -sin(deg2rad($pitch)), $xz * cos(deg2rad($yaw))))->normalize()->multiply(self::ARROW_SPEED);

		//arrow rotation is inverted compared to the player's
		$arrow = new CrossbowArrow(Location::fromObject(
			$source->getEyePos(),
			$source->getWorld(),
			($yaw > 180 ? 360 : 0) - $yaw,
			-$pitch
		), $source, $pierceCount);
		$arrow->setPickupMode(ArrowEntity::PICKUP_CREATIVE);
		$arrow->setMotion($motion);

		if(($punchLevel = $this->getEnchantmentLevel(VanillaEnchantments::PUNCH())) > 0){
			$arrow->setPunchKnockback($punchLevel);
		}
		if(($powerLevel = $this->getEnchantmentLevel(VanillaEnchantments::POWER())) > 0){
			$arrow->setBaseDamage($arrow->getBaseDamage() + (($powerLevel + 1) / 2));
		}
		if($this->hasEnchantment(VanillaEnchantments::FLAME())){
			$arrow->setOnFire(intdiv($arrow->getFireTicks(), 20) + 100);
		}
		return $arrow;
	}

	/**
	 * Shoots the loaded arrows. Returns false if every projectile was cancelled.
	 */
	public function shoot(Living $source) : bool{
		$pierceCount = $this->getEnchantmentLevel(VanillaEnchantments::PIERCING());

		$mainArrow = $this->createArrow($source, 0, $pierceCount);
		$mainArrow->setPickupMode(ArrowEntity::PICKUP_ANY);
		$projectiles = [$mainArrow];
		$multishot = $this->getEnchantmentLevel(VanillaEnchantments::MULTISHOT());
		for($i = 1; $i <= $multishot; ++$i){
			$projectiles[] = $this->createArrow($source, $i, $pierceCount);
			$projectiles[] = $this->createArrow($source, -$i, $pierceCount);
		}

		$ev = new EntityShootCrossbowEvent($source, $this, $projectiles);
		$ev->call();
		if($ev->isCancelled()){
			foreach($projectiles as $projectile){
				$projectile->flagForDespawn();
			}
			return false;
		}

		$allCancelled = true;
		foreach($ev->getProjectiles() as $projectile){
			if($projectile instanceof Projectile){
				$projectileEv = new ProjectileLaunchEvent($projectile);
				$projectileEv->call();
				if($projectileEv->isCancelled()){
					$projectile->flagForDespawn();
					continue;
				}
			}
			$allCancelled = false;
			$projectile->spawnToAll();
		}

		if(!$allCancelled){
			$source->getWorld()->addSound($source->getLocation(), new BowShootSound());
		}
		return !$allCancelled;
	}
}
