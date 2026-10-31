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

namespace pocketmine\console;

use pocketmine\utils\MainLogger;
use function array_slice;
use function array_splice;
use function array_unique;
use function array_values;
use function count;
use function end;
use function explode;
use function implode;
use function max;
use function mb_chr;
use function mb_str_split;
use function mb_strlen;
use function mb_strrpos;
use function mb_strtolower;
use function mb_strwidth;
use function mb_substr;
use function min;
use function sort;
use function str_starts_with;
use function trim;
use const SORT_FLAG_CASE;
use const SORT_STRING;

/**
 * Keeps the line being typed on the last row of the console, below the log output, like the proxy console does.
 * Tab with several matches shows them on the row below the prompt; Tab/Shift+Tab or the arrow keys pick one and
 * Enter puts it in the line.
 * Fed with key events from ConsoleKeyReader.ps1.
 */
final class ConsoleLineEditor{
	private const PROMPT = "> ";
	private const HISTORY_SIZE = 100;

	private const KEY_BACKSPACE = 8;
	private const KEY_TAB = 9;
	private const KEY_ENTER = 13;
	private const KEY_ESCAPE = 27;
	private const KEY_END = 35;
	private const KEY_HOME = 36;
	private const KEY_LEFT = 37;
	private const KEY_UP = 38;
	private const KEY_RIGHT = 39;
	private const KEY_DOWN = 40;
	private const KEY_DELETE = 46;

	private const MOD_ALT = 1;
	private const MOD_SHIFT = 2;
	private const MOD_CONTROL = 4;

	/** @var string[] */
	private array $chars = [];
	private int $cursor = 0;
	private int $offset = 0;
	private int $width = 80;
	private int $highSurrogate = 0;
	/** @var string[] */
	private array $history = [];
	private int $historyIndex = 0;
	/** @var string[] */
	private array $menu = [];
	private int $menuIndex = -1;
	private int $menuTokenLength = 0;

	/**
	 * @phpstan-param (\Closure(string) : list<string>)|null $completer
	 */
	public function __construct(
		private MainLogger $logger,
		private ?\Closure $completer = null
	){
		$this->redraw();
	}

	public function close() : void{
		$this->logger->setConsolePrompt(null);
	}

	public function redraw(?string $message = null) : void{
		$avail = max(1, $this->width - mb_strwidth(self::PROMPT) - 1);
		if($this->cursor < $this->offset){
			$this->offset = $this->cursor;
		}
		while($this->offset < $this->cursor && $this->columns($this->offset, $this->cursor) > $avail){
			$this->offset++;
		}
		$end = $this->offset;
		$used = 0;
		while($end < count($this->chars) && $used + mb_strwidth($this->chars[$end]) <= $avail){
			$used += mb_strwidth($this->chars[$end]);
			$end++;
		}
		$visible = implode("", array_slice($this->chars, $this->offset, $end - $this->offset));
		$column = mb_strwidth(self::PROMPT) + $this->columns($this->offset, $this->cursor);
		$menu = $this->menu !== [] ? "\r\n" . $this->renderMenu() . "\x1b[1A" : "";
		$this->logger->setConsolePrompt("\x1b[?25l\r" . self::PROMPT . $visible . "\x1b[J" . $menu . "\r\x1b[" . $column . "C\x1b[?25h", $message);
	}

	/**
	 * Handles one line from the key reader. Returns a command when Enter was pressed on a non-empty line.
	 */
	public function handle(string $event) : ?string{
		$parts = explode(" ", $event);
		if($parts[0] === "w" && count($parts) === 2){
			$this->width = max(10, (int) $parts[1]);
			$this->redraw();
			return null;
		}
		if($parts[0] !== "k" || count($parts) !== 4){
			return null;
		}
		$key = (int) $parts[1];
		$char = (int) $parts[2];
		$modifiers = (int) $parts[3];

		if($this->menu !== []){
			switch($key){
				case self::KEY_TAB:
					$this->moveMenu(($modifiers & self::MOD_SHIFT) !== 0 ? -1 : 1);
					return null;
				case self::KEY_RIGHT:
				case self::KEY_DOWN:
					$this->moveMenu(1);
					return null;
				case self::KEY_LEFT:
				case self::KEY_UP:
					$this->moveMenu(-1);
					return null;
				case self::KEY_ENTER:
					if($this->menuIndex >= 0){
						$choice = $this->menu[$this->menuIndex];
						$this->menu = [];
						$this->replaceToken($this->menuTokenLength, $choice . " ");
						$this->redraw();
						return null;
					}
					$this->menu = [];
					break;
				case self::KEY_ESCAPE:
					$this->menu = [];
					$this->redraw();
					return null;
				default:
					$this->menu = [];
					break;
			}
		}

		switch($key){
			case self::KEY_ENTER:
				return $this->submit();
			case self::KEY_TAB:
				$this->complete();
				return null;
			case self::KEY_BACKSPACE:
				if($this->cursor > 0){
					array_splice($this->chars, --$this->cursor, 1);
				}
				break;
			case self::KEY_DELETE:
				if($this->cursor < count($this->chars)){
					array_splice($this->chars, $this->cursor, 1);
				}
				break;
			case self::KEY_LEFT:
				$this->cursor = max(0, $this->cursor - 1);
				break;
			case self::KEY_RIGHT:
				$this->cursor = min(count($this->chars), $this->cursor + 1);
				break;
			case self::KEY_HOME:
				$this->cursor = 0;
				break;
			case self::KEY_END:
				$this->cursor = count($this->chars);
				break;
			case self::KEY_UP:
				if($this->historyIndex > 0){
					$this->setLine($this->history[--$this->historyIndex]);
				}
				break;
			case self::KEY_DOWN:
				if($this->historyIndex < count($this->history)){
					$this->historyIndex++;
					$this->setLine($this->history[$this->historyIndex] ?? "");
				}
				break;
			case self::KEY_ESCAPE:
				$this->setLine("");
				break;
			default:
				if($char < 32 || $char === 127 || ($modifiers & (self::MOD_CONTROL | self::MOD_ALT)) === self::MOD_CONTROL){
					$this->redraw();
					return null;
				}
				if($char >= 0xd800 && $char <= 0xdbff){
					$this->highSurrogate = $char;
					return null;
				}
				if($char >= 0xdc00 && $char <= 0xdfff){
					if($this->highSurrogate === 0){
						return null;
					}
					$char = 0x10000 + (($this->highSurrogate - 0xd800) << 10) + ($char - 0xdc00);
				}
				$this->highSurrogate = 0;
				$this->insert(mb_chr($char, "UTF-8"));
				break;
		}
		$this->redraw();
		return null;
	}

