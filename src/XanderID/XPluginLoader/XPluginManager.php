<?php

declare(strict_types=1);

namespace XanderID\XPluginLoader;

use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\PermissionManager;
use pocketmine\permission\PermissionParser;
use pocketmine\plugin\DiskResourceProvider;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginDescription;
use pocketmine\plugin\PluginDescriptionParseException;
use pocketmine\plugin\PluginGraylist;
use pocketmine\plugin\PluginLoadabilityChecker;
use pocketmine\plugin\PluginLoader;
use pocketmine\plugin\PluginLoadTriage;
use pocketmine\plugin\PluginLoadTriageEntry;
use pocketmine\plugin\PluginManager;
use pocketmine\Server;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Utils;
use ReflectionClass;
use Symfony\Component\Filesystem\Path;
use function array_diff_key;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function class_exists;
use function count;
use function dirname;
use function file_exists;
use function implode;
use function is_a;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function iterator_to_array;
use function mkdir;
use function realpath;
use function shuffle;
use function str_contains;

/**
 * XPluginManager extends the original PluginManager functionality to support
 * loading plugins from multiple category paths.
 */
class XPluginManager {
	private PluginManager $pluginManager;
	private ReflectionClass $reflection;

	/**
	 * @var list<PluginLoader>
	 *
	 * @phpstan-var array<class-string<PluginLoader>, PluginLoader>
	 */
	protected array $fileAssociations = [];

	/**
	 * Constructor.
	 *
	 * @throws \RuntimeException if the plugin data path exists but is not a directory
	 */
	public function __construct(
		private Server $server,
		private ?string $pluginDataDirectory,
		private ?PluginGraylist $graylist = null
	) {
		if ($this->pluginDataDirectory !== null) {
			if (!file_exists($this->pluginDataDirectory)) {
				mkdir($this->pluginDataDirectory, 0o777, true);
			} elseif (!is_dir($this->pluginDataDirectory)) {
				throw new \RuntimeException("Plugin data path {$this->pluginDataDirectory} exists and is not a directory");
			}
		}

		$pluginManager = $server->getPluginManager();
		$this->pluginManager = $pluginManager;
		$this->reflection = new ReflectionClass($pluginManager);
	}

	/**
	 * Magic getter to access properties of the original PluginManager.
	 */
	public function __get(string $name) : mixed {
		$prop = $this->reflection->getProperty($name);
		$prop->setAccessible(true);
		return $prop->getValue($this->pluginManager);
	}

	/**
	 * Magic setter to modify properties of the original PluginManager.
	 */
	public function __set(string $name, mixed $value) : void {
		$prop = $this->reflection->getProperty($name);
		$prop->setAccessible(true);
		$prop->setValue($this->pluginManager, $value);
	}

	/**
	 * Retrieves a plugin by name.
	 */
	public function getPlugin(string $name) : ?Plugin {
		$plugins = $this->__get('plugins');
		if (isset($plugins[$name])) {
			return $plugins[$name];
		}

		return null;
	}

	/**
	 * Registers a plugin loader interface.
	 */
	public function registerInterface(PluginLoader $loader) : void {
		$this->fileAssociations[$loader::class] = $loader;
	}

	/**
	 * Returns the data directory for a plugin.
	 */
	private function getDataDirectory(string $pluginPath, string $pluginName) : string {
		if ($this->pluginDataDirectory !== null) {
			return Path::join($this->pluginDataDirectory, $pluginName);
		}

		return Path::join(dirname($pluginPath), $pluginName);
	}

	/**
	 * Internal method to load a plugin.
	 */
	private function internalLoadPlugin(string $path, PluginLoader $loader, PluginDescription $description) : ?Plugin {
		$language = $this->server->getLanguage();
		$this->server->getLogger()->info(
			$this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_load($description->getFullName()))
		);

		$dataFolder = $this->getDataDirectory($path, $description->getName());
		if (file_exists($dataFolder) && !is_dir($dataFolder)) {
			$this->server->getLogger()->critical(
				$language->translate(KnownTranslationFactory::pocketmine_plugin_loadError(
					$description->getName(),
					KnownTranslationFactory::pocketmine_plugin_badDataFolder($dataFolder)
				))
			);
			return null;
		}

