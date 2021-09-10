<?php

namespace Runtime\Bref;

use Bref\Context\Context;
use Bref\Context\ContextBuilder;
use Bref\Event\Handler;
use Symfony\Component\Runtime\RunnerInterface;
use Exception;

/**
 * This will run BrefHandlers.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class BrefRunner implements RunnerInterface
{
    private $application;
    private $loopMax;

    /** @var resource|null */
    private $handler;

    /** @var resource|null */
    private $returnHandler;

    /** @var string */
    private $apiUrl;

    public function __construct(Handler $application, int $loopMax)
    {
        $this->application = $application;
        $this->loopMax = $loopMax;
        $this->apiUrl = (string) getenv('AWS_LAMBDA_RUNTIME_API');
    }

    public function run(): int
    {
        $loops = 0;
        while (true) {
            if (++$loops > $this->loopMax) {
                return 0;
            }
            try {
                $this->processNextEvent($this->application);
            } catch (\Throwable $e) {
                return 1;
            }
        }
    }

    public function __destruct()
    {
        $this->closeHandler();
        $this->closeReturnHandler();
    }

    private function closeHandler(): void
    {
        if ($this->handler !== null) {
            curl_close($this->handler);
            $this->handler = null;
        }
    }

    private function closeReturnHandler(): void
    {
        if ($this->returnHandler !== null) {
            curl_close($this->returnHandler);
            $this->returnHandler = null;
        }
    }

    /**
     * Process the next event.
     *
     * @param Handler $handler If it is a callable, it takes two parameters, an $event parameter (mixed) and a $context parameter (Context) and must return anything serializable to JSON.
     *
     * Example:
     *
     *     $lambdaRuntime->processNextEvent(function ($event, Context $context) {
     *         return 'Hello ' . $event['name'] . '. We have ' . $context->getRemainingTimeInMillis()/1000 . ' seconds left';
     *     });
     * @throws Exception
     */
    public function processNextEvent(Handler $handler): void
    {
        [$event, $context] = $this->waitNextInvocation();
        \assert($context instanceof Context);

        try {
            $result = $handler->handle($event, $context);

            $this->sendResponse($context->getAwsRequestId(), $result);
        } catch (\Throwable $e) {
            $this->signalFailure($context->getAwsRequestId(), $e);

            throw $e;
        }
    }

    /**
     * Wait for the next lambda invocation and retrieve its data.
     *
     * This call is blocking because the Lambda runtime API is blocking.
     *
     * @see https://docs.aws.amazon.com/lambda/latest/dg/runtimes-api.html#runtimes-api-next
     */
    private function waitNextInvocation(): array
    {
        if ($this->handler === null) {
            $this->handler = curl_init("http://{$this->apiUrl}/2018-06-01/runtime/invocation/next");
            curl_setopt($this->handler, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($this->handler, CURLOPT_FAILONERROR, true);
        }

        // Retrieve invocation ID
        $contextBuilder = new ContextBuilder;
        curl_setopt($this->handler, CURLOPT_HEADERFUNCTION, function ($ch, $header) use ($contextBuilder) {
            if (! preg_match('/:\s*/', $header)) {
                return strlen($header);
            }
            [$name, $value] = preg_split('/:\s*/', $header, 2);
            $name = strtolower($name);
            $value = trim($value);
            if ($name === 'lambda-runtime-aws-request-id') {
                $contextBuilder->setAwsRequestId($value);
            }
            if ($name === 'lambda-runtime-deadline-ms') {
                $contextBuilder->setDeadlineMs(intval($value));
            }
            if ($name === 'lambda-runtime-invoked-function-arn') {
                $contextBuilder->setInvokedFunctionArn($value);
            }
            if ($name === 'lambda-runtime-trace-id') {
                $contextBuilder->setTraceId($value);
            }

            return strlen($header);
        });

        // Retrieve body
        $body = '';
        curl_setopt($this->handler, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$body) {
            $body .= $chunk;

            return strlen($chunk);
        });

        curl_exec($this->handler);
        if (curl_errno($this->handler) > 0) {
            $message = curl_error($this->handler);
            $this->closeHandler();
            throw new Exception('Failed to fetch next Lambda invocation: ' . $message);
        }
        if ($body === '') {
            throw new Exception('Empty Lambda runtime API response');
        }

        $context = $contextBuilder->buildContext();

        if ($context->getAwsRequestId() === '') {
            throw new Exception('Failed to determine the Lambda invocation ID');
        }

        $event = json_decode($body, true);

        return [$event, $context];
    }

    /**
     * @param mixed $responseData
     *
     * @see https://docs.aws.amazon.com/lambda/latest/dg/runtimes-api.html#runtimes-api-response
     */
    private function sendResponse(string $invocationId, $responseData): void
    {
        $url = "http://{$this->apiUrl}/2018-06-01/runtime/invocation/$invocationId/response";
        $this->postJson($url, $responseData);
    }

    /**
     * @see https://docs.aws.amazon.com/lambda/latest/dg/runtimes-api.html#runtimes-api-invokeerror
     */
    private function signalFailure(string $invocationId, \Throwable $error): void
    {
        $stackTraceAsArray = explode(PHP_EOL, $error->getTraceAsString());
        $errorFormatted = [
            'errorType' => get_class($error),
            'errorMessage' => $error->getMessage(),
            'stack' => $stackTraceAsArray,
        ];

        if ($error->getPrevious() !== null) {
            $previousError = $error;
            $previousErrors = [];
            do {
                $previousError = $previousError->getPrevious();
                $previousErrors[] = [
                    'errorType' => get_class($previousError),
                    'errorMessage' => $previousError->getMessage(),
                    'stack' => explode(PHP_EOL, $previousError->getTraceAsString()),
                ];
            } while ($previousError->getPrevious() !== null);

            $errorFormatted['previous'] = $previousErrors;
        }

        // Log the exception in CloudWatch
        // We aim to use the same log format as what we can see when throwing an exception in the NodeJS runtime
        // See https://github.com/brefphp/bref/pull/579
        error_log($invocationId . "\tInvoke Error\t" . json_encode($errorFormatted) . PHP_EOL);

        // Send an "error" Lambda response
        $url = "http://{$this->apiUrl}/2018-06-01/runtime/invocation/$invocationId/error";
        $this->postJson($url, [
            'errorType' => get_class($error),
            'errorMessage' => $error->getMessage(),
            'stackTrace' => $stackTraceAsArray,
        ]);
    }

    /**
     * @param mixed $data
     */
    private function postJson(string $url, $data): void
    {
        $jsonData = json_encode($data);
        if ($jsonData === false) {
            throw new Exception(sprintf(
                "The Lambda response cannot be encoded to JSON.\nThis error usually happens when you try to return binary content. If you are writing an HTTP application and you want to return a binary HTTP response (like an image, a PDF, etc.), please read this guide: https://bref.sh/docs/runtimes/http.html#binary-responses\nHere is the original JSON error: '%s'",
                json_last_error_msg()
            ));
        }

        if ($this->returnHandler === null) {
            $this->returnHandler = curl_init();
            curl_setopt($this->returnHandler, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($this->returnHandler, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->returnHandler, CURLOPT_FAILONERROR, true);
        }

        curl_setopt($this->returnHandler, CURLOPT_URL, $url);
        curl_setopt($this->returnHandler, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($this->returnHandler, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData),
        ]);
        curl_exec($this->returnHandler);
        if (curl_errno($this->returnHandler) > 0) {
            $errorMessage = curl_error($this->returnHandler);
            $this->closeReturnHandler();
            throw new Exception('Error while calling the Lambda runtime API: ' . $errorMessage);
        }
    }
}
