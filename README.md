PHP Threaded Class Loader Package
===============
_A thread safe implementation of a PSR-0, PSR-4 and classmap class loader for use with the pthreads extension!_

The core advantage of having a central thread safe class loader is that new classes can be registered to the loader, on any thread, and when PHP tries to load the class from any thread the loader is also registered on, everything will 'just work' as they shared \Threaded objects.

## Installation

### Composer

Via command line:
```bash
$ composer require jacknoordhuis/threaded-class-loader
```
Or add the package to your `composer.json`:
```json
{
    "require": {
        "jacknoordhuis/threaded-class-loader": "*"
    }
}
```

## Usage

Here is a basic example of replacing the default composer class loader:

```php
$loader = require_once "vendor/autoload.php";
$loader = jacknoordhuis\Autoload\ThreadedClassLoader::fromComposerLoader($loader);
```

This example will load the composer autoloader so that our threaded loader can be loaded, then we call the helper method which conveniently handles converting composers mappings to \Threaded members. Depending on the extra arguments provided the helper method will also (by default) unregister the composer loader and register the new thread safe loader on the current thread.

You can now safely pass the `$loader` to a new thread and call the `ThreadedClassLoader::register()` method to load the classes on the new thread.

```php
$loader = ...; //put example code from above here

class MyWorker extends \Worker {

	public $loader;

	public function __construct($loader) {
		$this->loader = $loader;
	}

	public function run() {
		$this->loader->register();

		//put your code here
	}
}

$worker = new MyWorker($loader);
$worker->start() && $worker->join();
```

<br/>
__The content of this repo is licensed under the GNU Lesser General Public License v3. A full copy of the license is
available [here](LICENSE).__
