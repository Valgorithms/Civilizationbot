<?php

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@valzargaming.com>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use Civ13\Civ13;
use Civ14\GameServer;
use React\Http\Browser;
use Psr\Http\Message\ResponseInterface;

use function React\Async\await;
use function React\Promise\resolve;

class GameServerTest extends TestCase
{
    private GameServer $api;
    private $browserMock;

    protected function setUp(): void
    {
        // Mock the Civ13 instance
        $civ13Mock = $this->createMock(Civ13::class);
        
        // Create a real Browser instance or mock it
        $this->browserMock = $this->createMock(Browser::class);

        // Create the GameServer instance
        $this->api = new GameServer($civ13Mock);
        $this->api->setProtocol('http');
        $this->api->setIP(gethostbyname('www.civ13.com')); //$this->api->setIP('127.0.0.1');
        $this->api->setPort(1212);
        $this->api->setWatchdogToken(getenv('SS14_WATCHDOG_TOKEN') ?? 'you should choose a better token');
    }

    public function testGetStatus(): void
    {
        // Mock the response
        $responseMock = $this->createMock(ResponseInterface::class);

        // Mock the Browser's get method
        $this->browserMock->method('get')->with('/status')->willReturn(resolve($responseMock));

        // Test the getStatus method
        await($this->api->getStatus()->then(fn($result) => $this->assertArrayHasKey('name', $result, "The 'name' key is missing in the response.")));
    }

    public function testGetInfo(): void
    {
        // Mock the response
        $responseMock = $this->createMock(ResponseInterface::class);

        // Mock the Browser's get method
        $this->browserMock->method('get')->with('/info')->willReturn(resolve($responseMock));

        // Test the getInfo method
        await($this->api->getInfo()->then(fn($result) => $this->assertArrayHasKey('build', $result, "The 'build' key is missing in the response.")));
    }

    /*
    public function testUpdate(): void
    {
        // Mock the response
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);

        // Mock the Browser's post method
        $this->browserMock->method('post')->with('/update', $this->anything())->willReturn(resolve($responseMock));

        // Test the update method
        await($this->api->update()->then(fn($result) => $this->assertTrue($result)));

    }

    public function testShutdown(): void
    {
        // Mock the response
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);

        // Mock the Browser's post method
        $this->browserMock->method('post')->with('/shutdown', $this->anything())->willReturn(resolve($responseMock));

        // Test the shutdown method
        await($this->api->shutdown()->then( fn($result) => $this->assertTrue($result)));
    }
    */
}