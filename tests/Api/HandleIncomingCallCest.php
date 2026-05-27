<?php

declare(strict_types=1);

namespace Tests\Api;

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
    public function canReceiveAnIncomingCallAndRespondViaSMS(ApiTester $I): void
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
}
