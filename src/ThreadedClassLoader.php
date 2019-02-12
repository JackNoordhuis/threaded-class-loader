<?php

/**
 * ThreadedClassLoader.php - threaded-class-loader
 *
 * Copyright (C) 2018 Jack Noordhuis
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Jack
 *
 */

namespace jacknoordhuis\Autoload;

use Composer\Autoload\ClassLoader;

/**
 * ThreadedClassLoader implements a PSR-0, PSR-4 and classmap class loader.
 *
 *     $loader = new \jacknoordhuis\Autoload\ThreadedClassLoader();
 *
 *     // register classes with namespaces
 *     $loader->add('Symfony\Component', __DIR__.'/component');
 *     $loader->add('Symfony',           __DIR__.'/framework');
 *
 *     // activate the autoloader
 *     $loader->register();
 *
 *     // to enable searching the include path (eg. for PEAR packages)
 *     $loader->setUseIncludePath(true);
 *
 * In this example, if you try to use a class in the Symfony\Component
 * namespace or one of its children (Symfony\Component\Console for instance),
 * the autoloader will first look for the class under the component/
 * directory, and it will then fallback to the framework/ directory if not
 * found before giving up.
 *
 * This class is based on the Composer ClassLoader.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Jack Noordhuis <me@jacknoordhuis.net>
 * @see    http://www.php-fig.org/psr/psr-0/
 * @see    http://www.php-fig.org/psr/psr-4/
 */
class ThreadedClassLoader extends \Threaded {

	// PSR-4
	private $prefixLengthsPsr4;
	private $prefixDirsPsr4;
	private $fallbackDirsPsr4;

	// PSR-0
	/** @var \Threaded[] */
	private $prefixesPsr0;
	private $fallbackDirsPsr0;

	private $includeFiles;

	private $useIncludePath = false;
	private $classMap;
	private $classMapAuthoritative = false;
	private $missingClasses;
	private $apcuPrefix;

	/**
	 * Creates a new threaded class loader from a default composer class loader.
	 *
	 * @param ClassLoader $loader The composer class loader.
	 * @param array $includeFiles Array of files to be included by the loader.
	 * @param bool $register      If the new autoloader should be registered.
	 * @param bool $unregister    If the composer autoloader should be unregistered.
	 *
	 * @return ThreadedClassLoader
	 */
	public static function fromComposerLoader(ClassLoader $loader, array $includeFiles = [], bool $register = true, bool $unregister = true) {
		$threadedLoader = new static();

		$threadedLoader->mergeComposerLoader($loader);

		foreach($includeFiles as $identifier => $file) {
			$threadedLoader->addFile($identifier, $file, !$register);
		}

		if($register) {
			$threadedLoader->register();
		}

		if($unregister) {
			$loader->unregister();
		}

		return $threadedLoader;
	}

	public function __construct() {
		$this->prefixLengthsPsr4 = new \Threaded;
		$this->prefixDirsPsr4 = new \Threaded;
		$this->fallbackDirsPsr4 = new \Threaded;

		$this->prefixesPsr0 = new \Threaded;
		$this->fallbackDirsPsr0 = new \Threaded;

		$this->includeFiles = new \Threaded;

		$this->classMap = new \Threaded;
		$this->missingClasses = new \Threaded;
	}

	public function getPrefixes() : array {
		return (array) $this->prefixesPsr0;
	}

	public function getPrefixesPsr4() : array {
		return (array) $this->prefixDirsPsr4;
	}

	protected function getAndRemovePrefixes(string $first, string $prefix) : array {
		/** @var \Threaded $entry */
		$entry = $this->prefixesPsr0[$first][$prefix];

		$entries = [];
		while($entry->count() > 0){
			$entries[] = $entry->shift();
		}
		return $entries;
	}

	protected function getAndRemovePrefixDirsPsr4(string $prefix) : array {
		/** @var \Threaded $entry */
		$entry = $this->prefixDirsPsr4[$prefix];

		$entries = [];
		while($entry->count() > 0){
			$entries[] = $entry->shift();
		}
		return $entries;
	}

	public function getFallbackDirs() : array {
		return (array) $this->fallbackDirsPsr0;
	}

	public function getFallbackDirsPsr4() : array {
		return (array) $this->fallbackDirsPsr4;
	}
	protected function getAndRemoveFallbackDirs(){
		$entries = [];
		while($this->fallbackDirsPsr0->count() > 0){
			$entries[] = $this->fallbackDirsPsr0->shift();
		}
		return $entries;
	}

	protected function getAndRemoveFallbackDirsPsr4(){
		$entries = [];
		while($this->fallbackDirsPsr4->count() > 0){
			$entries[] = $this->fallbackDirsPsr4->shift();
		}
		return $entries;
	}

