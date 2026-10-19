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

namespace pocketmine\block\tile;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\TypeConverter;

/**
 * The client needs this tile to render the statue. It stores the golem's pose.
 */
class CopperGolemStatue extends Spawnable{
	public const MIN_POSE = 0;
	public const MAX_POSE = 3;

	private const TAG_POSE = "Pose"; //TAG_Int

	private int $pose = self::MIN_POSE;

	public function getPose() : int{
		return $this->pose;
	}

	public function setPose(int $pose) : void{
		if($pose < self::MIN_POSE || $pose > self::MAX_POSE){
			throw new \InvalidArgumentException("Pose must be in range " . self::MIN_POSE . " ... " . self::MAX_POSE);
		}
		$this->pose = $pose;
		$this->clearSpawnCompoundCache();
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$pose = $nbt->getInt(self::TAG_POSE, self::MIN_POSE);
		$this->pose = $pose >= self::MIN_POSE && $pose <= self::MAX_POSE ? $pose : self::MIN_POSE;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setInt(self::TAG_POSE, $this->pose);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt, TypeConverter $typeConverter) : void{
		$nbt->setInt(self::TAG_POSE, $this->pose);
	}
}
