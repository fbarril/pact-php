<?php

namespace PhpPact\Consumer\Listener;

use Amp\Process\ProcessException;
use Exception;
use GuzzleHttp\Psr7\Uri;
use JsonException;
use PhpPact\Broker\Service\BrokerHttpClient;
use PhpPact\Http\GuzzleClient;
use PhpPact\Standalone\Exception\MissingEnvVariableException;
use PhpPact\Standalone\MockService\MockServer;
use PhpPact\Standalone\MockService\MockServerEnvConfig;
use PhpPact\Standalone\MockService\Service\MockServerHttpService;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use PHPUnit\Framework\TestSuite;
use Throwable;

use function getenv;
use function in_array;

/**
 * PACT listener that can be used with environment variables and easily attached to PHPUnit configuration.
 */
class PactTestListener implements TestListener
{
    use TestListenerDefaultImplementation;

    private MockServer $server;
    /**
     * Name of the test suite configured in your phpunit config.
     *
     * @var array<int, string>
     */
    private array $testSuiteNames = [];

    private MockServerEnvConfig $mockServerConfig;

    private bool $failed = false;

    /**
     * @param array<int, string> $testSuiteNames test suite names that need evaluated with the listener
     *
     * @throws MissingEnvVariableException
     */
    public function __construct(array $testSuiteNames)
    {
        $this->testSuiteNames   = $testSuiteNames;
        $this->mockServerConfig = new MockServerEnvConfig();
    }

    /**
     * @throws Exception
     */
    public function startTestSuite(TestSuite $suite): void
    {
        if (in_array($suite->getName(), $this->testSuiteNames)) {
            $this->server = new MockServer($this->mockServerConfig);
            $this->server->start();
        }
    }

    public function addError(Test $test, Throwable $t, float $time): void
    {
        $this->failed = true;
    }

    public function addFailure(Test $test, AssertionFailedError $e, float $time): void
    {
        $this->failed = true;
    }

    /**
     * Publish JSON results to PACT Broker and stop the Mock Server.
     * @throws JsonException|ProcessException
     */
    public function endTestSuite(TestSuite $suite): void
    {
        if (in_array($suite->getName(), $this->testSuiteNames)) {
            try {
                $httpService = new MockServerHttpService(new GuzzleClient(), $this->mockServerConfig);
                $httpService->verifyInteractions();

                $json = $httpService->getPactJson();
            } finally {
                $this->server->stop();
            }

            if ($this->failed === true) {
                print 'A unit test has failed. Skipping PACT file upload.';
            } elseif (!($pactBrokerUri = getenv('PACT_BROKER_URI'))) {
                print 'PACT_BROKER_URI environment variable was not set. Skipping PACT file upload.';
            } elseif (!($consumerVersion = getenv('PACT_CONSUMER_VERSION'))) {
                print 'PACT_CONSUMER_VERSION environment variable was not set. Skipping PACT file upload.';
            } elseif (!($tag = getenv('PACT_CONSUMER_TAG'))) {
                print 'PACT_CONSUMER_TAG environment variable was not set. Skipping PACT file upload.';
            } else {
                $clientConfig = [];
                if (($user = getenv('PACT_BROKER_HTTP_AUTH_USER')) &&
                    ($pass = getenv('PACT_BROKER_HTTP_AUTH_PASS'))
                ) {
                    $clientConfig = [
                        'auth' => [$user, $pass],
                    ];
                }

                if (($sslVerify = getenv('PACT_BROKER_SSL_VERIFY'))) {
                    $clientConfig['verify'] = $sslVerify !== 'no';
                }

                $headers = [];
                if ($bearerToken = getenv('PACT_BROKER_BEARER_TOKEN')) {
                    $headers['Authorization'] = 'Bearer ' . $bearerToken;
                }

                $client = new GuzzleClient($clientConfig);

                $brokerHttpService = new BrokerHttpClient($client, new Uri($pactBrokerUri), $headers);
                $brokerHttpService->tag($this->mockServerConfig->getConsumer(), $consumerVersion, $tag);
                $brokerHttpService->publishJson($consumerVersion, $json);
                print 'Pact file has been uploaded to the Broker successfully.';
            }
        }
    }
}
