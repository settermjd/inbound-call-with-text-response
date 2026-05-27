<?php

declare(strict_types=1);

namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App as SlimApp;
use Slim\Interfaces\RouteInterface;
use Slim\Middleware\ContentLengthMiddleware;
use Twilio\TwiML\VoiceResponse;

use function sprintf;

/**
 * This class encapsulates the central Slim application, making it easier to
 * create and test.
 */
final class Application
{
    public function __construct(private readonly SlimApp $app)
    {
        $app->add(new ContentLengthMiddleware());
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(true, true, true);
    }

    /**
     * setupRoutes sets up the application's routing table
     */
    public function setupRoutes(): void
    {
        $this->app->post('/', [$this, 'handleDefaultRoute']);
    }

    /**
     * getRoutes returns the application's current routes
     *
     * @return RouteInterface[]
     */
    public function getRoutes(): array
    {
        return $this->app->getRouteCollector()->getRoutes();
    }

    /**
     * run launches the application
     */
    public function run(): void
    {
        $this->app->run();
    }

    /**
     * Returns TwiML to answer an incoming phone call and start an SMS interaction
     *
     * The body of the response contains TwiML that instructs Twilio to:
     *
     * - Play a greeting from an audio file
     * - Tell the customer that they are being redirected to support and that
     *   the call will be followed up by SMS
     * - Redirect the caller to the support department
     *
     * @see https://www.twilio.com/docs/voice/twiml#twilios-request-to-your-application
     */
    public function handleDefaultRoute(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $twimlResponse = new VoiceResponse();
        $twimlResponse->play("https://api.twilio.com/cowbell.mp3");
        $twimlResponse->say(
            sprintf(
                "You're now being redirected to support. We'll respond via text to %s answer your questions",
                $request->getParsedBody()['From'],
            ),
        );
        $twimlResponse->redirect(sprintf("%s/support", $_SERVER['BASE_URL']), ["method" => "POST"]);

        $response = $response->withHeader("content-type", "application/xml");
        $response->getBody()->write($twimlResponse->asXML());

        return $response;
    }
}
