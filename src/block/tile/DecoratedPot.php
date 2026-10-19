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
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use function array_pad;
use function array_slice;
use function count;

/**
 * The client needs this tile to render the pot.
 */
class DecoratedPot extends Spawnable{
	private const TAG_SHERDS = "sherds"; //TAG_List<TAG_String>
	private const DEFAULT_SHERD = "minecraft:brick";
	private const SHERD_COUNT = 4;

	/** @var string[] */
	private array $sherds = [self::DEFAULT_SHERD, self::DEFAULT_SHERD, self::DEFAULT_SHERD, self::DEFAULT_SHERD];

	/**
	 * @return string[]
	 */
	public function getSherds() : array{
		return $this->sherds;
	}

	/**
	 * @param string[] $sherds item type names, e.g. minecraft:brick
	 */
	public function setSherds(array $sherds) : void{
		if(count($sherds) !== self::SHERD_COUNT){
			throw new \InvalidArgumentException("Expected exactly " . self::SHERD_COUNT . " sherds");
		}
		$this->sherds = $sherds;
		$this->clearSpawnCompoundCache();
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$sherdsTag = $nbt->getListTag(self::TAG_SHERDS, StringTag::class);
		if($sherdsTag !== null){
			$sherds = [];
			foreach($sherdsTag as $tag){
				$sherds[] = $tag->getValue();
			}
			$this->sherds = array_pad(array_slice($sherds, 0, self::SHERD_COUNT), self::SHERD_COUNT, self::DEFAULT_SHERD);
		}
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setTag(self::TAG_SHERDS, $this->createSherdsTag());
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt, TypeConverter $typeConverter) : void{
		$nbt->setTag(self::TAG_SHERDS, $this->createSherdsTag());
	}

	private function createSherdsTag() : ListTag{
		$list = new ListTag();
		foreach($this->sherds as $sherd){
			$list->push(new StringTag($sherd));
		}
		return $list;
	}
}
