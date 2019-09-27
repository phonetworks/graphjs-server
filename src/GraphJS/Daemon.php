<?php

/*
 * This file is part of the Pho package.
 *
 * (c) Emre Sokullu <emre@phonetworks.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GraphJS;

use Pho\Kernel\Kernel;
use PhoNetworksAutogenerated\{User, Site, Network};
use React\EventLoop\LoopInterface;
use Pho\Plugins\FeedPlugin;
use Sikei\React\Http\Middleware\CorsMiddleware;
use Pho\Lib\DHT\DummyPeer;

/**
 * The async/event-driven REST server daemon
 * 
 * @author Emre Sokullu <emre@phonetworks.org>
 */
class Daemon implements \Pho\Lib\DHT\PeerInterface
{

    use AutoloadingTrait;

    const BUCKET_SIZE = 1000;

    protected $heroku = false;
    protected $kernel;
    protected $server;
    protected $loop;

    /**
     * Pho-Lib-DHT related params go below:
     */
    protected $ip;
    protected $port;
    protected $id;
    protected $router;
    
    public function __construct(string $configs = "", string $cors = "", bool $heroku = false, ?LoopInterface &$loop = null)
    {
        if(!isset($loop)) {
            $loop = \React\EventLoop\Factory::create();    
        }
        $this->loop = &$loop;
        $this->heroku = $heroku;
        $this->loadEnvVars($configs);
        $cors .= sprintf(";%s", getenv("CORS_DOMAIN"));
        $this->initKernel();
        $this->server = new Server($this->kernel, $this->loop);
        // won't bootstrap() to skip Pho routes.
        $controller_dir = __DIR__ . DIRECTORY_SEPARATOR . "Controllers";
        $this->server->withControllers($controller_dir);
        $router_dir = __DIR__ . DIRECTORY_SEPARATOR . "Routes";
        $this->server->withRoutes($router_dir);
        $this->addCorsSupport();
        $this->server->bootstrap();
        $this->computeDHTParams(); // maybe do this every X minutes.
        //$this->server->disableRoutesExcept([]);
    }

    /**
     * {@inheritDoc}
     */
    public function ip(): string
    {
        return $this->ip;
    }

    /**
     * {@inheritDoc}
     */
    public function port(): int
    {
        return $this->server->port();
    }

    /**
     * {@inheritDoc}
     */
    public function id(): string
    {
        return $this->id;
    }

    public function router()
    {
        return $this->router;
    }


    public function __call(string $method, array $params)//: mixed
    {
        return $this->server->$method(...$params);
    }

    protected function addCorsSupport(): void
    {
        $origins = ["*"];
        $is_production = (null==getenv("IS_PRODUCTION") || getenv("IS_PRODUCTION") === "false") ? false : (bool) getenv("IS_PRODUCTION");
        $env = getenv("CORS_DOMAIN");
        if($is_production && isset($env)&&!empty($env)) 
        {
            $origins = Utils::expandCorsUrl($env);
        }
        $this->server->withMiddleware(
            new CorsMiddleware(
                [
                    'allow_credentials' => true,
                    'allow_origin'      => $origins,
                    'allow_methods'     => ['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH'],
                    'allow_headers'     => ['DNT','X-Custom-Header','Keep-Alive','User-Agent','X-Requested-With','If-Modified-Since','Cache-Control','Content-Type','Content-Range','Range', 'Origin', 'Accept', 'Authorization'],
                    'expose_headers'    => ['DNT','X-Custom-Header','Keep-Alive','User-Agent','X-Requested-With','If-Modified-Since','Cache-Control','Content-Type','Content-Range','Range', 'Origin', 'Accept', 'Authorization'],
                    'max_age'           => 60 * 60 * 24 * 20, // preflight request is valid for 20 days
                ]
            )
        );
    }

    protected function initKernel(): void
    {
        $configs = array(
            "services"=>array(
                "database" => ["type" => getenv('DATABASE_TYPE'), "uri" => getenv('DATABASE_URI')],
                "storage" => ["type" => getenv('STORAGE_TYPE'), "uri" =>  getenv("STORAGE_URI")],
                "index" => ["type" => getenv('INDEX_TYPE'), "uri" => getenv('INDEX_URI')]
            ),
            "default_objects" => array(
                    "graph" => getenv('INSTALLATION_TYPE') === 'groupsv2' ? Network::class : Site::class,
                    "founder" => User::class,
                    "actor" => User::class
            )
        );
        $this->kernel = new Kernel($configs);
        if(!empty(getenv("STREAM_KEY"))&&!empty(getenv("STREAM_SECRET"))) {
            $feedplugin = new FeedPlugin($this->kernel,  getenv('STREAM_KEY'),  getenv('STREAM_SECRET'));
            $this->kernel->registerPlugin($feedplugin);
        }
        $founder = new User(
            $this->kernel, $this->kernel->space(), 
            getenv('FOUNDER_NICKNAME'), 
            getenv('FOUNDER_EMAIL'), 
            getenv('FOUNDER_PASSWORD')
        );
        $this->kernel->boot($founder);
        //eval(\Psy\sh());
    }

    /**
     * Computes DHT related params
     * 
     * All except Port, because port is set in 
     * Pho-Server-REST Server layer.
     *
     * @return void
     */
    private function computeDHTParams(): void
    {
        $uuid = getenv("UUID");
        $this->id = (string) $uuid;
        $this->ip = Utils::getIp();
        $router = new \Pho\Lib\DHT\Router($this, [/*
            new DummyPeer("http://accounts-dev.graphjs.com", 1338, "16D58CF2FD884A49972B6F60054BF023"),
            new DummyPeer("http://accounts-dev.graphjs.com", 1339, "07660876C7E144A486C3754799733FF0")
        */], ["debug"=>true, "kbucket_size"=>static::BUCKET_SIZE]);
        $router->bootstrap();
        $this->router = $router;
        $GLOBALS["router"] = $router;
        //eval(\Psy\sh());
    }

}

