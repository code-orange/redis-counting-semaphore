<?php
use Symfony\Component\Process\Process;

require __DIR__ . '/../vendor/autoload.php';

chdir(__DIR__);

$nr_containers = 10;
$per_container = 3;

$resource_limit = 10;

$docker_ip = $argv[1] ?? 'localhost';

// Build a docker image from the contains of `container/`
echo "Building docker image for test processes...";
$docker_process = new Process('docker build -t code-orange/redis-counting-semaphore-test ./container/');
$docker_process->run();
if (!$docker_process->isSuccessful()) {
	echo "Docker container failed to build";
	exit(-1);
}
echo "done", PHP_EOL;
// Get a redis docker image and start it
echo "Starting redis image...";
$redis_container = new Process('docker run --name semaphore-redis -p 6379:6379 -d redis redis-server --appendonly no --save "" --timeout 0');
$redis_container->run();
if (!$redis_container->isSuccessful()) {
	echo "Redis container failed to start";
	exit(-1);
}
echo "done", PHP_EOL;

// Setup listener
$client = new Predis\Client(['host' => $docker_ip, 'read_write_timeout' => -1]);
$l = $client->pubSubLoop(['subscribe' => 'chan_critical']);
echo "Starting listening...", PHP_EOL;

// Start 100 containers based on the docker image in `container/`
echo "Spawning processes for the tests...";
for ($i = 0; $i < $nr_containers; $i += 1) {
	$container = new Process('docker run --link semaphore-redis:redis -d code-orange/redis-counting-semaphore-test');
	$container->run();
	if (!$container->isSuccessful()) {
		echo "One of the containers didn't start successfully", PHP_EOL;
		exit(-1);
	}
}
echo "done", PHP_EOL;

// These containers will all try to obtain the same counting semaphore with a limit of 10, 3 times
// Check that the amount of containers in their critical section is never great than the limit of 10
$count = 0;
foreach ($l as $msg) {
	if ($msg->kind == 'message') {
		$count++;
		if ($msg->payload > $resource_limit) {
			// More containers are in the critical section than should be
			echo "There are currently {$msg->payload} users in the critical section, so the test has failed.";
			exit(-1);
		}
		if ($count >= ($nr_containers * $per_container)) {
			// We've reached the total number of times the critical section is entered
			$l->stop();
		}
	}
}

echo "Done! All containers have finished and the resource limit was never exceeded.", PHP_EOL;

// Stop the redis container
echo "Stopping redis...";
$redis_container_stop = new Process('docker kill semaphore-redis && docker rm semaphore-redis');
$redis_container_stop->run();
if (!$redis_container_stop->isSuccessful()) {
	echo "Redis container failed to stop";
	exit(-1);
}
echo "done", PHP_EOL;
