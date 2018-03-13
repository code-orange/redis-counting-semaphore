Redis counting semaphore [![Latest Stable Version](https://poser.pugx.org/code-orange/redis-counting-semaphore/v/stable)](https://packagist.org/packages/code-orange/redis-counting-semaphore) [![Total Downloads](https://poser.pugx.org/code-orange/redis-counting-semaphore/downloads)](https://packagist.org/packages/code-orange/redis-counting-semaphore) [![License](https://poser.pugx.org/code-orange/redis-counting-semaphore/license)](https://packagist.org/packages/code-orange/redis-counting-semaphore) [![composer.lock](https://poser.pugx.org/code-orange/redis-counting-semaphore/composerlock)](https://packagist.org/packages/code-orange/redis-counting-semaphore)
========================

`redis-counting-semaphore` is a package with a counting semaphore implementation for PHP.
It uses redis as a central broker.

# Installation

To install redis-counting-semaphore with composer:

```
composer require code-orange/redis-counting-semaphore
```

# Usage

First, make sure you have a [Predis](https://github.com/nrk/predis) connection instance.

You can create and attempt to obtain a semaphore like so:
 
```php
<?php
use CodeOrange\RedisCountingSemaphore\Semaphore;

$client = new Predis\Client();

// Create a counting semaphore with a limit of 3
$sem = new Semaphore($client, 'semaphore-name', 3);
if ($sem->acquire(0.1, 10)) {
	// Obtained the semaphore
	use_limited_resource();
	$sem->release();
} else {
	// We weren't able to get a semaphore, even though we tried 10 times
	// And slept for 0.1 seconds in between tries
}
```

## API

```php
/**
 * Semaphore constructor.
 * @param Client $client Predis client with an open connection
 * @param string $name Name of the semaphore
 * @param int $limit The amount of resources this semaphore protects
 * @param int $timeout Timeout of an acquired semaphore, in seconds
 */
public Semaphore(Client $client, $name, $limit = 1, $timeout = 10);

/**
 * Try to acquire a semaphore
 *
 * @param float $sleep Number of seconds to sleep between retries. If null, this function will not retry but return immediately.
 * @param int $retries Number of times to retry before giving up
 * @return bool Whether or not the semaphore was acquired correctly
 */
public function acquire($sleep = null, $retries = null);

/**
 * Release this semaphore
 *
 * @return void
 */
public function release();

/**
 * Refresh the semaphore
 *
 * @return bool Whether or not we still have the semaphore
 */
public function refresh();
```
