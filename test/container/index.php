<?php
require __DIR__ . '/vendor/autoload.php';

echo "Connecting to redis", PHP_EOL;
$client = new Predis\Client(['host' => 'redis']);
echo "Connected!", PHP_EOL;

for ($i = 0; $i < 3; $i += 1) {
	echo "Starting the loop", PHP_EOL;
	$semaphore = new \CodeOrange\RedisCountingSemaphore\Semaphore($client, 'test1', 10, 50);
	$semaphore->acquire(0.5, 1000000);
	// BEGIN critical section
	echo "In the critical section!", PHP_EOL;
	$critical = $client->incr('critical');
	$client->publish('chan_critical', $critical);
	sleep(rand(5, 15));
	echo "Leaving the critical section!", PHP_EOL;
	$client->decr('critical');
	// END critical sections
	$semaphore->release();
}
