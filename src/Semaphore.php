<?php
namespace CodeOrange\RedisCountingSemaphore;

use Predis\Client;
use Predis\Transaction\MultiExec;
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
		$acquired = $this->acquire_fair_with_lock();
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
		$this->release_fair();
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
		return $this->refresh_fair();
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
		$identifier = (string) Uuid::uuid4();
		$cszet = $this->name . ':owner';
		$ctr = $this->name . ':counter';

		$now = time();

		$transaction = $this->client->transaction();

		// Time out old entries
		$transaction->zremrangebyscore($this->name, '-inf', $now - $this->timeout);
		$transaction->zinterstore($cszet, [$cszet, $this->name], ['weights' => [1, 0]]);

		// Get the counter
		$transaction->incr($ctr);
		$result = $transaction->execute();
		$counter = $result[count($result) - 1];

		// Try to acquire the semaphore
		$transaction = $this->client->transaction();
		$transaction->zadd($this->name, [$identifier => $now]);
		$transaction->zadd($cszet, [$identifier => $counter]);

		// Check the rank to determine if we got the semaphore
		$transaction->zrank($cszet, $identifier);
		$result = $transaction->execute();
		$rank = $result[count($result) - 1];
		if ($rank < $this->limit) {
			// We got it!
			$this->identifier = $identifier;
			return true;
		}

		// We didn't get the semaphore, clean out the bad data
		$transaction = $this->client->transaction();
		$transaction->zrem($this->name, $identifier);
		$transaction->zrem($cszet, $identifier);
		$transaction->execute();

		return false;
	}
	private function release_fair() {
		$id = $this->identifier;
		$this->identifier = null;

		$transaction = $this->client->transaction();
		$transaction->zrem($this->name, $id);
		$transaction->zrem($this->name . ':owner', $id);
		return $transaction->execute()[0];
	}
	private function refresh_fair() {
		if ($this->client->zadd($this->name, [$this->identifier => time()])) {
			// We lost it
			$this->release_fair();
			return false;
		}
		// We still have it
		return true;
	}
	private function acquire_fair_with_lock() {
		$identifier = $this->acquire_lock(0.01);
		if ($identifier) {
			try {
				return $this->acquire_fair();
			} finally {
				$this->release_lock($identifier);
			}
		}
		return false;
	}

	// From section 6.2 of the book
	private function acquire_lock($acquire_timeout = 10) {
		$identifier = (string)Uuid::uuid4();

		$end = time() + $acquire_timeout;
		while (time() < $end) {
			$res = $this->client->setnx('lock:' . $this->name, $identifier);
			if ($res) {
				return $identifier;
			}
			sleep(0.001);
		}
		return false;
	}
	private function release_lock($id) {
		$lockname = 'lock:' . $this->name;

		$res = $this->client->transaction(['watch' => $lockname, 'cas' => true, 'retry' => 1000], function (MultiExec $t) use ($id, $lockname) {
			$value = $t->get($lockname);
			if ($value === $id) {
				$t->multi();
				$t->del([$lockname]);
			}
		});
		if ($res) {
			return true;
		} else {
			// We didn't execute anything, so we've lost the lock
			return false;
		}
	}
	//</editor-fold>

}
