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

namespace pocketmine;

use pocketmine\timings\TimingsHandler;
use function gc_collect_cycles;
use function gc_disable;
use function gc_status;
use function hrtime;
use function max;
use function min;
use function number_format;
use function sprintf;

/**
 * Allows threads to manually trigger the cyclic garbage collector using a threshold like PHP's own garbage collector,
 * but triggered at a time that suits the thread instead of in random code pathways.
 *
 * The GC trigger behaviour in this class was adapted from Zend/zend_gc.c as of PHP 8.3.14.
 */
final class GarbageCollectorManager{
	// Raised thresholds for event/BedWars servers:
	// - DEFAULT raised from 10_001 to 100_001 so GC only triggers after many more root objects accumulate
	// - STEP raised so threshold grows faster after a non-productive GC run
	// - TRIGGER kept low so we still benefit from GC when it's useful
	// Net effect: GC runs far less frequently, reducing main-thread stalls
	private const GC_THRESHOLD_TRIGGER = 100;
	private const GC_THRESHOLD_MAX = 1_000_000_000;
	private const GC_THRESHOLD_DEFAULT = 100_001;
	private const GC_THRESHOLD_STEP = 50_000;

	private int $threshold = self::GC_THRESHOLD_DEFAULT;
	private int $collectionTimeTotalNs = 0;
	private int $runs = 0;
	/** Nanosecond timestamp of the last GC run end — enforces minimum interval */
	private int $lastRunEndNs = 0;
	/**
	 * Minimum nanoseconds between two GC runs: 10 seconds.
	 * Prevents back-to-back spikes when roots pile up after a large game burst.
	 */
	private const GC_MIN_INTERVAL_NS = 10_000_000_000;

	private \Logger $logger;
	private TimingsHandler $timings;

	public function __construct(
		\Logger $logger,
		?TimingsHandler $parentTimings,
	){
		gc_disable();
		$this->logger = new \PrefixedLogger($logger, "Cyclic Garbage Collector");
		$this->timings = new TimingsHandler("Cyclic Garbage Collector", $parentTimings);
	}

	private function adjustGcThreshold(int $cyclesCollected, int $rootsAfterGC) : void{
		//TODO Very simple heuristic for dynamic GC buffer resizing:
		//If there are "too few" collections, increase the collection threshold
		//by a fixed step
		//Adapted from zend_gc.c/gc_adjust_threshold() as of PHP 8.3.14
		if($cyclesCollected < self::GC_THRESHOLD_TRIGGER || $rootsAfterGC >= $this->threshold){
			$this->threshold = min(self::GC_THRESHOLD_MAX, $this->threshold + self::GC_THRESHOLD_STEP);
		}elseif($this->threshold > self::GC_THRESHOLD_DEFAULT){
			$this->threshold = max(self::GC_THRESHOLD_DEFAULT, $this->threshold - self::GC_THRESHOLD_STEP);
		}
	}

	public function getThreshold() : int{ return $this->threshold; }

	public function getCollectionTimeTotalNs() : int{ return $this->collectionTimeTotalNs; }

	public function maybeCollectCycles() : int{
		$rootsBefore = gc_status()["roots"];
		if($rootsBefore < $this->threshold){
			return 0;
		}

		// enforce minimum interval to prevent back-to-back spikes
		$now = hrtime(true);
		if($this->lastRunEndNs !== 0 && ($now - $this->lastRunEndNs) < self::GC_MIN_INTERVAL_NS){
			return 0;
		}

		$this->timings->startTiming();

		$start = hrtime(true);
		$cycles = gc_collect_cycles();
		$end = hrtime(true);

		$rootsAfter = gc_status()["roots"];
		$this->adjustGcThreshold($cycles, $rootsAfter);

		$this->timings->stopTiming();

		$time = $end - $start;
		$this->collectionTimeTotalNs += $time;
		$this->lastRunEndNs = $end;
		$this->runs++;
		// debug instead of info — no console spam on every GC run
		$this->logger->debug(sprintf(
			"Run #%d took %s ms (%s -> %s roots, %s cycles collected) - cumulative GC time: %s ms",
			$this->runs,
			number_format($time / 1_000_000, 2),
			$rootsBefore,
			$rootsAfter,
			$cycles,
			number_format($this->collectionTimeTotalNs / 1_000_000, 2)
		));

		return $cycles;
	}
}