	public function getClassMap() : array {
		return (array) $this->classMap;
	}

	/**
	 * @param array $classMap Class to filename map
	 */
	public function addClassMap(array $classMap) : void {
		$this->classMap->merge($classMap);
	}

	/**
	 * Registers a set of PSR-0 directories for a given prefix, either
	 * appending or prepending to the ones previously set for this prefix.
	 *
	 * @param string       $prefix  The prefix
	 * @param array|string $paths   The PSR-0 root directories
	 * @param bool         $prepend Whether to prepend the directories
	 */
	public function add($prefix, $paths, $prepend = false) : void {
		if($prefix === null) {
			if($prepend) {
				$this->synchronized(function($paths){
					$fallbackDirs = array_merge($paths, $this->getAndRemoveFallbackDirs());
					foreach($fallbackDirs as $fallbackDir){
						$this->fallbackDirsPsr0[] = $fallbackDir;
					}
				}, (array) $paths);
			} else {
				$this->fallbackDirsPsr0->merge((array) $paths);
			}

			return;
		}

		$first = $prefix[0];
		if(!isset($this->prefixesPsr0[$first])) {
			$this->prefixesPsr0[$first] = new \Threaded;
		}
		if(!isset($this->prefixesPsr0[$first][$prefix])) {
			$this->prefixesPsr0[$first][$prefix] = new \Threaded;
			$this->prefixesPsr0[$first][$prefix]->merge((array) $paths);

			return;
		}
		if($prepend) {
			$this->synchronized(function($paths, $first, $prefix) {
				$prefixes = array_merge($paths, $this->getAndRemovePrefixes($first, $prefix));
				foreach($prefixes as $p) {
					$this->prefixesPsr0[$first][$prefix] = $p;
				}
			}, (array) $paths, $first, $prefix);
		} else {
			$this->prefixesPsr0[$first][$prefix]->merge((array) $paths);
		}
	}

	/**
	 * Registers a set of PSR-4 directories for a given namespace, either
	 * appending or prepending to the ones previously set for this namespace.
	 *
	 * @param string       $prefix  The prefix/namespace, with trailing '\\'
	 * @param array|string $paths   The PSR-4 base directories
	 * @param bool         $prepend Whether to prepend the directories
	 *
	 * @throws \InvalidArgumentException
	 */
	public function addPsr4($prefix, $paths, $prepend = false) : void {
		if($prefix === null) {
			// Register directories for the root namespace.
			if($prepend) {
				$this->synchronized(function($paths){
					$fallbackDirs = array_merge($paths, $this->getAndRemoveFallbackDirsPsr4());
					foreach($fallbackDirs as $fallbackDir){
						$this->fallbackDirsPsr4[] = $fallbackDir;
					}
				}, (array) $paths);
			} else {
				$this->fallbackDirsPsr4->merge((array) $paths);
			}
		} elseif(!isset($this->prefixDirsPsr4[$prefix])) {
			// Register directories for a new namespace.
			$length = strlen($prefix);
			if('\\' !== $prefix[$length - 1]) {
				throw new \InvalidArgumentException("A non-empty PSR-4 prefix must end with a namespace separator.");
			}
			$this->prefixLengthsPsr4[$prefix[0]] = new \Threaded;
			$this->prefixLengthsPsr4[$prefix[0]][$prefix] = $length;
			$this->prefixDirsPsr4[$prefix] = new \Threaded;
			$this->prefixDirsPsr4[$prefix]->merge((array) $paths);
		} elseif($prepend) {
			// Prepend directories for an already registered namespace.
			$this->synchronized(function($paths, $prefix){
				$prefixDirs = array_merge($paths, $this->getAndRemovePrefixDirsPsr4($prefix));
				foreach($prefixDirs as $prefixDir){
					$this->fallbackDirsPsr4[$prefix] = $prefixDir;
				}
			}, (array) $paths, $prefix);
		} else {
			// Append directories for an already registered namespace.
			$this->prefixDirsPsr4[$prefix]->merge((array) $paths);
		}
	}

	/**
	 * Registers a set of files to the class loader.
	 *
	 * @param string $identifier
	 * @param string $file
	 * @param bool $include
	 */
	public function addFile(string $identifier, string $file, bool $include = true) : void {
		if(!isset($this->includeFiles[$identifier])) {
			$this->includeFiles[$identifier] = $file;

			if($include) {
				require $file;
			}
		}
	}

