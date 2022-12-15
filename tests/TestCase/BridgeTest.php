<?php

namespace CakeDC\Roadrunner\Test\TestCase;

use Cake\Core\Configure;
use Cake\Http\ServerRequest as CakeServerRequest;
use Cake\TestSuite\TestCase;
use CakeDC\Roadrunner\Bridge;
use CakeDC\Roadrunner\Exception\CakeRoadrunnerException;
use CakeDC\Roadrunner\Test\ServerRequestHelper;
use Laminas\Diactoros\ServerRequest as LaminasServerRequest;
use Laminas\Diactoros\ServerRequestFactory as LaminasServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;
use Laminas\Diactoros\Uri;

class BridgeTest extends TestCase
{
    private string $rootDir;

    public function setUp(): void
    {
        parent::setUp(); // TODO: Change the autogenerated stub
        Configure::write('Error', []);
        Configure::write('debug', true);
        Configure::write('App.namespace', 'App\\');
        $this->rootDir = dirname(__DIR__ . '../') . '/test_app';
    }

    public function test_handle(): void
    {
        $request = LaminasServerRequestFactory::fromGlobals(ServerRequestHelper::defaultServerParams());
        $response = (new Bridge($this->rootDir))->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['hello' => 'world'], json_decode((string) $response->getBody(), true));
    }

    public function test_handle_with_trailing_root_directory_slash(): void
    {
        $request = LaminasServerRequestFactory::fromGlobals(ServerRequestHelper::defaultServerParams());
        $response = (new Bridge($this->rootDir . '/'))->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['hello' => 'world'], json_decode((string) $response->getBody(), true));
    }

    public function test_handle_http_write_methods(): void
    {
        foreach (['POST', 'PUT', 'PATCH'] as $method) {
            $data = ['hello' => $method];
            $request = LaminasServerRequestFactory::fromGlobals(
                ServerRequestHelper::defaultServerParams([
                    'CONTENT_TYPE' => 'application/json',
                    'REQUEST_URI' => 'http://localhost:8080/write.json',
                    'REQUEST_METHOD' => $method,
                ])
            );

            $request = $request->withBody((new StreamFactory())->createStream(json_encode($data)));
            $request = $request->withHeader('Content-Type', 'application/json');
            $response = (new Bridge($this->rootDir))->handle($request);
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals($data, json_decode((string) $response->getBody(), true));
        }
    }

    public function test_handle_http_delete_method(): void
    {
        $request = LaminasServerRequestFactory::fromGlobals(
            ServerRequestHelper::defaultServerParams([
                'REQUEST_URI' => 'http://localhost:8080/delete.json',
                'REQUEST_METHOD' => 'DELETE',
            ])
        );

        $response = (new Bridge($this->rootDir))->handle($request);
        $this->assertEquals(204, $response->getStatusCode());
        $this->assertEquals('', (string) $response->getBody());
    }

    public function test_construct_throws_exception_when_root_dir_not_found(): void
    {
        $rootDir = '/dev/null/cakephp-roadrunner-'. md5((string)microtime(true));
        $this->expectException(CakeRoadrunnerException::class);
        $this->expectExceptionMessage(sprintf(CakeRoadrunnerException::ROOT_DIR_NOT_FOUND, $rootDir));
        (new Bridge($rootDir));
    }

    /**
     * @todo this is difficult to test
     * @return void
     */
    public function test_construct_throws_exception_when_application_instance_not_created(): void
    {
        $this->markTestSkipped('@todo: difficult to test');
        $this->expectException(CakeRoadrunnerException::class);
        $this->expectExceptionMessage(CakeRoadrunnerException::APP_INSTANCE_NOT_CREATED);
        (new Bridge($this->rootDir));
    }

    public function test_convert_request_adds_host_header_correctly(): void
    {
        $request = (new LaminasServerRequest())->withUri(new Uri('http://website.com/test.json'));

        $convertedRequest = Bridge::convertRequest($request);

        $this->assertEquals('website.com', $convertedRequest->getHeaderLine('Host'));
    }

    public function test_convert_request_adds_uploaded_files_to_parsed_body(): void
    {
        $stream = (new StreamFactory())->createStream('test contents');
        $request = (new LaminasServerRequest())->withUploadedFiles([
            'uploadedFileField' => new UploadedFile(
                $stream,
                $stream->getSize(),
                UPLOAD_ERR_OK,
                clientFilename: 'test.txt'
            ),
        ]);

        $convertedRequest = Bridge::convertRequest($request);
        $parsedBody = $convertedRequest->getParsedBody();

        $this->assertArrayHasKey('uploadedFileField', $parsedBody);

        $uploadedFile = $parsedBody['uploadedFileField'];
        $this->assertInstanceOf(UploadedFile::class, $uploadedFile);
        $this->assertEquals('test.txt', $uploadedFile->getClientFilename());
    }

    public function test_convert_request_should_not_attempt_to_parse_request_body_when_is_put_with_urlencoded_parameters(): void
    {
        $urlEncodedParameters = [
            'test' => 123,
        ];

        // Roadrunner sends the request body encoded with JSON, even when it was originally parsed
        // from a `application/x-www-form-urlencoded` request
        $stream = (new StreamFactory())->createStream(json_encode($urlEncodedParameters));
        $request = (new LaminasServerRequest())
            ->withMethod('PUT')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8')
            ->withBody($stream)
            ->withParsedBody($urlEncodedParameters);

        $convertedRequest = Bridge::convertRequest($request);
        $parsedBody = $convertedRequest->getParsedBody();

        $this->assertEquals($urlEncodedParameters, $parsedBody);
    }

    public function test_convert_request_should_keep_headers_as_list_when_request_has_duplicated_headers(): void
    {
        $expectedHeaderValues = ['123', '456'];

        // A request que vem do Roadrunner contém tanto o header nos server params (que representa
        // os conteudos da global $_SERVER), quanto os headers adicionados pelo `withHeader`/`withAddedHeader`
        $serverParams = [
            'HTTP_X_TEST_HEADER' => '123, 456',
        ];
        $request = (new LaminasServerRequest($serverParams))
            ->withHeader('X-Test-Header', '123')
            ->withAddedHeader('X-Test-Header', '456');

        $convertedRequest = Bridge::convertRequest($request);

        $this->assertEquals($expectedHeaderValues, $convertedRequest->getHeader('X-Test-Header'));
    }

    public function test_convert_request_should_keep_environment_values_as_string_when_header_is_present_only_one_time(): void {
        $expectedEnvironmentValue = '192.168.0.1';

        $serverParams = [
            'HTTP_X_REAL_IP' => '192.168.0.1',
        ];
        $request = (new LaminasServerRequest($serverParams))
            ->withHeader('X-Real-IP', '192.168.0.1');

        $convertedRequest = Bridge::convertRequest($request);

        $this->assertEquals($expectedEnvironmentValue, $convertedRequest->getEnv('HTTP_X_REAL_IP'));
    }

    public function test_convert_request_should_keep_uri_parameters(): void
    {
        $serverParams = [
            'REQUEST_URI' => 'http://localhost/test?parameter=1',
        ];

        $requestUri = new Uri('http://localhost/test?parameter=1');
        $request = (new LaminasServerRequest($serverParams))
            ->withUri($requestUri);

        $convertedRequest = Bridge::convertRequest($request);

        $this->assertEquals('http', $convertedRequest->getUri()->getScheme());
        $this->assertEquals('localhost', $convertedRequest->getUri()->getHost());
        $this->assertEquals('parameter=1', $convertedRequest->getUri()->getQuery());
        $this->assertEquals('/test?parameter=1', $convertedRequest->getRequestTarget());
    }

    /** @dataProvider provide_test_values_for_basic_authentication_test */
    public function test_convert_request_should_parse_basic_authorization_into_php_environment_variables(
        string $username,
        string $password,
    ): void {
        $encodedBasicParameter = base64_encode("$username:$password");
        $headerValue = "Basic $encodedBasicParameter";

        $request = (new LaminasServerRequest())
            ->withHeader('Authorization', $headerValue);

        $convertedRequest = Bridge::convertRequest($request);

        $this->assertEquals($username, $convertedRequest->getEnv('PHP_AUTH_USER'));
        $this->assertEquals($password, $convertedRequest->getEnv('PHP_AUTH_PW'));
    }

    public function provide_test_values_for_basic_authentication_test(): iterable
    {
        yield 'with username and empty password' => ['test_username', ''];
        yield 'with username and non-empty password' => ['test_username', 'test_password'];
    }
}
