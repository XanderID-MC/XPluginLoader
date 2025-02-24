<?php

declare(strict_types=1);

namespace XanderID\XPluginLoader\Loader;

use pocketmine\plugin\PluginDescription;
use pocketmine\Server;
use Symfony\Component\Filesystem\Path;
use function file_exists;
use function file_get_contents;
use function is_dir;

class FolderPluginLoader extends Loader {
	public function canLoadPlugin(string $path) : bool {
		return is_dir($path) && file_exists(Path::join($path, 'plugin.yml')) && file_exists(Path::join($path, 'src'));
	}

	public function loadPlugin(string $path) : void {
		$pluginDescription = $this->getPluginDescription($path);
		if ($pluginDescription !== null) {
			$this->loader->addPath($pluginDescription->getSrcNamespacePrefix(), Path::join($path, 'src'));
		}
	}

	public function getPluginDescription(string $path) : ?PluginDescription {
		$ymlPath = Path::join($path, 'plugin.yml');
		if (is_dir($path) && file_exists($ymlPath)) {
			$yaml = file_get_contents($ymlPath);
			if ($yaml !== '') {
				$pluginDescription = new PluginDescription($yaml);
				if (Server::getInstance()->getPluginManager()->getPlugin($pluginDescription->getName()) === null) {
					return $pluginDescription;
				}
			}
		}

		return null;
	}

	public function getAccessProtocol() : string {
		return '';
	}
}
