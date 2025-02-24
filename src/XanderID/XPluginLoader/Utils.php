<?php

declare(strict_types=1);

namespace XanderID\XPluginLoader;

use pocketmine\Server;
use Symfony\Component\Filesystem\Path;
use function preg_match;
use function str_contains;

class Utils {
	/**
	 * Validates a folder name ensuring that:
	 * - It does not contain any directory separators (i.e. no subfolders)
	 * - It only contains allowed characters: letters, digits, underscores, and hyphens.
	 *
	 * @param string $folder the folder name to validate
	 *
	 * @return bool returns true if the folder name is valid, false otherwise
	 */
	public static function validateFolder(string $folder) : bool {
		if (str_contains($folder, '/') || str_contains($folder, '\\')) {
			return false;
		}

		// Returns true if the folder name matches the allowed pattern, false otherwise.
		return preg_match('/^[A-Za-z0-9_-]+$/', $folder) === 1;
	}

	/**
	 * Generates the full path for a given category by joining the plugin path and the category name.
	 *
	 * @param string $category the category name
	 *
	 * @return string the full path to the category folder
	 */
	public static function getCategoryPath(string $category) : string {
		return Path::join(Server::getInstance()->getPluginPath(), $category);
	}
}
