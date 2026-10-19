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

use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\FishingHook;
use pocketmine\event\player\PlayerFishingRodCastEvent;
use pocketmine\event\player\PlayerFishingRodReelEvent;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\ThrowSound;
use function abs;

/**
 * TODO: actual fishing (bites and loot) is not implemented, the hook only pulls entities
 */
class FishingRod extends Durable{
	private const TAG_HOOK_ENTITY_ID = "runtime-id"; //TAG_Long

	private const CAST_FORCE = 1.3;
	private const COMBAT_CAST_FORCE = 1.8;
	private const MAX_PULL_FORCE = 0.4;

	private bool $combatRod = false;

	public function getMaxStackSize() : int{
		return 1;
	}

	public function getMaxDurability() : int{
		return 384;
	}

	public function getFuelTime() : int{
		return 300;
	}

	/**
	 * Combat rods don't pull hooked entities, and recast immediately if the hook is attached to an entity.
	 */
	public function isCombatRod() : bool{ return $this->combatRod; }

	/** @return $this */
	public function setCombatRod(bool $combatRod) : self{
		$this->combatRod = $combatRod;
		return $this;
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		$hook = $this->getFishingHook($player);
		if($hook === null){
			$result = $this->cast($player);
		}elseif($this->combatRod && $hook->getTargetEntity() !== null){
			$hook->flagForDespawn();
			$result = $this->cast($player);
		}else{
			$result = $this->reel($player, $hook);
		}
		return $result ? ItemUseResult::SUCCESS : ItemUseResult::FAIL;
	}

	/**
	 * Returns the hook cast by this rod, if it still exists.
	 */
	public function getFishingHook(Player $player) : ?FishingHook{
		$hookId = $this->getNamedTag()->getLong(self::TAG_HOOK_ENTITY_ID, -1);
		if($hookId === -1){
			return null;
		}
		$hook = $player->getWorld()->getEntity($hookId);
		return $hook instanceof FishingHook && !$hook->isFlaggedForDespawn() ? $hook : null;
	}

	private function setHookEntityId(?int $id) : void{
		if($id === null){
			$this->getNamedTag()->removeTag(self::TAG_HOOK_ENTITY_ID);
		}else{
			$this->getNamedTag()->setLong(self::TAG_HOOK_ENTITY_ID, $id);
		}
	}

	/**
	 * Returns false if the cast was cancelled.
	 */
	public function cast(Player $player) : bool{
		$ev = new PlayerFishingRodCastEvent($player, $this);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}

		$location = $player->getLocation();
		$hook = new FishingHook(Location::fromObject(
			$location->add(0, $player->getEyeHeight() * 0.75, 0),
			$location->getWorld(),
			$location->yaw,
			$location->pitch
		), $player, $this->combatRod);
		$hook->setMotion($player->getDirectionVector()->multiply($this->combatRod ? self::COMBAT_CAST_FORCE : self::CAST_FORCE));
		$hook->spawnToAll();
		$location->getWorld()->addSound($location, new ThrowSound());

		$this->setHookEntityId($hook->getId());
		return true;
	}

	/**
	 * Returns false if reeling in was cancelled.
	 */
	public function reel(Player $player, FishingHook $hook) : bool{
		$ev = new PlayerFishingRodReelEvent($player, $this);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}

		$hook->flagForDespawn();
		$target = $hook->getTargetEntity();
		if(!$this->combatRod && $target instanceof Living && $target !== $player){
			$motion = $target->getLocation()->subtractVector($player->getEyePos())->normalize();
			$motion->x *= -0.5;
			$motion->y *= -1.5;
			$motion->z *= -0.5;
			if(abs($motion->x) > self::MAX_PULL_FORCE){
				$motion->x = $motion->x < 0 ? -self::MAX_PULL_FORCE : self::MAX_PULL_FORCE;
			}
			if(abs($motion->z) > self::MAX_PULL_FORCE){
				$motion->z = $motion->z < 0 ? -self::MAX_PULL_FORCE : self::MAX_PULL_FORCE;
			}
			$target->setMotion($motion);
		}

		$this->setHookEntityId(null);
		if($player->hasFiniteResources()){
			$this->applyDamage(1);
		}
		return true;
	}
}