		if (!file_exists($dataFolder)) {
			mkdir($dataFolder, 0o777, true);
		}

		$prefixed = $loader->getAccessProtocol() . $path;
		$loader->loadPlugin($prefixed);

		$mainClass = $description->getMain();
		if (!class_exists($mainClass, true)) {
			$this->server->getLogger()->critical(
				$language->translate(KnownTranslationFactory::pocketmine_plugin_loadError(
					$description->getName(),
					KnownTranslationFactory::pocketmine_plugin_mainClassNotFound()
				))
			);
			return null;
		}

		if (!is_a($mainClass, Plugin::class, true)) {
			$this->server->getLogger()->critical(
				$language->translate(KnownTranslationFactory::pocketmine_plugin_loadError(
					$description->getName(),
					KnownTranslationFactory::pocketmine_plugin_mainClassWrongType(Plugin::class)
				))
			);
			return null;
		}

		$reflect = new \ReflectionClass($mainClass);
		if (!$reflect->isInstantiable()) {
			$this->server->getLogger()->critical(
				$language->translate(KnownTranslationFactory::pocketmine_plugin_loadError(
					$description->getName(),
					KnownTranslationFactory::pocketmine_plugin_mainClassAbstract()
				))
			);
			return null;
		}

		$permManager = PermissionManager::getInstance();
		foreach ($description->getPermissions() as $permsGroup) {
			foreach ($permsGroup as $perm) {
				if ($permManager->getPermission($perm->getName()) !== null) {
					$this->server->getLogger()->critical(
						$language->translate(KnownTranslationFactory::pocketmine_plugin_loadError(
							$description->getName(),
							KnownTranslationFactory::pocketmine_plugin_duplicatePermissionError($perm->getName())
						))
					);
					return null;
				}
			}
		}

		$opRoot = $permManager->getPermission(DefaultPermissions::ROOT_OPERATOR);
		$everyoneRoot = $permManager->getPermission(DefaultPermissions::ROOT_USER);
		foreach (Utils::stringifyKeys($description->getPermissions()) as $default => $perms) {
			foreach ($perms as $perm) {
				$permManager->addPermission($perm);
				switch ($default) {
					case PermissionParser::DEFAULT_TRUE:
						$everyoneRoot->addChild($perm->getName(), true);
						break;
					case PermissionParser::DEFAULT_OP:
						$opRoot->addChild($perm->getName(), true);
						break;
					case PermissionParser::DEFAULT_NOT_OP:
						$everyoneRoot->addChild($perm->getName(), true);
						$opRoot->addChild($perm->getName(), false);
						break;
					default:
						break;
				}
			}
		}

		/** @var Plugin $plugin */
		$plugin = new $mainClass($loader, $this->server, $description, $dataFolder, $prefixed, new DiskResourceProvider($prefixed . '/resources/'));
		$plugins = $this->__get('plugins');
		$plugins[$plugin->getDescription()->getName()] = $plugin;
		$this->__set('plugins', $plugins);

