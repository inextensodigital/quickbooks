<?php

namespace ActiveCollab\Quickbooks;

use ActiveCollab\Quickbooks\Data\Entity;
use ActiveCollab\Quickbooks\Data\QueryResponse;
use ActiveCollab\Quickbooks\Exception\FaultException;
use ActiveCollab\Quickbooks\Quickbooks;
use DateTime;
use Guzzle\Http\Exception\BadResponseException;
use Guzzle\Service\Client as GuzzleClient;
use InvalidArgumentException;
use League\OAuth1\Client\Credentials\ClientCredentials;
use League\OAuth1\Client\Credentials\TokenCredentials;

class DataService
{
    const API_VERSION = 3;

    /**
     * @var string
     */
    protected $consumer_key, $consumer_key_secret, $access_token, $access_token_secret, $realmId;

    /**
     * @var string|null
     */
    protected $user_agent = null;

    /**
     * @var string
     */
    protected $entity = '';

    /**
     * Construct data service
     *
     * @param string $consumer_key
     * @param string $consumer_key_secret
     * @param string $access_token
     * @param string $access_token_secret
     * @param string $realmId
     */
    public function __construct($consumer_key, $consumer_key_secret, $access_token, $access_token_secret, $realmId)
    {
        $this->consumer_key = $consumer_key;
        $this->consumer_key_secret = $consumer_key_secret;
        $this->access_token = $access_token;
        $this->access_token_secret = $access_token_secret;
        $this->realmId = $realmId;
    }

    /**
     * Return api url
     *
     * @return string
     */
    public function getApiUrl()
    {
        return 'https://quickbooks.api.intuit.com/v'.self::API_VERSION;
    }

    /**
     * Return http client
     *
     * @return GuzzleClient
     */
    public function createHttpClient()
    {
        return new GuzzleClient();
    }

    /**
     * Return oauth server
     *
     * @return Quickbooks
     */
    public function createServer()
    {
        $client_credentials = new ClientCredentials();
        $client_credentials->setIdentifier($this->consumer_key);
        $client_credentials->setSecret($this->consumer_key_secret);

        return new Quickbooks($client_credentials);
    }

    /**
     * Return token credentials
     *
     * @return TokenCredentials
     */
    public function getTokenCredentials()
    {
        $tokenCredentials = new TokenCredentials();
        $tokenCredentials->setIdentifier($this->access_token);
        $tokenCredentials->setSecret($this->access_token_secret);

        return $tokenCredentials;
    }

    /**
     * Set user agent
     *
     * @param string|null $user_agent
     */
    public function setUserAgent($user_agent = null)
    {
        $this->user_agent = $user_agent;

        return $this;
    }

    /**
     * Return user agent
     *
     * @return string
     */
    public function getUserAgent()
    {
        return $this->user_agent;
    }

    /**
     * Set entity
     *
     * @param string $entity
     */
    public function setEntity($entity)
    {
        $this->entity = $entity;

        return $this;
    }

    /**
     * @return string
     */
    protected function getBaseUrl()
    {
        return $this->getApiUrl() . '/company/' . $this->realmId .  '/';
    }

    /**
     * Return entity $path
     *
     * @return string
     */
    public function getRequestUrl($path)
    {
        return $this->getBaseUrl() . $path;
    }

    /**
     * Return entity url
     *
     * @return string
     */
    public function getEntityRequestUrl($slug)
    {
        return $this->getBaseUrl() . strtolower($slug);
    }

    /**
     * Send create request
     *
     * @param  array            $payload
     * @return Entity
     */
    public function create(array $payload)
    {
        $response = $this->request('POST', $this->getEntityRequestUrl($this->entity), $payload);

        return new Entity($response[$this->entity]);
    }

    /**
     * Send read request
     *
     * @param  int              $id
     * @return Entity
     */
    public function read($id)
    {
        $uri = $this->getEntityRequestUrl($this->entity) . '/' . $id;

        $response = $this->request('GET', $uri);

        return new Entity($response[$this->entity]);
    }

