<?php
namespace CodeOrange\RedisCountingSemaphore;

use Predis\Client;
use Ramsey\Uuid\Uuid;

/**
 * Class Semaphore
 *
 * A fair, race free implementation of a counting semaphore, based on the algorithm description in
 * section 6.3 of "Redis in Action"
 * (https://redislabs.com/ebook/part-2-core-concepts/chapter-6-application-components-in-redis/6-3-counting-semaphores/)
 *
 * @package CodeOrange\RedisCountingSemaphore
 */
class Semaphore {
	private $client;
	private $name;
	private $limit;
	private $timeout;

	/** @var string $identifier Identifier for the acquired semaphore */
	private $identifier = null;

	/**
	 * Semaphore constructor.
	 * @param Client $client Predis client with an open connection
	 * @param string $name Name of the semaphore
	 * @param int $limit The amount of resources this semaphore protects
	 * @param int $timeout Timeout of an acquired semaphore, in seconds
	 */
	public function __construct(Client $client, $name, $limit = 1, $timeout = 10) {
		$this->client = $client;
		$this->name = $name;
		$this->limit = $limit;
		$this->timeout = $timeout;
	}

	/**
	 * Try to acquire a semaphore
	 *
	 * @param float $sleep Number of seconds to sleep between retries. If null, this function will not retry but return immediately.
	 * @param int $retries Number of times to retry before giving up
	 * @return bool Whether or not the semaphore was acquired correctly
	 */
	public function acquire($sleep = null, $retries = null) {
		if ($this->identifier) {
			// We already have it
			return true;
		}
		$acquired = $this->acquire_unfair();
		if ($acquired) {
			return true;
		} else {
			if ($retries > 0 && $sleep > 0) {
				sleep($sleep);
				return $this->acquire($sleep, $retries - 1);
			}
			return false;
		}
	}

	/**
	 * Release this semaphore
	 *
	 * @return void
	 */
	public function release() {
		if (!$this->identifier) {
			// We didn't have it
			return;
		}
		$this->release_unfair();
	}

	/**
	 * Refresh the semaphore
	 *
	 * @return bool Whether or not we still have the semaphore
	 */
	public function refresh() {
		if (!$this->identifier) {
			return false;
		}
		return true;
	}

	//<editor-fold desc="Methods as built up to in the book">
	private function acquire_unfair() {
		$identifier = (string) Uuid::uuid4();
		$now = time();

		$transaction = $this->client->transaction();
		// Time out old identifiers
		$transaction->zremrangebyscore($this->name, '-inf', $now - $this->timeout);
		// Try to acquire semaphore
		$transaction->zadd($this->name, [$identifier => $now]);
		// Check to see if we have it
		$transaction->zrank($this->name, $identifier);
		$result = $transaction->execute();
		$rank = $result[count($result) - 1];
		if ($rank < $this->limit) {
			// We got it!
			$this->identifier = $identifier;
			return true;
		}

		// We didn't get it, remove the identifier from the table
		$this->client->zrem($this->name, $identifier);
		return false;
	}
	private function release_unfair() {
		$id = $this->identifier;
		$this->identifier = null;
		return $this->client->zrem($this->name, $id);
	}
	private function acquire_fair() {

	}
	private function acquire_fair_with_lock() {

	}
	//</editor-fold>

}
