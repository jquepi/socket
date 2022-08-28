<?php

namespace React\Tests\Socket;

use React\EventLoop\Loop;
use React\Promise;
use React\Promise\Deferred;
use React\Socket\TimeoutConnector;

class TimeoutConnectorTest extends TestCase
{
    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $base = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();

        $connector = new TimeoutConnector($base, 0.01);

        $ref = new \ReflectionProperty($connector, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($connector);

        $this->assertInstanceOf('React\EventLoop\LoopInterface', $loop);
    }

    public function testRejectsWithTimeoutReasonOnTimeout()
    {
        $promise = new Promise\Promise(function () { });

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('google.com:80')->will($this->returnValue($promise));

        $timeout = new TimeoutConnector($connector, 0.01);

        $promise = $timeout->connect('google.com:80');
        Loop::run();

        $this->setExpectedException(
            'RuntimeException',
            'Connection to google.com:80 timed out after 0.01 seconds (ETIMEDOUT)',
            \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
        );
        \React\Async\await($promise);
    }

    public function testRejectsWithOriginalReasonWhenConnectorRejects()
    {
        $promise = Promise\reject(new \RuntimeException('Failed', 42));

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('google.com:80')->will($this->returnValue($promise));

        $timeout = new TimeoutConnector($connector, 5.0);

        $this->setExpectedException(
            'RuntimeException',
            'Failed',
            42
        );
        \React\Async\await($timeout->connect('google.com:80'));
    }

    public function testResolvesWhenConnectorResolves()
    {
        $promise = Promise\resolve(null);

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('google.com:80')->will($this->returnValue($promise));

        $timeout = new TimeoutConnector($connector, 5.0);

        $timeout->connect('google.com:80')->then(
            $this->expectCallableOnce(),
            $this->expectCallableNever()
        );

        Loop::run();
    }

    public function testRejectsAndCancelsPendingPromiseOnTimeout()
    {
        $promise = new Promise\Promise(function () { }, $this->expectCallableOnce());

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('google.com:80')->will($this->returnValue($promise));

        $timeout = new TimeoutConnector($connector, 0.01);

        $timeout->connect('google.com:80')->then(
            $this->expectCallableNever(),
            $this->expectCallableOnce()
        );

        Loop::run();
    }

    public function testCancelsPendingPromiseOnCancel()
    {
        $promise = new Promise\Promise(function () { }, function () { throw new \Exception(); });

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('google.com:80')->will($this->returnValue($promise));

        $timeout = new TimeoutConnector($connector, 0.01);

        $out = $timeout->connect('google.com:80');
        $out->cancel();

        $out->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testRejectionDuringConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        gc_collect_cycles();

        $connection = new Deferred();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($connection->promise());

        $timeout = new TimeoutConnector($connector, 0.01);

        $promise = $timeout->connect('example.com:80');
        $connection->reject(new \RuntimeException('Connection failed'));
        unset($promise, $connection);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testRejectionDueToTimeoutShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        gc_collect_cycles();

        $connection = new Deferred(function () {
            throw new \RuntimeException('Connection cancelled');
        });
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($connection->promise());

        $timeout = new TimeoutConnector($connector, 0);

        $promise = $timeout->connect('example.com:80');

        Loop::run();
        unset($promise, $connection);

        $this->assertEquals(0, gc_collect_cycles());
    }
}