	/**
	 * Registers a set of PSR-0 directories for a given prefix,
	 * replacing any others previously set for this prefix.
	 *
	 * @param string       $prefix The prefix
	 * @param array|string $paths  The PSR-0 base directories
	 */
	public function set($prefix, $paths) {
		if (!$prefix) {
			$this->synchronized(function($paths) {
				while($this->fallbackDirsPsr0->count() > 0) {
					$this->fallbackDirsPsr0->pop();
				}
				$this->fallbackDirsPsr0->merge($paths);
			}, (array) $paths);
		} else {
			$this->synchronized(function($paths, $prefix) {
				while($this->prefixesPsr0[$prefix[0]][$prefix]->count() > 0) {
					$this->prefixesPsr0[$prefix[0]][$prefix]->pop();
				}
				$this->prefixesPsr0[$prefix[0]][$prefix]->merge($paths);
			}, (array) $paths, $prefix);
		}
	}

	/**
	 * Registers a set of PSR-4 directories for a given namespace,
	 * replacing any others previously set for this namespace.
	 *
	 * @param string       $prefix The prefix/namespace, with trailing '\\'
	 * @param array|string $paths  The PSR-4 base directories
	 *
	 * @throws \InvalidArgumentException
	 */
	public function setPsr4($prefix, $paths) {
		if(!$prefix) {
			$this->synchronized(function($paths) {
				while($this->fallbackDirsPsr4->count() > 0) {
					$this->fallbackDirsPsr4->pop();
				}
				$this->fallbackDirsPsr4->merge($paths);
			}, (array) $paths);
		} else {
			$length = strlen($prefix);
			if ('\\' !== $prefix[$length - 1]) {
				throw new \InvalidArgumentException("A non-empty PSR-4 prefix must end with a namespace separator.");
			}
			$this->prefixLengthsPsr4[$prefix[0]][$prefix] = $length;
			$this->synchronized(function($paths, $prefix) {
				while($this->prefixDirsPsr4[$prefix]->count() > 0) {
					$this->prefixDirsPsr4[$prefix]->pop();
				}
				$this->prefixDirsPsr4[$prefix]->merge($paths);
			}, (array) $paths, $prefix);
		}
	}

	/**
	 * Turns on searching the include path for class files.
	 *
	 * @param bool $useIncludePath
	 */
	public function setUseIncludePath($useIncludePath) {
		$this->useIncludePath = $useIncludePath;
	}

	/**
	 * Can be used to check if the autoloader uses the include path to check
	 * for classes.
	 *
	 * @return bool
	 */
	public function getUseIncludePath() {
		return $this->useIncludePath;
	}

	/**
	 * Turns off searching the prefix and fallback directories for classes
	 * that have not been registered with the class map.
	 *
	 * @param bool $classMapAuthoritative
	 */
	public function setClassMapAuthoritative($classMapAuthoritative) {
		$this->classMapAuthoritative = $classMapAuthoritative;
	}

	/**
	 * Should class lookup fail if not found in the current class map?
	 *
	 * @return bool
	 */
	public function isClassMapAuthoritative() {
		return $this->classMapAuthoritative;
	}

	/**
	 * APCu prefix to use to cache found/not-found classes, if the extension is enabled.
	 *
	 * @param string|null $apcuPrefix
	 */
	public function setApcuPrefix($apcuPrefix) {
		$this->apcuPrefix = function_exists("apcu_fetch") && ini_get("apc.enabled") ? $apcuPrefix : null;
	}

	/**
	 * The APCu prefix in use, or null if APCu caching is not enabled.
	 *
	 * @return string|null
	 */
	public function getApcuPrefix() {
		return $this->apcuPrefix;
	}

	/**
	 * Registers this instance as an autoloader.
	 *
	 * @param bool $prepend Whether to prepend the autoloader or not
	 */
	public function register($prepend = false) {
		spl_autoload_register(array($this, "loadClass"), true, $prepend);

		foreach($this->includeFiles as $file) {
			require $file;
		}
	}

	/**
	 * Unregisters this instance as an autoloader.
	 */
	public function unregister() {
		spl_autoload_unregister(array($this, "loadClass"));
	}

	/**
	 * Loads the given class or interface.
	 *
	 * @param  string    $class The name of the class
	 * @return bool|null True if loaded, null otherwise
	 */
	public function loadClass($class) {
		if($file = $this->findFile($class)) {
			include($file);

			return true;
		}

		return false;
	}

	/**
	 * Finds the path to the file where the class is defined.
	 *
	 * @param string $class The name of the class
	 *
	 * @return string|false The path if found, false otherwise
	 */
	public function findFile($class) {
		// class map lookup
		if(isset($this->classMap[$class])) {
			return $this->classMap[$class];
		}
		if($this->classMapAuthoritative || isset($this->missingClasses[$class])) {
			return false;
		}
		if(null !== $this->apcuPrefix) {
			$file = apcu_fetch($this->apcuPrefix.$class, $hit);
			if($hit) {
				return $file;
			}
		}

		$file = $this->findFileWithExtension($class, '.php');

		// Search for Hack files if we are running on HHVM
		if(false === $file && defined('HHVM_VERSION')) {
			$file = $this->findFileWithExtension($class, '.hh');
		}

		if(null !== $this->apcuPrefix) {
			apcu_add($this->apcuPrefix.$class, $file);
		}

		if(false === $file) {
			// Remember that this class does not exist.
			$this->missingClasses[$class] = true;
		}

		return $file;
	}