    /**
     * Send update request
     *
     * @param  array            $payload
     * @return Entity
     */
    public function update(array $payload)
    {
        $uri = $this->getEntityRequestUrl($this->entity) . '?operation=update';

        $response = $this->request('POST', $uri, $payload);

        return new Entity($response[$this->entity]);
    }

    /**
     * Send delete request
     *
     * @param  array            $payload
     * @return null
     */
    public function delete(array $payload)
    {
        $uri = $this->getEntityRequestUrl($this->entity) . '?operation=delete';

        $this->request('POST', $uri, $payload);

        return null;
    }

    /**
     * Send query request
     *
     * @param string|null $query
     * @param int|null $minorVersion
     *
     * @return QueryResponse
     */
    public function query($query = null, $minorVersion = null)
    {
        if ($query === null) {
            $query = "select * from {$this->entity}";
        }

        $uri = $this->getEntityRequestUrl('query') . '?query=' . urlencode($query);

        if ($minorVersion !== null) {
            $this->validateMinorVersion($minorVersion);
            $uri .= '&minorversion=' . $minorVersion;
        }

        $response = $this->request('GET', $uri);

        return new QueryResponse($response['QueryResponse']);
    }

    /**
     * Send CDC request
     *
     * @param  array        $entities
     * @param  DateTime     $changed_since
     * @return array
     */
    public function cdc(array $entities, DateTime $changed_since)
    {
        $entities_value = urlencode(implode(',', $entities));
        $changed_since_value = urlencode(date_format($changed_since, DateTime::ATOM));
        $uri = $this->getEntityRequestUrl('cdc') . '?entities=' . $entities_value . '&changedSince=' . $changed_since_value;

        $response = $this->request('GET', $uri);

        if (!isset($response['CDCResponse']) || !isset($response['CDCResponse'][0]['QueryResponse'])) {
            throw new \Exception("Invalid CDC response.");
        }

        $query_response = $response['CDCResponse'][0]['QueryResponse'];
        $result = [];
        foreach ($query_response as $data) {
            foreach ($data as $key => $values) {
                if (!isset($result[$key])) {
                    $result[$key] = [];
                }
                foreach ($values as $value) {
                    $result[$key][] = new Entity($value);
                }
            }
        }

        return $result;
    }

    /**
     * Return headers for request
     *
     * @param  string           $method
     * @param  string           $uri
     * @return array
     */
    public function getHeaders($method, $uri)
    {
        $server = $this->createServer();

        $headers = $server->getHeaders($this->getTokenCredentials(), $method, $uri);

        $headers['Accept'] = 'application/json';
        $headers['Content-Type'] = 'application/json';

        if (!empty($this->user_agent)) {
            $headers['User-Agent'] = $this->user_agent;
        }

        return $headers;
    }

    /**
     * Request
     *
     * @param  string $method
     * @param  string $uri
     * @param  string|array $body
     * @return array
     * @throws \Exception
     */
    public function request($method, $uri, array $body = null)
    {
        $client = $this->createHttpClient();

        $headers = $this->getHeaders($method, $uri);

        if ($body !== null) {
            $body = json_encode($body);
        }

        try {
            return $client->createRequest($method, $uri, $headers, $body)->send()->json();
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();

            $body = $response->json();
            if (isset($body['Fault'])) {
                throw $this->createFaultException($body);
            }

            $body = $response;
            throw new \Exception(
                "Received error [$body] with status code [$statusCode] when sending request."
            );
        }
    }

    /**
     * @param array $body
     *
     * @return FaultException
     */
    private function createFaultException(array $body)
    {
        $errors = [];
        if (isset($body['Fault']['Error'])) {
            $errors = $body['Fault']['Error'];
        }
        return new FaultException("Fault response", 0, null, $errors);
    }

    /**
     * Validates the type for $minorVersion
     *
     * @param int $minorVersion
     *
     * @return self
     *
     * @throws InvalidArgumentException
     */
    private function validateMinorVersion($minorVersion)
    {
        if (!is_int($minorVersion)) {
            throw new InvalidArgumentException(sprintf('Invalid type for "$minorVersion" : expected "int", got "%s".', gettype($minorVersion)));
        }
        return $this;
    }
}
