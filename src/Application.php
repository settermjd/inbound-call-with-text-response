<?php

declare(strict_types=1);

namespace App;

use DateTime;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App as SlimApp;
use Slim\Interfaces\RouteInterface;
use Slim\Middleware\ContentLengthMiddleware;
use Twilio\Rest\Client;
use Twilio\TwiML\MessagingResponse;
use Twilio\TwiML\VoiceResponse;

use function array_key_exists;
use function assert;
use function is_string;
use function sprintf;
use function strcasecmp;

/**
 * This class encapsulates the central Slim application, making it easier to
 * create and test.
 */
final class Application
{
    public const string ACCOUNT_BILLIING_EMAIL = <<<EOF
    For account support, email accounts@example.org. For billing support, email billing@example.org."
    EOF;

    public const string APPOINTMENT = <<<EOF
    Thank you for seeking an appointment.
    The next available appointment is at 9:45 am on %s
    EOF;

    public const string POSTAL_ADDRESS = 'Our mailing address is 1234 Queen Street, Brisbane, QLD, 4000, Australia.';

    public const string CALLBACK = <<<EOF
    Thank you for registering for a phone callback.
    We'll call you within the next 30 minutes.
    EOF;

    public const string SUPPORT_REDIRECT = <<<EOF
    We're not able to handle support calls directly at the moment.
    However, we can provide limited support via SMS.
    We'll send you an SMS in a moment with the available options.
    EOF;

    public const string SUPPORT_OPTIONS = <<<EOF
    Please choose from the following support options:
    - For our opening hours, reply with: "opening hours".
    - For our postal address, reply with: "postal address".
    - For our support email, reply with: "support email".
    - For the account support email, reply with: "account support email".
    - For the billing support email, reply with: "billing support email".
    - To book an appointment, reply with: "book appointment".
    - To request a callback, reply with: "callback".
    EOF;

    public const string OPENING_HOURS = <<<EOF
    Our opening hours are Mon to Fri from 8:30 am to 5:30 pm, and Sat from 9:30 am to 1:30 pm."
    EOF;

    public const string SUPPORT_EMAIL = "For general support, email support@example.org.";

    public const array OPTIONS_OPENING_HOURS = [
        'hours',
        'opening hours',
    ];

    public const array OPTIONS_ACCOUNT_BILLING_SUPPORT_EMAIL = [
        'account support email',
        'account',
        'billing support email',
        'billing',
    ];

    public const array OPTIONS_APPOINTMENT = [
        'appointment',
        'book appointment',
    ];

    /**
     * @param SlimApp<ContainerInterface|null> $app
     */
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
        $this->app->post('/send-sms', [$this, 'handleSendInitialSupportSms']);
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
     * Launches the application
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
        $twimlResponse->say(self::SUPPORT_REDIRECT);
        $twimlResponse->redirect("./send-sms", ["method" => "POST"]);
        $twimlResponse->hangup();

        $response = $response->withHeader("content-type", "application/xml");
        $response->getBody()->write($twimlResponse->asXML());

        return $response;
    }

    /**
     * Handles support SMS requests
     *
     * The body of the response contains TwiML that instructs Twilio to send a
     * reply with the following details:
     *
     *   - The opening hours
     *   - The general support email address
     *   - The account and billing support email address
     *   - The business' mailing address
     *
     * Additionally, it can (faux) handle:
     *
     *   - Registering for a callback
     *   - Making an appointment
     *
     * Finally, the function provides the set of options to choose from if the
     * request to the application is not in response to an incoming support
     * SMS, i.e., it is the initial redirect after the incoming support call.
     *
     * @see https://www.twilio.com/docs/messaging/twiml/message
     * @see https://www.twilio.com/docs/voice/twiml#request-parameters
     */
    public function handleSupportRequestsBySms(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $requestData = (array) $request->getParsedBody();

        $twimlResponse = new MessagingResponse();
        if (! array_key_exists('Body', $requestData)) {
            $twimlResponse->message(self::SUPPORT_OPTIONS, [
                'to'   => $requestData['From'],
                'from' => $requestData['To'],
            ]);
        } else {
            $body = $requestData['Body'];
            assert(is_string($body));
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
            $twimlResponse->message($message);
        }

        $response = $response->withHeader('content-type', 'application/xml');
        $response->getBody()->write($twimlResponse->asXML());

        return $response;
    }

    /**
     * Sends an SMS to the caller with the self-service support options
     *
     * @see https://www.twilio.com/docs/voice/twiml#twilios-request-to-your-application
     */
    public function handleSendInitialSupportSms(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $requestData = (array) $request->getParsedBody();
        assert(is_string($requestData["From"]));

        $client = $this->app->getContainer()?->get(Client::class);
        if ($client instanceof Client) {
            $message = $client
                ->messages
                ->create(
                    $requestData["From"],
                    [
                        "body" => self::SUPPORT_OPTIONS,
                        "from" => $requestData["To"],
                    ],
                );
        }

        $response = $response->withHeader("content-type", "application/xml");
        $response->getBody()->write(new VoiceResponse()->asXML());

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

        foreach ($possibleValues as $message) {
            if (strcasecmp($body, $message) === 0) {
                return true;
            }
        }

        return false;
    }
}
