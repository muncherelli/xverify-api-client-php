<?php

namespace Muncherelli\XverifyAPIClient;

use GuzzleHttp\Client;

class XverifyAPIClient {

    private Client $client; // the guzzle client

    public function __construct(
        private string  $apiKey, 
        private string  $domain,
        array           $configOptions = [],
        private string  $baseUri = 'https://api.xverify.com/v2/',
    ) {
        // other guzzle client configuration options can be passed in as an array, overriding the default
        $defaultConfig = [
            'base_uri'  => $this->baseUri,
            'timeout'   => 5,
            'headers'   => [
                'Content-Type' => 'application/json'
            ]
        ];
        // use the merged defaultConfig with the custom configOptions to instantiate the client
        $this->client = new Client(array_merge($defaultConfig, $configOptions));
    }

    public function verifyEmail(string $email = '', array $options = []): array {
        $response = $this->makeRequest(endpoint: 'ev', params: ['email' => $email] + $options);
        return $this->formatResponse(response: $response);
    }

    public function verifyPhone(string $phone = '', array $options = []): array {
        $response = $this->makeRequest(endpoint: 'pv', params: ['phone' => $phone] + $options);
        return $this->formatResponse(response: $response);
    }

    public function verifyAddress(array $params = []): array {
        $params['api_key'] = $this->apiKey;
        $params['domain'] = $this->domain;

        $response = $this->makeRequest(endpoint: 'av', params: $params);
        return $this->formatResponse(response: $response);
    }

    public function verifyCombined(array $params = []): array {
        $response = $this->makeRequest(endpoint: 'aio', params: $params);
        return $this->formatResponse(response: $response);
    }

    private function makeRequest(string $endpoint, array $params): array {
        $params = array_merge($params, [
            'api_key' => $this->apiKey,
            'domain' => $this->domain
        ]);

        try {
            $response = $this->client->get(uri: $endpoint, options: ['query' => $params, 'http_errors' => false]); // Keeping 'http_errors' => false

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                return [
                    'error' => $this->getStatusMessage($statusCode),  // Use our custom status messages
                    'statusCode' => $statusCode
                ];
            }

            return ['data' => json_decode($response->getBody(), true)];
        } catch (ConnectException $e) {
            return $this->handleConnectException(exception: $e);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'statusCode' => 0];
        }
    }

    private function handleConnectException(ConnectException $exception): array {
        return ['error' => 'Connection timeout or network issue.', 'statusCode' => 0];
    }

    private function handleRequestException(RequestException $exception): array {
        if ($exception->hasResponse()) {
            $response = $exception->getResponse();
            $statusCode = $response->getStatusCode();
            $reason = $response->getReasonPhrase();
            
            return [
                'error' => $reason,
                'statusCode' => $statusCode
            ];
        }
        
        // If no response is available, return a generic error
        return ['error' => 'Unknown error', 'statusCode' => 0];
    }

    // recursively filter out any empty fields from the response (or any nested arrays)
    private function filterEmptyFields(array $data): array {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->filterEmptyFields($value);
            }
            if (empty($data[$key])) {
                unset($data[$key]);
            }
        }
        return $data;
    }

    private function formatResponse(array $response): array {
        if (isset($response['data'])) {
            $decodedData = $response['data'];
            return is_array($decodedData) ? $decodedData : [];
        } elseif (isset($response['error'])) {
            return [
                'error' => $response['error'],
                'statusCode' => $response['statusCode']
            ];
        }
        return ['error' => 'Unknown error', 'statusCode' => 0];
    }

    // return a status message based on the http status code
    private function getStatusMessage(int $statusCode): string {
        $messages = [
            200 => 'API OK',
            400 => 'A parameter was missing or has an invalid value',
            401 => 'Unauthorized. Either the apiKey was missing or invalid, or you are trying to use a service you are not configured for.',
            403 => 'Forbidden. Your query limit has been exceeded.',
            500 => 'Internal server error. Please contact support.',
            502 => 'Bad gateway. The API is not available. Please try again later or contact support.'
        ];

        return $messages[$statusCode] ?? 'An unknown error occurred.';
    }

}
