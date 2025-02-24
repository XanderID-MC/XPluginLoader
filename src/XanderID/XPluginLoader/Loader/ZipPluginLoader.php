<?php

declare(strict_types=1);

namespace XanderID\XPluginLoader\Loader;

use pocketmine\plugin\PluginDescription;
use pocketmine\Server;
use Symfony\Component\Filesystem\Path;
use function base64_encode;
use function fclose;
use function file_exists;
use function str_ends_with;
use function str_starts_with;
use function stream_get_contents;

class ZipPluginLoader extends Loader {
	public function canLoadPlugin(string $path) : bool {
		return file_exists($path) && Path::getExtension($path) === 'zip';
	}

	public function loadPlugin(string $path) : void {
		$path = Path::normalize($path);
		$pluginDescription = $this->getPluginDescription($path);
		if ($pluginDescription !== null) {
			$zip = new \ZipArchive();
			if ($zip->open($path) === true) {
				for ($i = 0; $i < $zip->numFiles; ++$i) {
					$entry = $zip->getNameIndex($i);
					if (str_starts_with($entry, 'src/') && str_ends_with($entry, '.php')) {
						$stream = $zip->getStream($entry);
						if ($stream !== false) {
							$contents = stream_get_contents($stream);
							fclose($stream);
							include_once 'data://text/plain;base64,' . base64_encode($contents);
						}
					}
				}

				$zip->close();
			}
		}
	}

	public function getPluginDescription(string $file) : ?PluginDescription {
		$zip = new \ZipArchive();
		if ($zip->open($file) === true) {
			$content = $zip->getFromName('plugin.yml');
			$zip->close();
			if ($content !== false) {
				$pluginDescription = new PluginDescription($content);
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
