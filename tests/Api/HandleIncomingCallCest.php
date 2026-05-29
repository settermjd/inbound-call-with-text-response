<?php

declare(strict_types=1);

namespace Tests\Api;

use DateTime;
use Codeception\Example;
use Codeception\Util\XmlBuilder;
use Tests\Support\ApiTester;

use function sprintf;

final class HandleIncomingCallCest
{
    public const string BASE_URL     = "http://localhost:8080";
    public const string PHONE_NUMBER = "+61123456789";

    /**
     * This test verifies that the API can:
     *
     * - Receive an incoming call
     * - Respond with a greeting, such as "Thanks for calling, we'll respond via text to answer your questions"
     * - Continue the conversation via SMS (text)
     */
    public function canReceiveAnIncomingCallAndForwardItToSupport(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $I->sendPost(
            '/',
            [
                'From'      => self::PHONE_NUMBER,
                'Direction' => 'inbound',
            ],
        );

        $I->seeResponseCodeIsSuccessful();
        $I->seeResponseIsXml();
        $I->seeHttpHeader("content-type", "application/xml");

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
            ->Play
                ->val('https://api.twilio.com/cowbell.mp3')
                ->parent()
            ->Say
                ->val(
                    sprintf(
                        "You're now being redirected to support. We'll respond via text to %s answer your questions",
                        self::PHONE_NUMBER,
                    ),
                )
                ->parent()
            ->Redirect
                ->val(sprintf('%s/support', self::BASE_URL))
                ->attr('method', 'POST');
        $I->seeXmlResponseIncludes($xml->__toString());
    }

    /**
     * This test checks whether the support endpoint can respond to the limited
     * options that it should support.
     *
     * The options that are supported are:
     *   - What are the opening hours? // "1"" or "Opening Hours"
     *   - What is the general support email address? // "2" or "Support Email"
     *   - What is the account and billing support email address? // "3" or "Account support email" or "Billing support email"
     *   - What is the business' mailing address? // "4" or "Mailing address" or "Business mailing address"
     *   - Register for a callback // "5" or "Callback"
     *   - Make an appointment to talk about something... // "6" or "Book appointment"
     */
    /**
     * @dataProvider pageProvider
     */
    public function canRespondToIncomingSMS(ApiTester $I, Example $example): void
    {
        $I->haveHttpHeader('Content-Type', 'application/x-www-form-urlencoded');
        $I->sendPost(
            '/support',
            [
                'From'      => self::PHONE_NUMBER,
                'Direction' => 'inbound',
                'Body'      => $example['body'],
            ],
        );

        $I->seeResponseCodeIsSuccessful();
        $I->seeResponseIsXml();
        $I->seeHttpHeader("content-type", "application/xml");

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
        $xml = new XmlBuilder();
        $xml->Response
            ->Message->val($example['response']);
        $I->seeXmlResponseIncludes($xml->__toString());
    }

    protected function pageProvider(): array
    {
        $openingHoursResponse           = "Our opening hours are Mon to Fri from 8:30 am to 5:30 pm, and Sat from 9:30 am to 1:30 pm.";
        $supportEmailResponse           = "For general support, email support@example.org.";
        $accountBillingEmailResponse    = "For account support, email accounts@example.org. For billing support, email billing@example.org.";
        $businessMailingAddressResponse = "Our mailing address is 1234 Queen Street, Brisbane, QLD, 4000, Australia.";
        $callbackResponse               = 'Thank you for registering for a phone callback. We\'ll call you within the next 30 minutes.';
        $appointmentResponse            = sprintf(
            'Thank you for seeking an appointment. The next available appointment is at 9:45 am on %s',
            new DateTime('next thursday')->format('l, F jS'),
        );

        return [
            [
                'body'     => 'Opening Hours',
                'response' => $openingHoursResponse,
            ],
            [
                'body'     => 'Hours',
                'response' => $openingHoursResponse,
            ],
            [
                'body'     => 'Support Email',
                'response' => $supportEmailResponse,
            ],
            [
                'body'     => 'Account Support Email',
                'response' => $accountBillingEmailResponse,
            ],
            [
                'body'     => 'Billing Support Email',
                'response' => $accountBillingEmailResponse,
            ],
            [
                'body'     => 'Account',
                'response' => $accountBillingEmailResponse,
            ],
            [
                'body'     => 'Billing',
                'response' => $accountBillingEmailResponse,
            ],
            [
                'body'     => 'Postal address',
                'response' => $businessMailingAddressResponse,
            ],
            [
                'body'     => 'Callback',
                'response' => $callbackResponse,
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
