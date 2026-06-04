<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application;
use Codeception\Test\Unit;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Response;
use Tests\Support\UnitTester;
use Twilio\Rest\Api\V2010\Account\MessageList;
use Twilio\Rest\Client;

class ApplicationTest extends Unit
{
    protected UnitTester $tester;

    public function testSendsInitialSupportSms(): void
    {
        $caller       = '+61123456789';
        $twilioNumber = '+1123456789';

        $request = $this->createMock(ServerRequestInterface::class);
        $request
            ->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(
                [
                    'From' => $caller,
                    'To'   => $twilioNumber,
                ],
            );

        $messageList = $this->createMock(MessageList::class);
        $messageList
            ->expects($this->once())
            ->method('create')
            ->with(
                $caller,
                [
                    'body' => Application::SUPPORT_OPTIONS,
                    'from' => $twilioNumber,
                ],
            );

        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('__get')
            ->with('messages')
            ->willReturn($messageList);

        $container = $this->createMock(ContainerInterface::class);
        $container
            ->expects($this->once())
            ->method('get')
            ->with(Client::class)
            ->willReturn($client);

        $slimApp = $this->createMock(App::class);
        $slimApp
            ->expects($this->once())
            ->method('getContainer')
            ->willReturn($container);

        $application = new Application($slimApp);
        $application->handleSendInitialSupportSms($request, new Response());
    }
}
