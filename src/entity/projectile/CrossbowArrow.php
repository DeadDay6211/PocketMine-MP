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
use pocketmine\entity\Location;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use function count;

/**
 * An arrow fired from a crossbow, which can pierce through entities with the Piercing enchantment.
 */
class CrossbowArrow extends Arrow{
	public const TAG_PIERCE_COUNT = "pierceCount"; //TAG_Int

	/**
	 * @var true[] entity runtime ID => true
	 * @phpstan-var array<int, true>
	 */
	private array $hitEntities = [];
	/** Motion to restore after piercing through an entity */
	private ?Vector3 $pierceMotion = null;

	public function __construct(Location $location, ?Entity $shootingEntity, private int $pierceCount, ?CompoundTag $nbt = null){
		parent::__construct($location, $shootingEntity, true, $nbt);
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->pierceCount = $nbt->getInt(self::TAG_PIERCE_COUNT, $this->pierceCount);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_PIERCE_COUNT, $this->pierceCount);
		return $nbt;
	}

	public function getPierceCount() : int{
		return $this->pierceCount;
	}

	public function getResultDamage() : int{
		return 9;
	}

	public function canCollideWith(Entity $entity) : bool{
		return !isset($this->hitEntities[$entity->getId()]) && parent::canCollideWith($entity);
	}

	protected function despawnsOnEntityHit() : bool{
		return count($this->hitEntities) > $this->pierceCount;
	}

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		$this->hitEntities[$entityHit->getId()] = true;
		$critical = $this->isCritical();
		$motion = clone $this->motion;
		parent::onHitEntity($entityHit, $hitResult);
		if(!$this->despawnsOnEntityHit()){
			$this->setCritical($critical);
			$this->pierceMotion = $motion;
		}
	}

	protected function move(float $dx, float $dy, float $dz) : void{
		parent::move($dx, $dy, $dz);
		if($this->pierceMotion !== null){
			//keep flying through the pierced entity
			$this->motion = $this->pierceMotion;
			$this->pierceMotion = null;
			$this->isCollided = $this->onGround = false;
		}
	}
}
