<?php

/**
 * ClassLoaderTest.php – threaded-class-loader
 *
 * Copyright (C) 2019 Jack Noordhuis
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Jack
 *
 */

declare(strict_types=1);

namespace Tests\Autoload;

use function class_exists;
use jacknoordhuis\Autoload\ThreadedClassLoader;
use Tests\TestCase;

/**
 * Tests the jacknoordhuis\Autoload\ThreadedClassLoader class.
 *
 *  Adapted from the composer autoloader test cases.
 *  https://github.com/composer/composer
 */
class ClassLoaderTest extends TestCase {

	/**
	 * Provides arguments for ->testLoadClass().
	 *
	 * @return array Array of parameter sets to test with.
	 */
	public function getLoadClassTests()
	{
		return array(
			array('Namespaced\\', __DIR__ . '/Fixtures', 'Namespaced\\Foo', false),
			array('Pearlike_', __DIR__ . '/Fixtures', 'Pearlike_Foo', false),
			array('ShinyVendor\\ShinyPackage\\', __DIR__ . '/Fixtures', 'ShinyVendor\\ShinyPackage\\SubNamespace\\Foo', true),
		);
	}

	/**
	 * Tests regular PSR-0 and PSR-4 class loading.
	 *
	 * @dataProvider getLoadClassTests
	 *
	 * @param string $prefix The namespace prefix.
	 * @param string $path The path to the namespace.
	 * @param string $class The fully-qualified class name to test, without preceding namespace separator.
	 * @param bool $psr4 If the prefix and path provided are for a psr4 namespace.
	 */
	public function testLoadClass($prefix, $path, $class, $psr4)
	{
		$loader = new ThreadedClassLoader();
		$psr4 ? $loader->addPsr4($prefix, $path) : $loader->add($prefix, $path);
		$loader->loadClass($class);
		$this->assertTrue(class_exists($class, false), "->loadClass() loads '$class'");
	}

	/**
	 * getPrefixes method should return empty array if ClassLoader does not have any psr-0 configuration
	 */
	public function testGetPrefixesWithNoPSR0Configuration()
	{
		$loader = new ThreadedClassLoader();
		$this->assertEmpty($loader->getPrefixes());
	}

	/**
	 * Tests loading existing classes on a thread.
	 *
	 * @dataProvider getLoadClassTests
	 *
	 * @param string $prefix The namespace prefix.
	 * @param string $path The path to the namespace.
	 * @param string $class The fully-qualified class name to test, without preceding namespace separator.
	 * @param bool $psr4 If the prefix and path provided are for a psr4 namespace.
	 */
	public function testLoadBeforeThreadStart($prefix, $path, $class, $psr4) {
		$loader = new ThreadedClassLoader();
		$worker = new class extends \Worker {
			/** @var ThreadedClassLoader */
			public $loader;
			public $class;
			public $hadClass;

			public function run() {
				$this->loader->register();

				$this->hadClass[] = class_exists($this->class);
			}
		};

		$hadClass = new \Threaded;

		$worker->loader = $loader;
		$worker->hadClass = $hadClass;
		$worker->class = $class;

		$psr4 ? $loader->addPsr4($prefix, $path) : $loader->add($prefix, $path);

		$worker->start() && $worker->join();

		$this->assertTrue($hadClass->pop(), "->loadClass() works when loader passed into threaded context.");

	}

	/**
	 * Tests registering classes on a thread.
	 *
	 * @dataProvider getLoadClassTests
	 *
	 * @param string $prefix The namespace prefix.
	 * @param string $path The path to the namespace.
	 * @param string $class The fully-qualified class name to test, without preceding namespace separator.
	 * @param bool $psr4 If the prefix and path provided are for a psr4 namespace.
	 */
	public function testLoadFromThread($prefix, $path, $class, $psr4) {
		$loader = new ThreadedClassLoader();
		$worker = new class extends \Worker {
			/** @var ThreadedClassLoader */
			public $loader;
			public $psr4;
			public $prefix;
			public $path;

			public function run() {
				$this->psr4 ? $this->loader->addPsr4($this->prefix, $this->path) : $this->loader->add($this->prefix, $this->path);
			}
		};

		$worker->loader = $loader;
		$worker->psr4 = $psr4;
		$worker->prefix = $prefix;
		$worker->path = $path;

		$worker->start() && $worker->join();

		$this->assertTrue(class_exists($class), "->loadClass() works when called from threaded context.");
	}

	/**
	 * Tests registering on the main thread after starting the worker.
	 *
	 * @dataProvider getLoadClassTests
	 *
	 * @param string $prefix The namespace prefix.
	 * @param string $path The path to the namespace.
	 * @param string $class The fully-qualified class name to test, without preceding namespace separator.
	 * @param bool $psr4 If the prefix and path provided are for a psr4 namespace.
	 */
	public function testLoadFromMainThread($prefix, $path, $class, $psr4) {

		$loader = new ThreadedClassLoader();
		$worker = new class extends \Worker {
			/** @var ThreadedClassLoader */
			public $loader;
			public $done = false;
			public $class;
			public $hadClass;

			public function run() {
				$this->loader->register();

				$this->synchronized(function($thread) {
					if(!$thread->done) {
						$thread->wait();
					}
				}, $this);

				$this->hadClass[] = class_exists($this->class);
			}
		};

		$hadClass = new \Threaded;

		$worker->loader = $loader;
		$worker->class = $class;
		$worker->hadClass = $hadClass;

		$worker->start();

		$psr4 ? $loader->addPsr4($prefix, $path) : $loader->add($prefix, $path);

		$worker->synchronized(function($thread) {
			$thread->done = true;
			$thread->notify();
		}, $worker);

		$worker->join() && $this->assertTrue($hadClass->pop(), "->loadClass() works on main thread when worker already started.");
	}

}