		return $plugin;
	}

	/**
	 * Triage plugins from multiple category paths.
	 *
	 * @param list<string>      $categoryPaths array of category folder paths
	 * @param list<string>|null $newLoaders    optional array of new loader keys
	 */
	private function triagePlugins(array $categoryPaths, PluginLoadTriage $triage, int &$loadErrorCount, ?array $newLoaders = null) : void {
		if (is_array($newLoaders)) {
			$loaders = [];
			foreach ($newLoaders as $key) {
				if (isset($this->fileAssociations[$key])) {
					$loaders[$key] = $this->fileAssociations[$key];
				}
			}
		} else {
			$loaders = $this->fileAssociations;
		}

		foreach ($categoryPaths as $path) {
			if (is_dir($path)) {
				$files = iterator_to_array(new \FilesystemIterator($path, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS));
				shuffle($files); // Prevent reliance on filesystem order.
			} elseif (is_file($path)) {
				$realPath = Utils::assumeNotFalse(realpath($path), 'realpath() should not return false on an accessible, existing file');
				$files = [$realPath];
			} else {
				continue;
			}

			foreach ($loaders as $loader) {
				foreach ($files as $file) {
					if (!is_string($file)) {
						throw new AssumptionFailedError('FilesystemIterator current should be string when using CURRENT_AS_PATHNAME');
					}

					if (!$loader->canLoadPlugin($file)) {
						continue;
					}

					try {
						$description = $loader->getPluginDescription($file);
					} catch (PluginDescriptionParseException $e) {
						$this->server->getLogger()->critical(
							$this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_loadError(
								$file,
								KnownTranslationFactory::pocketmine_plugin_invalidManifest($e->getMessage())
							))
						);
						++$loadErrorCount;
						continue;
					} catch (\RuntimeException $e) {
						$this->server->getLogger()->critical(
							$this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_loadError($file, $e->getMessage()))
						);
						$this->server->getLogger()->logException($e);
						++$loadErrorCount;
						continue;
					}

					if ($description === null) {
						continue;
					}

					$name = $description->getName();

					if ($this->graylist !== null && !$this->graylist->isAllowed($name)) {
						$this->server->getLogger()->notice(
							$this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_loadError(
								$name,
								$this->graylist->isWhitelist() ? KnownTranslationFactory::pocketmine_plugin_disallowedByWhitelist() : KnownTranslationFactory::pocketmine_plugin_disallowedByBlacklist()
							))
						);
						continue;
					}

					$loadabilityChecker = new PluginLoadabilityChecker($this->server->getApiVersion());
					if (($loadabilityError = $loadabilityChecker->check($description)) !== null) {
						$this->server->getLogger()->critical(
							$this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_loadError($name, $loadabilityError))
						);
						++$loadErrorCount;
						continue;
					}

					if (isset($triage->plugins[$name]) || $this->getPlugin($name) instanceof Plugin) {
						$this->server->getLogger()->critical(
							$this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_duplicateError($name))
						);
						++$loadErrorCount;
						continue;
					}

					if (str_contains($name, ' ')) {
						$this->server->getLogger()->warning(
							$this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_spacesDiscouraged($name))
						);
					}

					$triage->plugins[$name] = new PluginLoadTriageEntry($file, $loader, $description);
					$triage->softDependencies[$name] = array_merge($triage->softDependencies[$name] ?? [], $description->getSoftDepend());
					$triage->dependencies[$name] = $description->getDepend();

					foreach ($description->getLoadBefore() as $before) {
						if (isset($triage->softDependencies[$before])) {
							$triage->softDependencies[$before][] = $name;
						} else {
							$triage->softDependencies[$before] = [$name];
						}
					}
				}
			}
		}
	}

	/**
	 * Checks and resolves plugin dependencies during triage.
	 */
	private function checkDepsForTriage(string $pluginName, string $dependencyType, array &$dependencyLists, array $loadedPlugins, PluginLoadTriage $triage) : void {
		if (isset($dependencyLists[$pluginName])) {
			foreach (Utils::promoteKeys($dependencyLists[$pluginName]) as $key => $dependency) {
				if (isset($loadedPlugins[$dependency]) || $this->getPlugin($dependency) instanceof Plugin) {
					$this->server->getLogger()->debug("Successfully resolved {$dependencyType} dependency \"{$dependency}\" for plugin \"{$pluginName}\"");
					unset($dependencyLists[$pluginName][$key]);
				} elseif (array_key_exists($dependency, $triage->plugins)) {
					$this->server->getLogger()->debug("Deferring resolution of {$dependencyType} dependency \"{$dependency}\" for plugin \"{$pluginName}\" (found but not loaded yet)");
				}
			}

			if (count($dependencyLists[$pluginName]) === 0) {
				unset($dependencyLists[$pluginName]);
			}
		}
	}

	/**
	 * Loads plugins from multiple category paths.
	 *
	 * @param list<string> $categoryPaths  array of category folder paths
	 * @param int          $loadErrorCount reference to the load error count
	 *
	 * @return list<Plugin> array of loaded plugins
	 */
	public function loadPlugins(array $categoryPaths, int &$loadErrorCount = 0) : array {
		if ($this->loadPluginsGuard) {
			throw new \LogicException(__METHOD__ . '() cannot be called from within itself');
		}

		$this->loadPluginsGuard = true;
		$triage = new PluginLoadTriage();
		$this->triagePlugins($categoryPaths, $triage, $loadErrorCount);
		$loadedPlugins = [];

		while (count($triage->plugins) > 0) {
			$loadedThisLoop = 0;
			foreach (Utils::stringifyKeys($triage->plugins) as $name => $entry) {
				$this->checkDepsForTriage($name, 'hard', $triage->dependencies, $loadedPlugins, $triage);
				$this->checkDepsForTriage($name, 'soft', $triage->softDependencies, $loadedPlugins, $triage);

				if (!isset($triage->dependencies[$name]) && !isset($triage->softDependencies[$name])) {
					unset($triage->plugins[$name]);
					++$loadedThisLoop;

					$oldRegisteredLoaders = $this->fileAssociations;
					if (($plugin = $this->internalLoadPlugin($entry->getFile(), $entry->getLoader(), $entry->getDescription())) instanceof Plugin) {
						$loadedPlugins[$name] = $plugin;
						$diffLoaders = [];
						foreach ($this->fileAssociations as $k => $loader) {
							if (!array_key_exists($k, $oldRegisteredLoaders)) {
								$diffLoaders[] = $k;
							}
						}

						if (count($diffLoaders) !== 0) {
							$this->server->getLogger()->debug("Plugin {$name} registered a new plugin loader during load, scanning for new plugins");
							$plugins = $triage->plugins;
							$this->triagePlugins($categoryPaths, $triage, $loadErrorCount, $diffLoaders);
							$diffPlugins = array_diff_key($triage->plugins, $plugins);
							$this->server->getLogger()->debug('Re-triage found plugins: ' . implode(', ', array_keys($diffPlugins)));
						}
					} else {
						++$loadErrorCount;
					}
				}
			}

			if ($loadedThisLoop === 0) {
				// No plugins loaded; resolve soft dependencies and unknown dependencies.
				foreach (Utils::stringifyKeys($triage->plugins) as $name => $file) {
					if (isset($triage->softDependencies[$name]) && !isset($triage->dependencies[$name])) {
						foreach (Utils::promoteKeys($triage->softDependencies[$name]) as $k => $dependency) {
							if ($this->getPlugin($dependency) === null && !array_key_exists($dependency, $triage->plugins)) {
								$this->server->getLogger()->debug("Skipping resolution of missing soft dependency \"{$dependency}\" for plugin \"{$name}\"");
								unset($triage->softDependencies[$name][$k]);
							}
						}

						if (count($triage->softDependencies[$name]) === 0) {
							unset($triage->softDependencies[$name]);
							continue 2;
						}
					}
				}

				foreach (Utils::stringifyKeys($triage->plugins) as $name => $file) {
					if (isset($triage->dependencies[$name])) {
						$unknownDependencies = [];
						foreach ($triage->dependencies[$name] as $dependency) {
							if ($this->getPlugin($dependency) === null && !array_key_exists($dependency, $triage->plugins)) {
								$unknownDependencies[$dependency] = $dependency;
							}
						}

						if (count($unknownDependencies) > 0) {
							$this->server->getLogger()->critical(
								$this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_loadError(
									$name,
									KnownTranslationFactory::pocketmine_plugin_unknownDependency(implode(', ', $unknownDependencies))
								))
							);
							unset($triage->plugins[$name]);
							++$loadErrorCount;
						}
					}
				}

				foreach (Utils::stringifyKeys($triage->plugins) as $name => $file) {
					$this->server->getLogger()->critical(
						$this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_plugin_loadError($name, KnownTranslationFactory::pocketmine_plugin_circularDependency()))
					);
					++$loadErrorCount;
				}

				break;
			}
		}

		$this->loadPluginsGuard = false;
		return $loadedPlugins;
	}

	/**
	 * Checks if a plugin is enabled.
	 */
	public function isPluginEnabled(Plugin $plugin) : bool {
		return isset($this->__get('plugins')[$plugin->getDescription()->getName()]) && $plugin->isEnabled();
	}
}