	private function submit() : ?string{
		$line = trim(implode("", $this->chars));
		$this->chars = [];
		$this->cursor = 0;
		$this->offset = 0;
		if($line === ""){
			$this->redraw();
			return null;
		}
		if(end($this->history) !== $line){
			$this->history[] = $line;
			if(count($this->history) > self::HISTORY_SIZE){
				$this->history = array_slice($this->history, -self::HISTORY_SIZE);
			}
		}
		$this->historyIndex = count($this->history);
		$this->redraw(self::PROMPT . $line);
		return $line;
	}

	private function complete() : void{
		if($this->completer === null){
			return;
		}
		$before = implode("", array_slice($this->chars, 0, $this->cursor));
		$space = mb_strrpos($before, " ");
		$token = $space === false ? $before : mb_substr($before, $space + 1);
		$lower = mb_strtolower($token);

		$matches = [];
		foreach(($this->completer)($before) as $candidate){
			if(str_starts_with(mb_strtolower($candidate), $lower)){
				$matches[] = $candidate;
			}
		}
		$matches = array_values(array_unique($matches));
		if(count($matches) === 0){
			$this->redraw();
			return;
		}
		if(count($matches) === 1){
			$this->replaceToken(mb_strlen($token), $matches[0] . " ");
			$this->redraw();
			return;
		}

		sort($matches, SORT_STRING | SORT_FLAG_CASE);
		$common = $matches[0];
		foreach($matches as $match){
			$length = 0;
			$max = min(mb_strlen($common), mb_strlen($match));
			while($length < $max && mb_strtolower(mb_substr($common, $length, 1)) === mb_strtolower(mb_substr($match, $length, 1))){
				$length++;
			}
			$common = mb_substr($common, 0, $length);
		}
		if(mb_strlen($common) > mb_strlen($token)){
			$this->replaceToken(mb_strlen($token), $common);
			$token = $common;
		}
		$this->menu = $matches;
		$this->menuIndex = -1;
		$this->menuTokenLength = mb_strlen($token);
		$this->redraw();
	}

	private function moveMenu(int $step) : void{
		$count = count($this->menu);
		$this->menuIndex = $this->menuIndex < 0 ? ($step > 0 ? 0 : $count - 1) : ($this->menuIndex + $step + $count) % $count;
		$this->redraw();
	}

	private function renderMenu() : string{
		$avail = max(1, $this->width - 1);
		$cells = [];
		foreach($this->menu as $candidate){
			$cells[] = " " . $candidate . " ";
		}
		$selected = max(0, $this->menuIndex);
		$start = 0;
		while($start < $selected && mb_strwidth(implode("", array_slice($cells, $start, $selected - $start + 1))) > $avail){
			$start++;
		}
		$out = "";
		$used = 0;
		for($i = $start; $i < count($cells); $i++){
			$width = mb_strwidth($cells[$i]);
			if($used + $width > $avail){
				break;
			}
			$used += $width;
			$out .= $i === $this->menuIndex ? "\x1b[7m" . $cells[$i] . "\x1b[27m" : "\x1b[90m" . $cells[$i] . "\x1b[39m";
		}
		return $out;
	}

	private function replaceToken(int $length, string $replacement) : void{
		array_splice($this->chars, $this->cursor - $length, $length);
		$this->cursor -= $length;
		$this->insert($replacement);
	}

	private function insert(string $text) : void{
		$chars = mb_str_split($text, 1, "UTF-8");
		array_splice($this->chars, $this->cursor, 0, $chars);
		$this->cursor += count($chars);
	}

	private function setLine(string $line) : void{
		$this->chars = mb_str_split($line, 1, "UTF-8");
		$this->cursor = count($this->chars);
	}

	private function columns(int $from, int $to) : int{
		return mb_strwidth(implode("", array_slice($this->chars, $from, $to - $from)));
	}
}
