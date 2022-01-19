<?php
declare(strict_types=1);

namespace CakeDC\Roadrunner;

use Cake\Core\HttpApplicationInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Http\MiddlewareQueue;
use Cake\Http\Runner;
use Cake\Http\Server;
use Cake\Http\ServerRequestFactory;
use CakeDC\Roadrunner\Exception\CakeRoadrunnerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The CakePHP RoadRunner Bridge converts a request to a PSR response suitable for the RoadRunner server. This should
 * be called from your worker file.
 *
 * Example:
 *
 * ```php
 * # worker/cakephp-worker.php
 * $bridge = new Bridge(__DIR__);
 * ```
 *
 * When a request is ready to be handled in your worker:
 *
 * ```php
 * return $bridge->handle($request);
 * ```
 *
 * @link https://roadrunner.dev/docs/php-worker
 */
class Bridge
{
    /**
     * @param string $rootDir Absolute path to your applications root directory without the trailing slash. For example,
     *      if your `composer.json` file is located at `/srv/app/composer.json` then `/srv/app` is your $rootDir.
     * @param \Cake\Core\HttpApplicationInterface|\Cake\Core\PluginApplicationInterface|null $application Application
     * (e.g. `\App\Application`), if null then the constructor will attempt creating an instance.
     * @param \Cake\Http\Server|null $server Server instance, if null then one will be created for you.
     */
    public function __construct(
        private string $rootDir,
        private HttpApplicationInterface|PluginApplicationInterface|null $application = null,
        private ?Server $server = null
    ) {
        if (str_ends_with($this->rootDir, '/')) {
            $this->rootDir = substr($this->rootDir, 0, -1);

        }
        if (!file_exists($this->rootDir)) {
            throw new CakeRoadrunnerException(
                sprintf(
                    CakeRoadrunnerException::ROOT_DIR_NOT_FOUND,
                    $this->rootDir
                )
            );
        }

        $configDir = "$this->rootDir/config";

        if ($this->application == null && class_exists('\App\Application')) {
            $this->application = new \App\Application($configDir);
        } else {
            throw new CakeRoadrunnerException(CakeRoadrunnerException::APP_INSTANCE_NOT_CREATED);
        }

        $this->server = $server ?? new Server($this->application);

        require $configDir . '/requirements.php';
        $this->application->bootstrap();
        if ($this->application instanceof PluginApplicationInterface) {
            $this->application->pluginBootstrap();
        }
    }

    /**
     * Handle the request and return a response.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request PSR Server Request Interface
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $request = $this->convertRequest($request);
        $middleware = $this->application->middleware(new MiddlewareQueue());
        if ($this->application instanceof PluginApplicationInterface) {
            $middleware = $this->application->pluginMiddleware($middleware);
        }

        $this->server->dispatchEvent('Server.buildMiddleware', ['middleware' => $middleware]);
        //$middleware->add($this->application);
        /** @var \Cake\Http\Response $response */
        $response = (new Runner())->run($middleware, $request, $this->application);

        $cookies = [];
        foreach ($response->getCookieCollection() as $cookie) {
            if (!$cookie->getExpiresTimestamp()) {
                $cookie = $cookie->withNeverExpire();
            }
            $cookies[] = $cookie->toHeaderValue();
        }
        if (!empty($cookies)) {
            $response = $response->withHeader('Set-Cookie', $cookies);
        }

        session_write_close();

        return $response;
    }

    /**
     * @todo needs documentation
     * @param \Psr\Http\Message\ServerRequestInterface $request An instance of ServerRequestInterface
     * @return \Psr\Http\Message\ServerRequestInterface
     */
    private function convertRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        // debug code for analyzing request
        /*
        ob_start();
        var_dump($request);
        $out = ob_get_contents();
        file_put_contents('/tmp/request.txt', $out);
        ob_end_clean();
        */
        $server = $request->getServerParams();
        $server['REQUEST_TIME'] = time();
        $server['REQUEST_TIME_FLOAT'] = microtime(true);
        $server['REMOTE_ADDR'] = '127.0.0.1';
        $server['REQUEST_METHOD'] = $request->getMethod();
        $server['REQUEST_URI'] = $request->getUri()->getPath();
        $server['SERVER_PROTOCOL'] = $request->getUri()->getScheme();
        $server['SERVER_NAME'] = $request->getUri()->getHost();
        $server['SERVER_PORT'] = $request->getUri()->getPort();

        $query = $request->getQueryParams();
        $body = $request->getParsedBody();
        $cookies = $request->getCookieParams();
        $files = $request->getUploadedFiles();

        $cakeRequest = ServerRequestFactory::fromGlobals($server, $query, $body, $cookies, $files);
        $cakeRequest->trustProxy = true;

        return $cakeRequest;
    }
}
