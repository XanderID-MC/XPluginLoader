<?php

declare(strict_types=1);

namespace XanderID\XPluginLoader;

use pocketmine\lang\KnownTranslationFactory;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginEnableOrder;
use pocketmine\plugin\PluginGraylist;
use pocketmine\utils\Filesystem as FilesystemPM;
use pocketmine\utils\Process;
use pocketmine\utils\SingletonTrait;
use pocketmine\YmlServerProperties;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use XanderID\XPluginLoader\Loader\FolderPluginLoader;
use XanderID\XPluginLoader\Loader\PharPluginLoader;
use XanderID\XPluginLoader\Loader\ZipPluginLoader;
use function copy;
use function count;
use function file_exists;
use function gettype;
use function implode;
use function ini_get;
use function is_array;
use function yaml_parse;

/**
 * Class XPluginLoader.
 *
 * Main plugin class responsible for initializing the plugin, setting up plugin loaders,
 * categories, and managing plugin enable/disable lifecycle.
 */
class XPluginLoader extends PluginBase {
	use SingletonTrait;

	/** @var list<class-string> */
	private array $loader = [PharPluginLoader::class];

	/** @var array<string, string> */
	private array $categoriesPath = [];

	private XPluginManager $pluginManager;

	protected function onEnable() : void {
		self::setInstance($this);
		$this->saveDefaultConfig();
		$config = $this->getConfig();

		if ($config->get('loader.folder', true)) {
			$this->loader[] = FolderPluginLoader::class;
		}

		if ($config->get('loader.zip', true)) {
			if (ini_get('allow_url_include') === 0) {
				$this->getLogger()->warning('To Use Zip Plugin Loader please Set allow_url_include to 1 in php.ini');
			} else {
				$this->loader[] = ZipPluginLoader::class;
			}
		}

		$this->initCategories($config->get('categories', []));
		$this->initPluginManager();
		$this->loadPlugins();
	}

	/**
	 * Loads all plugins from the categories path and enables them in order.
	 *
	 * @see https://github.com/pmmp/PocketMine-MP/blob/stable/src/Server.php#L1056
	 */
	private function loadPlugins() : void {
		$server = $this->getServer();
		$serverLogger = $server->getLogger();
		$serverLang = $server->getLanguage();

		$loadErrorCount = 0;
		$this->pluginManager->loadPlugins($this->categoriesPath, $loadErrorCount);
		if ($loadErrorCount > 0) {
			$serverLogger->emergency($serverLang->translate(KnownTranslationFactory::pocketmine_plugin_someLoadErrors()));
			$this->forceShutdownExit();
			return;
		}

		if (!$server->enablePlugins(PluginEnableOrder::STARTUP)) {
			$serverLogger->emergency($serverLang->translate(KnownTranslationFactory::pocketmine_plugin_someEnableErrors()));
			$this->forceShutdownExit();
			return;
		}

		if (!$server->enablePlugins(PluginEnableOrder::POSTWORLD)) {
			$serverLogger->emergency($serverLang->translate(KnownTranslationFactory::pocketmine_plugin_someEnableErrors()));
			$this->forceShutdownExit();
			return;
		}
	}

	/**
	 * Forces the server to shut down and exit.
	 *
	 * @see Server::forceShutdownExit()
	 */
	private function forceShutdownExit() : void {
		$this->getServer()->forceShutdown();
		Process::kill(Process::pid());
	}

	/**
	 * Initializes the PluginManager.
	 *
	 * Loads plugin graylist from a YAML file and sets up the plugin data directory,
	 * then registers all plugin loaders.
	 *
	 * @see https://github.com/pmmp/PocketMine-MP/blob/stable/src/Server.php#L1029
	 */
	private function initPluginManager() : void {
		$server = $this->getServer();
		$pluginData = $server->getConfigGroup()->getPropertyBool(YmlServerProperties::PLUGINS_LEGACY_DATA_DIR, true)
			? null
			: Path::join($server->getDataPath(), 'plugin_data');

		$graylistFile = Path::join($server->getDataPath(), 'plugin_list.yml');
		if (!file_exists($graylistFile)) {
			copy(Path::join(\pocketmine\RESOURCE_PATH, 'plugin_list.yml'), $graylistFile);
		}

		try {
			/** @phpstan-ignore-next-line */
			$array = yaml_parse(FilesystemPM::fileGetContents($graylistFile));
			if (!is_array($array)) {
				throw new \InvalidArgumentException('Expected an array as root, got ' . gettype($array));
			}

			$pluginGraylist = PluginGraylist::fromArray($array);
		} catch (\InvalidArgumentException $e) {
			$server->getLogger()->emergency("Failed to load {$graylistFile}: " . $e->getMessage());
			$this->forceShutdownExit();
			return;
		}

		$this->pluginManager = new XPluginManager($server, $pluginData, $pluginGraylist);
		foreach ($this->loader as $loaderClass) {
			$this->pluginManager->registerInterface(new $loaderClass($this->getServer()->getLoader()));
		}
	}

	/**
	 * Initializes plugin categories.
	 *
	 * Checks each provided category name, creates the folder if it doesn't exist,
	 * and maps category names to their normalized folder paths.
	 */
	private function initCategories(array $categoryegories) : void {
		$fs = new Filesystem();
		$pluginPath = $this->getServer()->getPluginPath();
		$loaded = [];
		$failed = [];
		$paths = [];

		foreach ($categoryegories as $category) {
			if (!Utils::validateFolder($category)) {
				$failed[] = $category;
				continue;
			}

			$categoryPath = Utils::getCategoryPath($category);
			if (!$fs->exists($categoryPath)) {
				$fs->mkdir($categoryPath);
			}

			if ($fs->exists(Path::join($categoryPath, 'plugin.yml')) || $fs->exists(Path::join($categoryPath, 'src'))) {
				$failed[] = $category;
				continue;
			}

			$loaded[] = $category;
			$paths[$category] = $categoryPath;
		}

		$this->categoriesPath = $paths;
		if (count($failed) > 0) {
			$this->getLogger()->warning('Failed to load categories: ' . implode(', ', $failed) . '.');
		}

		$this->getLogger()->notice('Successfully loaded ' . count($loaded) . ' categories.');
	}
}
