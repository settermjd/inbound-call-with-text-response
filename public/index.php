<?php

declare(strict_types=1);

use App\Application;
use DI\Container;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;
use Twilio\Rest\Client;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Load environment variables from .env in the project's parent directory
 */
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

/**
 * We next set up the application's DI container, which uses PHP-DI.
 */
$container = new Container();

/**
 * To simplify interacting with Twilio's APIs, we next register a Twilio REST Client object
 * as a service with the DI container, available in Twilio's PHP Helper Library.
 * Find out more about it at https://www.twilio.com/docs/libraries/reference/twilio-php/.
 */
$container->set(
    Client::class,
    fn(): Client => new Client($_ENV['TWILIO_ACCOUNT_SID'], $_ENV['TWILIO_AUTH_TOKEN']),
);

/**
 * With the DI container initialised, it's now set as the Slim application's DI container,
 * before initialising a new Slim App object.
 */
AppFactory::setContainer($container);
$app = AppFactory::createFromContainer($container);

/**
 * Finally, initialise a new Application object, initialise the routing table, and boot the
 * application, having it available for handling requests.
 */
$application = new Application($app);
$application->setupRoutes();
$application->run();
