<?php

declare(strict_types=1);

namespace XanderID\XPluginLoader\Loader;

use pocketmine\plugin\PluginDescription;
use pocketmine\plugin\PluginLoader;
use pocketmine\thread\ThreadSafeClassLoader;

abstract class Loader implements PluginLoader {
	public function __construct(
		protected readonly ThreadSafeClassLoader $loader,
	) {}

	public function canLoadPlugin(string $path) : bool {
		return false;
	}

	public function loadPlugin(string $file) : void {}

	public function getPluginDescription(string $file) : ?PluginDescription {
		return null;
	}
}
