<?php

declare(strict_types=1);

namespace XanderID\XPluginLoader\Loader;

use pocketmine\plugin\PluginDescription;
use pocketmine\Server;
use Symfony\Component\Filesystem\Path;
use function file_exists;

class PharPluginLoader extends Loader {
	public function canLoadPlugin(string $path) : bool {
		return file_exists($path) && Path::getExtension($path) === 'phar';
	}

	public function loadPlugin(string $path) : void {
		$pluginDescription = $this->getPluginDescription($path);
		if ($pluginDescription !== null) {
			$this->loader->addPath($pluginDescription->getSrcNamespacePrefix(), Path::join($path, 'src'));
		}
	}

	public function getPluginDescription(string $file) : ?PluginDescription {
		$phar = new \Phar($file);
		if (isset($phar['plugin.yml'])) {
			$pluginDescription = new PluginDescription($phar['plugin.yml']->getContent());
			if (Server::getInstance()->getPluginManager()->getPlugin($pluginDescription->getName()) === null) {
				return $pluginDescription;
			}
		}

		return null;
	}

	public function getAccessProtocol() : string {
		return 'phar://';
	}
}
