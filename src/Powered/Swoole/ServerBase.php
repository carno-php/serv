<?php
/**
 * Server base for swoole
 * User: moyo
 * Date: 28/09/2017
 * Time: 5:40 PM
 */

namespace Carno\Serv\Powered\Swoole;

use Carno\Coroutine\Context;
use Carno\Net\Address;
use Carno\Net\Connection;
use Carno\Net\Contracts\Server;
use Carno\Net\Contracts\Serving;
use Carno\Net\Events;
use Swoole\Server as SWServer;

abstract class ServerBase implements Serving
{
    /**
     * server related events
     * @var array
     */
    protected $masterEvs = [
        'start', 'shutdown',
        'managerStart', 'managerStop',
        'workerStart', 'workerStop', 'workerError',
    ];

    /**
     * implementer related events
     * @var array
     */
    protected $acceptEvs = [];

    /**
     * @var array
     */
    protected $baseConfig = [
        'daemonize' => 0,
        'dispatch_mode' => 1,
        'open_cpu_affinity' => 1,
        'open_tcp_nodelay' => 1,
        'tcp_fastopen' => true,
        'backlog' => 512,
        'max_request' => 0,
        'reload_async' => true,
        'max_wait_time' => 10,
    ];

    /**
     * @var array
     */
    protected $userConfig = [];

    /**
     * @var Context $ctx
     */
    protected $ctx = null;

    /**
     * @var Events
     */
    protected $events = null;

    /**
     * @var string
     */
    protected $serviced = null;

    /**
     * @return Context
     */
    protected function ctx() : Context
    {
        return $this->ctx ?? $this->ctx = new Context();
    }

    /**
     * @param Address $address
     * @param Events $events
     * @param string $implement
     * @param array $options
     * @param int $type
     * @return SWServer
     */
    protected function standardServerCreate(
        Address $address,
        Events $events,
        string $implement,
        array $options = [],
        int $type = SWOOLE_SOCK_TCP
    ) : SWServer {
        $server = new $implement($address->host(), $address->port(), SWOOLE_BASE, $type);

        $this->serverConfig($server, $this->baseConfig, $this->userConfig, $options);
        $this->registerEvs($server, array_merge($this->masterEvs, $this->acceptEvs));

        $this->events = $events;

        $this->evCreated($server);

        return $server;
    }

    /**
     * @param SWServer $server
     * @param array $events
     */
    protected function registerEvs($server, array $events) : void
    {
        foreach ($events as $event) {
            $server->on($event, [$this, sprintf('ev%s', ucfirst($event))]);
        }
    }

    /**
     * @param SWServer $server
     * @param array ...$options
     */
    protected function serverConfig($server, array ...$options) : void
    {
        $server->set(array_merge(...$options));
    }

    /**
     * @param SWServer $server
     */
    public function evCreated(SWServer $server) : void
    {
        $this->events->notify(
            Events\Server::CREATED,
            (new Connection($this->ctx()))
                ->setEvents($this->events)
                ->setServer($this->genServerOps($server))
                ->setServiced($this->serviced)
                ->setLocal($server->host, $server->port)
        );
    }

    /**
     * @param SWServer $server
     */
    public function evStart(SWServer $server) : void
    {
        @swoole_set_process_name(sprintf('[master] %s', $this->serviced));

        $this->events->notify(
            Events\Server::STARTUP,
            (new Connection($this->ctx()))
                ->setEvents($this->events)
                ->setServer($this->genServerOps($server))
                ->setServiced($this->serviced)
                ->setLocal($server->host, $server->port)
        );
    }

    /**
     * @param SWServer $server
     */
    public function evShutdown(SWServer $server) : void
    {
        $this->events->notify(
            Events\Server::SHUTDOWN,
            (new Connection($this->ctx()))
                ->setEvents($this->events)
                ->setServer($this->genServerOps($server))
                ->setServiced($this->serviced)
                ->setLocal($server->host, $server->port)
        );
    }

    /**
     * @param SWServer $server
     */
    public function evManagerStart(SWServer $server) : void
    {
        @swoole_set_process_name(sprintf('[manager] %s', $this->serviced));
    }

    /**
     * @param SWServer $server
     */
    public function evManagerStop(SWServer $server) : void
    {
        // do nothing
    }

    /**
     * @param SWServer $server
     * @param int $workerID
     */
    public function evWorkerStart(SWServer $server, int $workerID) : void
    {
        @swoole_set_process_name(sprintf('[worker] %s #%d', $this->serviced, $workerID));

        $this->events->notify(
            Events\Worker::STARTED,
            (new Connection($this->ctx()))
                ->setEvents($this->events)
                ->setServer($this->genServerOps($server))
                ->setServiced($this->serviced)
                ->setLocal($server->host, $server->port)
                ->setWorker($workerID)
        );
    }

    /**
     * @param SWServer $server
     * @param int $workerID
     */
    public function evWorkerStop(SWServer $server, int $workerID) : void
    {
        $this->events->notify(
            Events\Worker::STOPPED,
            (new Connection($this->ctx()))
                ->setEvents($this->events)
                ->setServer($this->genServerOps($server))
                ->setServiced($this->serviced)
                ->setLocal($server->host, $server->port)
                ->setWorker($workerID)
        );
    }

    /**
     * @param SWServer $server
     * @param int $workerID
     * @param int $workerPID
     * @param int $exitCode
     * @param int $lxSignal
     */
    public function evWorkerError(SWServer $server, int $workerID, int $workerPID, int $exitCode, int $lxSignal) : void
    {
        $this->events->notify(
            Events\Worker::FAILURE,
            (new Connection($this->ctx()))
                ->setEvents($this->events)
                ->setServer($this->genServerOps($server))
                ->setServiced($this->serviced)
                ->setLocal($server->host, $server->port)
                ->setWorker($workerID)
        );
    }

    /**
     * @param SWServer $sw
     * @return Server
     */
    private function genServerOps(SWServer $sw) : Server
    {
        return new class($sw) implements Server {
            /**
             * @var SWServer
             */
            private $sw = null;

            /**
             * anonymous constructor.
             * @param SWServer $sw
             */
            public function __construct(SWServer $sw)
            {
                $this->sw = $sw;
            }

            /**
             * @return object
             */
            public function raw() : object
            {
                return $this->sw;
            }

            /**
             * @return int
             */
            public function pid() : int
            {
                return $this->sw->master_pid;
            }

            /**
             */
            public function stop() : void
            {
                $this->sw->stop();
            }
        };
    }
}
