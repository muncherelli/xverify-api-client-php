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
        if (empty($email)) {
            return [
                'status' => 'error',
                'message' => $this->getStatusMessage(400),
                'status_code' => 400
            ];
        }
        
        $response = $this->makeRequest(endpoint: 'ev', params: ['email' => $email] + $options);
        return $this->formatResponse(response: $response);
    }

    public function verifyPhone(string $phone = '', array $options = []): array {
        if (empty($phone)) {
            return [
                'status' => 'error',
                'message' => $this->getStatusMessage(400),
                'status_code' => 400
            ];
        }
        
        $response = $this->makeRequest(endpoint: 'pv', params: ['phone' => $phone] + $options);
        return $this->formatResponse(response: $response);
    }

    public function verifyAddress(array $params = []): array {
        // required parameters
        if (empty($params['address1']) || (empty($params['city']) && empty($params['zip']))) {
            return [
                'status' => 'error',
                'message' => $this->getStatusMessage(400),
                'status_code' => 400
            ];
        }
        
        // add the API key and domain to the parameters
        $params['api_key'] = $this->apiKey;
        $params['domain'] = $this->domain;

        $response = $this->makeRequest(endpoint: 'av', params: $params);
        return $this->formatResponse(response: $response);
    }

    public function verifyCombined(array $params = []): array {
        if (empty($params['email']) && empty($params['phone']) && empty($params['address1'])) {
            return [
                'status' => 'error',
                'message' => $this->getStatusMessage(400),
                'status_code' => 400
            ];
        }

        $response = $this->makeRequest(endpoint: 'aio', params: $params);
        return $this->formatResponse(response: $response);
    }


    private function makeRequest(string $endpoint, array $params): array {
        // merge the API key and domain into the request params
        $params = array_merge($params, [
            'api_key' => $this->apiKey,
            'domain' => $this->domain
        ]);
        // try to make the request, and handle any exceptions
        try {
            $response = $this->client->get(uri: $endpoint, options: ['query' => $params]);
            return ['data' => json_decode($response->getBody(), true)];
        } catch (ConnectException $e) {
            return $this->handleConnectException(exception: $e);
        } catch (RequestException $e) {
            return $this->handleRequestException(exception: $e);
        } catch (\Exception $e) {
            // catch any other exceptions that may occur and return them as an error
            return ['error' => $e->getMessage(), 'statusCode' => 0];
        }
    }

    private function handleConnectException(ConnectException $exception): array {
        return ['error' => 'Connection timeout or network issue.', 'statusCode' => 0];
    }

    private function handleRequestException(RequestException $exception): array {
        // if the exception has a response, we can get the status code and error message from it
        // otherwise, just return a generic error message
        $statusCode = $exception->getResponse() ? $exception->getResponse()->getStatusCode() : 0;
        $error = $exception->hasResponse() ? (string) $exception->getResponse()->getBody() : 'Unknown error';
        return ['error' => $error, 'statusCode' => $statusCode];
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

    // format the response to match the expected format
    private function formatResponse(array $response): array {
        $data = $response['data'] ?? []; // the api's response data
        $statusCode = $response['statusCode'] ?? 200; // the http status code

        // if the response data is empty, return a generic error message
        if (empty($data)) {
            $result = array_merge($data, [
                'message' => $this->getStatusMessage(statusCode: $statusCode),
                'status_code' => $statusCode,
                'status' => 'error'
            ]);
        } else {
            // Return the API's response combined with the custom fields
            $result = array_merge($data, [
                'message' => $this->getStatusMessage(statusCode: $statusCode),
                'status_code' => $statusCode
            ]);
        }
        // filter out any empty fields from the response
        return $this->filterEmptyFields($result);
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
