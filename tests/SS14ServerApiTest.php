<?php

/*
 * This file is a part of the Civ14 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@valzargaming.com>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use Civ14\ServerAPI;
use Civ14\GameServer;
use React\Http\Browser;
use Psr\Http\Message\ResponseInterface;

use function React\Async\await;
use function React\Promise\resolve;

class ServerAPITest extends TestCase
{
    private ServerAPI $api;
    private $browserMock;

    protected function setUp(): void
    {
        // Create a real Browser instance or mock it
        $this->browserMock = $this->createMock(Browser::class);

        // Mock the GameServer instance
        $gameServerMock = $this->createMock(GameServer::class);
        $gameServerMock->method('getBrowserProperty')->willReturn($this->browserMock);

        // Create the ServerAPI instance
        $this->api = new ServerAPI($gameServerMock);
        $this->api->setProtocol('http');
        $this->api->setIP(gethostbyname('www.civ13.com'));
        $this->api->setPort(1212);
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