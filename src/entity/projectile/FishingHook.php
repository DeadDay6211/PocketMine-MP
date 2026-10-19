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

namespace pocketmine\entity\projectile;

use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\item\FishingRod;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\SetActorLinkPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;

/**
 * The bobber cast by a fishing rod. It attaches to the first living entity it hits.
 */
class FishingHook extends Projectile{

	public static function getNetworkTypeId() : string{ return EntityIds::FISHING_HOOK; }

	public function __construct(Location $location, ?Entity $shootingEntity, private bool $combatHook = false, ?CompoundTag $nbt = null){
		parent::__construct($location, $shootingEntity, $nbt);
		$this->setCanSaveWithChunk(false);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.15, 0.15); }

	protected function getInitialDragMultiplier() : float{ return 0.01; }

	protected function getInitialGravity() : float{ return 0.05; }

	public function isCombatHook() : bool{ return $this->combatHook; }

	public function getResultDamage() : int{
		return 0;
	}

	protected function despawnsOnEntityHit() : bool{
		return $this->combatHook;
	}

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		parent::onHitEntity($entityHit, $hitResult);
		if(!$entityHit->isAlive()){
			return;
		}

		$this->setTargetEntity($entityHit);
		if(!$this->combatHook){
			NetworkBroadcastUtils::broadcastPackets($entityHit->getViewers(), [
				SetActorLinkPacket::create(new EntityLink($entityHit->getId(), $this->getId(), EntityLink::TYPE_RIDER, true, true, 0.0))
			]);
			$this->getNetworkProperties()->setVector3(EntityMetadataProperties::RIDER_SEAT_POSITION, new Vector3(0, $entityHit->getSize()->getHeight(), 0));
		}

		$owner = $this->getOwningEntity();
		if($entityHit instanceof Living && $owner !== null){
			$diff = $entityHit->getPosition()->subtractVector($owner->getPosition());
			$entityHit->knockBack($diff->x, $diff->z, 0.2);
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);

		$owner = $this->getOwningEntity();
		if(
			!$owner instanceof Player ||
			!$owner->isAlive() ||
			!$owner->isConnected() ||
			$owner->getWorld() !== $this->getWorld() ||
			!$owner->getInventory()->getItemInHand() instanceof FishingRod
		){
			$this->flagForDespawn();
			return $hasUpdate;
		}

		$target = $this->getTargetEntity();
		if($target !== null && !$target->isClosed() && $target->isAlive() && $target->getWorld() === $this->getWorld()){
			$this->location = $target->getLocation();
			$this->location->y += $target->getSize()->getHeight();
		}

		if($this->isUnderwater()){
			$this->motion = new Vector3($this->motion->x / 2, ($this->motion->y + 0.25) / 2, $this->motion->z / 2);
			$this->gravityEnabled = false;
		}else{
			$this->gravityEnabled = true;
		}

		return $hasUpdate;
	}
}
