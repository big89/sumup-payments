<?php

namespace SumUpWhmcs;

/**
 * Minimal HTTP transport abstraction so the API client can be tested
 * without network access.
 */
interface HttpTransport
{
    /**
     * @param string               $method  HTTP method.
     * @param string               $url     Absolute URL.
     * @param array<string,string> $headers Request headers.
     * @param string|null          $body    Raw request body.
     *
     * @return array{0:int,1:string} HTTP status code and raw response body.
     */
    public function send($method, $url, array $headers, $body = null);
}

class CurlTransport implements HttpTransport
{
    private $timeout;

    public function __construct($timeout = 30)
    {
        $this->timeout = $timeout;
    }

    public function send($method, $url, array $headers, $body = null)
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ApiException('Could not connect to SumUp: ' . $error, 0, '');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, (string) $response];
    }
}

class ApiException extends \RuntimeException
{
    private $httpStatus;
    private $responseBody;

    public function __construct($message, $httpStatus, $responseBody)
    {
        parent::__construct($message, (int) $httpStatus);
        $this->httpStatus = (int) $httpStatus;
        $this->responseBody = (string) $responseBody;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    public function getResponseBody()
    {
        return $this->responseBody;
    }
}