	private function findFileWithExtension($class, $ext) {
		// PSR-4 lookup
		$logicalPathPsr4 = strtr($class, '\\', DIRECTORY_SEPARATOR) . $ext;

		$first = $class[0];
		if(isset($this->prefixLengthsPsr4[$first])) {
			$subPath = $class;
			while(false !== $lastPos = strrpos($subPath, '\\')) {
				$subPath = substr($subPath, 0, $lastPos);
				$search = $subPath . '\\';
				if(isset($this->prefixDirsPsr4[$search])) {
					$pathEnd = DIRECTORY_SEPARATOR . substr($logicalPathPsr4, $lastPos + 1);
					foreach($this->prefixDirsPsr4[$search] as $dir) {
						if(file_exists($file = $dir . $pathEnd)) {
							return $file;
						}
					}
				}
			}
		}

		// PSR-4 fallback dirs
		foreach($this->fallbackDirsPsr4 as $dir) {
			if(file_exists($file = $dir . DIRECTORY_SEPARATOR . $logicalPathPsr4)) {
				return $file;
			}
		}

		// PSR-0 lookup
		if(false !== $pos = strrpos($class, '\\')) {
			// namespaced class name
			$logicalPathPsr0 = substr($logicalPathPsr4, 0, $pos + 1)
				. strtr(substr($logicalPathPsr4, $pos + 1), '_', DIRECTORY_SEPARATOR);
		} else {
			// PEAR-like class name
			$logicalPathPsr0 = strtr($class, '_', DIRECTORY_SEPARATOR) . $ext;
		}

		if(isset($this->prefixesPsr0[$first])) {
			foreach($this->prefixesPsr0[$first] as $prefix => $dirs) {
				if(0 === strpos($class, $prefix)) {
					foreach($dirs as $dir) {
						if(file_exists($file = $dir . DIRECTORY_SEPARATOR . $logicalPathPsr0)) {
							return $file;
						}
					}
				}
			}
		}

		// PSR-0 fallback dirs
		foreach($this->fallbackDirsPsr0 as $dir) {
			if(file_exists($file = $dir . DIRECTORY_SEPARATOR . $logicalPathPsr0)) {
				return $file;
			}
		}

		// PSR-0 include paths.
		if($this->useIncludePath && $file = stream_resolve_include_path($logicalPathPsr0)) {
			return $file;
		}

		return false;
	}

	/**
	 * Merge an existing composer class loader into the threaded loader.
	 *
	 * @param ClassLoader $loader
	 * @param bool $overwrite
	 */
	public function mergeComposerLoader(ClassLoader $loader, bool $overwrite = false) {
		$reflection = new \ReflectionObject($loader);
		foreach($reflection->getProperties(\ReflectionProperty::IS_PRIVATE) as $property) {
			$property->setAccessible(true);
			switch($property->getName()) {
				//simple array to \Threaded
				case "fallbackDirsPsr0":
				case "fallbackDirsPsr4":
				case "classMap":
				case "missingClasses":
					$this->{$property->getName()}->merge($property->getValue($loader));
					break;
				//double nested array to double nested \Threaded
				case "prefixesPsr0":
				case "prefixLengthsPsr4":
					foreach($property->getValue($loader) as $key => $value) {
						if(!isset($this->{$property->getName()}[$key])) {
							$this->{$property->getName()}[$key] = new \Threaded;
						}
						foreach($value as $k => $v) {
							if(!isset($this->{$property->getName()}[$key][$k])) {
								$this->{$property->getName()}[$key][$k] = new \Threaded;
							}
							$this->{$property->getName()}[$key][$k]->merge($v);
						}
					}
					break;
				//nested array to nested \Threaded
				case "prefixDirsPsr4":
					foreach($property->getValue($loader) as $key => $value) {
						if(!isset($this->{$property->getName()}[$key])) {
							$this->{$property->getName()}[$key] = new \Threaded;
						}
						$this->{$property->getName()}[$key]->merge($value);
					}
					break;
				//simple data types
				case "classMapAuthoritative":
				case "apcuPrefix":
				case "useIncludePath":
					if($overwrite) {
						$this->{$property->getName()} = $property->getValue($loader);
					}
					break;
			}
			$property->setAccessible(false);
		}
	}
}