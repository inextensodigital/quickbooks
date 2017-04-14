<?php

namespace ActiveCollab\Quickbooks;

use ActiveCollab\Quickbooks\Data\Entity;
use ActiveCollab\Quickbooks\Data\QueryResponse;
use ActiveCollab\Quickbooks\Exception\FaultException;
use DateTime;
use Guzzle\Http\Exception\BadResponseException;
use Guzzle\Service\Client as GuzzleClient;
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
     *
     * @return static
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
     *
     * @return static
     */
    public function setEntity($entity)
    {
        $this->entity = $entity;

        return $this;
    }

    /**
     * Return entity url
     *
     * @param string $slug
     *
     * @return string
     */
    public function getRequestUrl($slug)
    {
        return $this->getApiUrl() . '/company/' . $this->realmId .  '/' . strtolower($slug);
    }

    /**
     * Send create request
     *
     * @param  array            $payload
     * @return Entity
     */
    public function create(array $payload)
    {
        $response = $this->request('POST', $this->getRequestUrl($this->entity), $payload);

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
        $uri = $this->getRequestUrl($this->entity) . '/' . $id;

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
        $uri = $this->getRequestUrl($this->entity) . '?operation=update';

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
        $uri = $this->getRequestUrl($this->entity) . '?operation=delete';

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

        $uri = $this->getRequestUrl('query') . '?query=' . urlencode($query);

        if (null !== $minorVersion) {
            $this->validateMinorVersion($minorVersion);
            $uri .= '&minorversion=' . $minorVersion;
        }

        $response = $this->request('GET', $uri);

        return new QueryResponse($response['QueryResponse']);
    }

    /**
     * Send CDC request
     *
     * @param  array $entities
     * @param  DateTime $changed_since
     *
     * @return array
     *
     * @throws \Exception
     */
    public function cdc(array $entities, DateTime $changed_since)
    {
        $entities_value = urlencode(implode(',', $entities));
        $changed_since_value = urlencode(date_format($changed_since, DateTime::ATOM));
        $uri = $this->getRequestUrl('cdc') . '?entities=' . $entities_value . '&changedSince=' . $changed_since_value;

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
     * @param string $fileName
     * @param string $contentType
     * @param string $content
     *
     * @return Entity
     *
     * @throws FaultException
     */
    public function upload($fileName, $contentType, $content)
    {
        $boundary = hash('sha256', uniqid('', true));
        $body = "--$boundary\r\n"
            . 'Content-Disposition: form-data; name="file_metadata_01"' . "\r\n"
            . 'Content-Type: application/json; charset=UTF-8' . "\r\n"
            . "--$boundary\r\n"
            . 'Content-Disposition: form-data; name="file_content_01"; filename="' . $fileName . '"' . "\r\n"
            . 'Content-Type: ' . $contentType . "\r\n"
            . 'Content-Transfer-Encoding: base64' . "\r\n"
            . "\r\n"
            . chunk_split(base64_encode($content)) . "\r\n"
            . "--$boundary--";

        $headers = [
            'Content-Type' => 'multipart/form-data; boundary='.$boundary,
        ];
        $response = $this->request('POST', $this->getRequestUrl('upload'), $body, $headers);

        if (!isset($response['AttachableResponse'])) {
            throw new \UnexpectedValueException('The QuickBooks response should contain an "AttachableResponse" node');
        }

        $response = reset($response['AttachableResponse']);

        if (false === $response) {
            throw new \UnexpectedValueException('The QuickBooks AttachableResponse is empty');
        }

        if (isset($response['Fault'])) {
            throw $this->createFaultException($response);
        }

        return new Entity($response[$this->entity]);
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
     * @param array|null $headers
     *
     * @return array
     *
     * @throws \RuntimeException
     */
    public function request($method, $uri, $body = null, array $headers = [])
    {
        $client = $this->createHttpClient();

        $headers += $this->getHeaders($method, $uri);

        if ($body !== null && is_array($body)) {
            $body = json_encode($body);
        }

        try {
            return $client->createRequest($method, $uri, $headers, $body)->send()->json();
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();

            $body = $response->json();
            if (isset($body['Fault'])) {
                throw $this->createFaultException($body, $e);
            }

            $body = $response;
            throw new \RuntimeException(
                "Received error [$body] with status code [$statusCode] when sending request."
            );
        }
    }

    /**
     * Validates the type for $minorVersion
     *
     * @param int $minorVersion
     *
     * @return self
     *
     * @throws \InvalidArgumentException
     */
    private function validateMinorVersion($minorVersion)
    {
        if (!is_int($minorVersion)) {
            throw new \InvalidArgumentException(sprintf('Invalid type for "$minorVersion" : expected "int", got "%s".', gettype($minorVersion)));
        }
        return $this;
    }

    /**
     * @param array $body
     * @param \Exception $previous
     *
     * @return FaultException
     */
    private function createFaultException(array $body, \Exception $previous = null)
    {
        $errors = [];
        if (isset($body['Fault']['Error'])) {
            $errors = $body['Fault']['Error'];
        }
        return new FaultException("Fault response : " . json_encode($errors), 0, $previous, $errors);
    }
}
