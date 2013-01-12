<?php

namespace React\EventLoop;

use SplObjectStorage;
use libev\TimerEvent as LibEvTimer;
use React\EventLoop\Timer\Timer;
use React\EventLoop\Timer\TimerInterface;

/**
 * @see https://github.com/m4rw3r/php-libev
 * @see https://gist.github.com/1688204
 */
class LibEvLoop implements LoopInterface
{
    private $loop;
    private $timers;
    private $readEvents = array();
    private $writeEvents = array();

    public function __construct()
    {
        $this->loop = new \libev\EventLoop();
        $this->timers = new SplObjectStorage();
    }

    public function addReadStream($stream, $listener)
    {
        $this->addStream($stream, $listener, \libev\IOEvent::READ);
    }

    public function addWriteStream($stream, $listener)
    {
        $this->addStream($stream, $listener, \libev\IOEvent::WRITE);
    }

    public function removeReadStream($stream)
    {
        $this->readEvents[(int)$stream]->stop();
        unset($this->readEvents[(int)$stream]);
    }

    public function removeWriteStream($stream)
    {
        $this->writeEvents[(int)$stream]->stop();
        unset($this->writeEvents[(int)$stream]);
    }

    public function removeStream($stream)
    {
        if (isset($this->readEvents[(int)$stream])) {
            $this->removeReadStream($stream);
        }

        if (isset($this->writeEvents[(int)$stream])) {
            $this->removeWriteStream($stream);
        }
    }

    private function addStream($stream, $listener, $flags)
    {
        $listener = $this->wrapStreamListener($stream, $listener, $flags);
        $event = new \libev\IOEvent($listener, $stream, $flags);
        $this->loop->add($event);

        if (($flags & \libev\IOEvent::READ) === $flags) {
            $this->readEvents[(int)$stream] = $event;
        } elseif (($flags & \libev\IOEvent::WRITE) === $flags) {
            $this->writeEvents[(int)$stream] = $event;
        }
    }

    private function wrapStreamListener($stream, $listener, $flags)
    {
        if (($flags & \libev\IOEvent::READ) === $flags) {
            $removeCallback = array($this, 'removeReadStream');
        } elseif (($flags & \libev\IOEvent::WRITE) === $flags) {
            $removeCallback = array($this, 'removeWriteStream');
        }

        return function ($event) use ($stream, $listener, $removeCallback) {
            if (feof($stream)) {
                call_user_func($removeCallback, $stream);

                return;
            }

            call_user_func($listener, $stream);
        };
    }

    public function addTimer($interval, $callback)
    {
        $timer = new Timer($this, $interval, $callback, false);
        $this->setupTimer($timer);

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback)
    {
        $timer = new Timer($this, $interval, $callback, true);
        $this->setupTimer($timer);

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer)
    {
        if (isset($this->timers[$timer])) {
            $this->loop->remove($this->timers[$timer]);
            $this->timers->detach($timer);
        }
    }

    private function setupTimer(TimerInterface $timer)
    {
        $dummyCallback = function () {};
        $interval = $timer->getInterval();

        if ($timer->isPeriodic()) {
            $libevTimer = new \libev\TimerEvent($dummyCallback, $interval, $interval);
        } else {
            $libevTimer = new \libev\TimerEvent($dummyCallback, $interval);
        }

        $libevTimer->setCallback(function () use ($timer) {
            call_user_func($timer->getCallback(), $timer);

            if ($timer->isPeriodic() === false) {
                $timer->cancel();
            }
        });

        $this->timers->attach($timer, $libevTimer);
        $this->loop->add($libevTimer);

        return $timer;
    }

    public function tick()
    {
        $this->loop->run(\libev\EventLoop::RUN_ONCE);
    }

    public function run()
    {
        $this->loop->run();
    }

    public function stop()
    {
        $this->loop->breakLoop();
    }
}
