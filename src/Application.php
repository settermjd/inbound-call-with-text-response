<?php

declare(strict_types=1);

namespace App;

use DateTime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App as SlimApp;
use Slim\Interfaces\RouteInterface;
use Slim\Middleware\ContentLengthMiddleware;
use Twilio\TwiML\MessagingResponse;
use Twilio\TwiML\VoiceResponse;

use function is_array;
use function is_string;
use function sprintf;
use function strcasecmp;

/**
 * This class encapsulates the central Slim application, making it easier to
 * create and test.
 */
final class Application
{
    public const string OPENING_HOURS          = "Our opening hours are Mon to Fri from 8:30 am to 5:30 pm, and Sat from 9:30 am to 1:30 pm.";
    public const string SUPPORT_EMAIL          = "For general support, email support@example.org.";
    public const string ACCOUNT_BILLIING_EMAIL = "For account support, email accounts@example.org. For billing support, email billing@example.org.";
    public const string POSTAL_ADDRESS         = 'Our mailing address is 1234 Queen Street, Brisbane, QLD, 4000, Australia.';
    public const string CALLBACK               = 'Thank you for registering for a phone callback. We\'ll call you within the next 30 minutes.';
    public const string APPOINTMENT            = 'Thank you for seeking an appointment. The next available appointment is at 9:45 am on %s';

    public const array OPTIONS_OPENING_HOURS                 = [
        'hours',
        'opening hours',
    ];
    public const array OPTIONS_ACCOUNT_BILLING_SUPPORT_EMAIL = [
        'account support email',
        'account',
        'billing support email',
        'billing',
    ];
    public const array OPTIONS_APPOINTMENT                   = [
        'appointment',
        'book appointment',
    ];

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
        $this->app->post('/', [$this, 'handleIncomingCall']);
        $this->app->post('/support', [$this, 'handleSupportRequestsBySms']);
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
    public function handleIncomingCall(
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
        $twimlResponse->redirect(
            sprintf("%s/support", $_SERVER['BASE_URL']),
            ["method" => "POST"],
        );

        $response = $response->withHeader("content-type", "application/xml");
        $response->getBody()->write($twimlResponse->asXML());

        return $response;
    }

    /**
     * Returns TwiML to send a support SMS reply to an incoming SMS
     *
     * The body of the response contains TwiML that instructs Twilio to do one of:
     *
     *   - Send the opening hours
     *   - Send the general support email address
     *   - Send the account and billing support email address
     *   - Send the business' mailing address
     *   - Register for a callback
     *   - Make an appointment
     *
     * @see https://www.twilio.com/docs/messaging/twiml/message
     */
    public function handleSupportRequestsBySms(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $body    = $request->getParsedBody()['Body'];
        $message = match (true) {
            $this->isMatch($body, self::OPTIONS_OPENING_HOURS) => self::OPENING_HOURS,
            $this->isMatch($body, 'support email') => self::SUPPORT_EMAIL,
            $this->isMatch($body, self::OPTIONS_ACCOUNT_BILLING_SUPPORT_EMAIL) => self::ACCOUNT_BILLIING_EMAIL,
            $this->isMatch($body, 'postal address') => self::POSTAL_ADDRESS,
            $this->isMatch($body, 'callback') => self::CALLBACK,
            $this->isMatch($body, self::OPTIONS_APPOINTMENT) => sprintf(
                self::APPOINTMENT,
                new DateTime('next thursday')->format('l, F jS'),
            ),
            default => <<<EOF
            Please choose from:
            - Account support email
            - Billing support email
            - Book appointment
            - Callback 
            - Opening Hours
            - Postal address
            - Support Email
            EOF,
        };

        $twimlResponse = new MessagingResponse();
        $twimlResponse->message($message);

        $response = $response->withHeader('content-type', 'application/xml');
        $response->getBody()->write($twimlResponse->asXML());

        return $response;
    }

    /**
     * @param string|array<int,string> $possibleValues
     */
    private function isMatch(string $body, string|array $possibleValues): bool
    {
        if (is_string($possibleValues)) {
            return strcasecmp($body, $possibleValues) === 0;
        }

        if (is_array($possibleValues)) {
            foreach ($possibleValues as $message) {
                if (strcasecmp($body, $message) === 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
