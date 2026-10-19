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

use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Explosive;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\world\Explosion;
use pocketmine\world\particle\CriticalParticle;

/**
 * A fireball thrown from a fire charge, which explodes on impact. Players can deflect it by hitting it.
 */
class Fireball extends Throwable implements Explosive{
	private const MAX_AGE = 300;
	private const OWNER_IMMUNITY_TICKS = 20;
	private const EXPLOSION_RADIUS = 4;

	public static function getNetworkTypeId() : string{ return EntityIds::FIREBALL; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.31, 0.31); }

	protected function getInitialGravity() : float{ return 0.0; }

	protected function onHit(ProjectileHitEvent $event) : void{
		$this->getWorld()->addParticle($this->location->add(0, $this->size->getHeight() / 2, 0), new CriticalParticle());
		$this->explode();
	}

	public function explode() : void{
		$ev = new EntityPreExplodeEvent($this, self::EXPLOSION_RADIUS);
		$ev->call();
		if(!$ev->isCancelled()){
			$explosion = new Explosion($this->location, $ev->getRadius(), $this, $ev->getFireChance());
			if($ev->isBlockBreaking()){
				$explosion->explodeA();
			}
			$explosion->explodeB();
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		if($this->closed){
			return false;
		}
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->isCollided || $this->ticksLived > self::MAX_AGE){
			$this->flagForDespawn();
		}
		return $hasUpdate;
	}

	public function canBeCollidedWith() : bool{
		//so that players can hit it back
		return $this->isAlive();
	}

	public function attack(EntityDamageEvent $source) : void{
		$damager = $source instanceof EntityDamageByEntityEvent ? $source->getDamager() : null;
		if($damager !== null && $damager === $this->getOwningEntity() && $this->ticksLived < self::OWNER_IMMUNITY_TICKS){
			$source->cancel();
		}
		$source->call();
		if($source->isCancelled()){
			return;
		}
		if($damager instanceof Player){
			//deflect it
			$this->setMotion($damager->getDirectionVector()->multiply($this->motion->length()));
			$this->setOwningEntity($damager);
		}
	}
}
