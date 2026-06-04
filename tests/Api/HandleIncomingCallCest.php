<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Application;
use Codeception\Example;
use Codeception\Util\XmlBuilder;
use DateTime;
use Tests\Support\ApiTester;

use function assert;
use function is_string;
use function sprintf;

final class HandleIncomingCallCest
{
    public const string PHONE_NUMBER = "+61123456789";

    /**
     * This test verifies that the API can:
     *
     * - Receive an incoming call
     * - Respond with a greeting, such as "Thanks for calling, we'll respond via text to answer your questions"
     * - Continue the conversation via SMS (text)
     */
    public function canReceiveAnIncomingCallAndForwardItToSupport(ApiTester $i): void
    {
        $i->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $i->sendPost(
            '/',
            [
                'From'      => self::PHONE_NUMBER,
                'Direction' => 'inbound',
            ],
        );

        $i->seeResponseCodeIsSuccessful();
        $i->seeResponseIsXml();
        $i->seeHttpHeader("content-type", "application/xml");

        /**
         * Example response:
         *
         * <?xml version="1.0" encoding="UTF-8"?>
         * <Response>
         *     <Say>Thanks for calling. We'll respond via text to %s answer your questions</Say>
         *     <Play>https://api.twilio.com/cowbell.mp3</Play>
         *     <Redirect method="POST">https://example.org/support</Redirect>
         * </Response
         */
        $xml = new XmlBuilder();
        $xml->Response
            ->Say
                ->val(Application::SUPPORT_REDIRECT)
                ->parent()
            ->Redirect
                ->val('./send-sms')
                ->attr('method', 'POST')
                ->parent()
            ->Hangup;
        $i->seeXmlResponseIncludes($xml->__toString());
    }

    public function willProvideInformationalResponseIfNoBodyIsPresent(ApiTester $i): void
    {
        $i->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $i->sendPost(
            '/support',
            [
                'CallStatus' => 'in-progress',
                'Direction'  => 'inbound',
                'From'       => self::PHONE_NUMBER,
                'To'         => '+12132635137',
            ],
        );

        $i->seeResponseCodeIsSuccessful();
        $i->seeResponseIsXml();
        $i->seeHttpHeader("content-type", "application/xml");

        $xml = new XmlBuilder();
        $xml->Response
            ->Message
                ->val(Application::SUPPORT_OPTIONS)
                ->attr('to', self::PHONE_NUMBER)
                ->attr('from', '+12132635137');
        $i->seeXmlResponseIncludes($xml->__toString());
    }

    /**
     * Checks whether the support endpoint can respond to the options it should support
     *
     * @dataProvider pageProvider
     * @param Example<string> $example
     */
    public function canRespondToIncomingSMS(ApiTester $i, Example $example): void
    {
        $i->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $i->sendPost(
            '/support',
            [
                'From'      => self::PHONE_NUMBER,
                'Direction' => 'inbound',
                'Body'      => $example['body'],
            ],
        );

        $i->seeResponseCodeIsSuccessful();
        $i->seeResponseIsXml();
        $i->seeHttpHeader("content-type", "application/xml");

        /**
         * Example (formatted) response:
         *
         * <?xml version="1.0" encoding="UTF-8"?>
         * <Response>
         *     <Message>
         *         Our mailing address is 1234 Queen Street, Brisbane, QLD, 4000, Australia.
         *     </Message>
         * </Response>
         */
        assert(is_string($example['response']));
        $xml = new XmlBuilder();
        $xml->Response
            ->Message->val($example['response']);
        $i->seeXmlResponseIncludes($xml->__toString());
    }

    /**
     * @return array<int,array<string,string>>
     */
    protected function pageProvider(): array
    {
        $appointmentResponse = sprintf(
            Application::APPOINTMENT,
            new DateTime('next thursday')->format('l, F jS'),
        );

        return [
            [
                'body'     => 'Opening Hours',
                'response' => Application::OPENING_HOURS,
            ],
            [
                'body'     => 'Hours',
                'response' => Application::OPENING_HOURS,
            ],
            [
                'body'     => 'Support Email',
                'response' => Application::SUPPORT_EMAIL,
            ],
            [
                'body'     => 'Account Support Email',
                'response' => Application::ACCOUNT_BILLIING_EMAIL,
            ],
            [
                'body'     => 'Billing Support Email',
                'response' => Application::ACCOUNT_BILLIING_EMAIL,
            ],
            [
                'body'     => 'Account',
                'response' => Application::ACCOUNT_BILLIING_EMAIL,
            ],
            [
                'body'     => 'Billing',
                'response' => Application::ACCOUNT_BILLIING_EMAIL,
            ],
            [
                'body'     => 'Postal address',
                'response' => Application::POSTAL_ADDRESS,
            ],
            [
                'body'     => 'Callback',
                'response' => Application::CALLBACK,
            ],
            [
                'body'     => 'Book appointment',
                'response' => $appointmentResponse,
            ],
            [
                'body'     => 'Appointment',
                'response' => $appointmentResponse,
            ],
            [
                'body'     => 'Something else',
                'response' => <<<EOF
                Please choose from:
                - Account support email
                - Billing support email
                - Book appointment
                - Callback 
                - Opening Hours
                - Postal address
                - Support Email
                EOF,
            ],
        ];
    }
}
