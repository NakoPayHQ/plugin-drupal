<?php

namespace Drupal\nakopay;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * NakoPay API client service.
 */
class NakoPayApiClient
{
    const BASE_URL = 'https://daslrxpkbkqrbnjwouiq.supabase.co/functions/v1';
    const API_VERSION = '2025-04-20';
    const SDK_VERSION = '1.0.0';

    protected ClientInterface $httpClient;
    protected $logger;

    public function __construct(
        ConfigFactoryInterface $config_factory,
        ClientInterface $http_client,
        LoggerChannelFactoryInterface $logger_factory
    ) {
        $this->httpClient = $http_client;
        $this->logger = $logger_factory->get('nakopay');
    }

    /**
     * Create an invoice via the NakoPay API.
     */
    public function createInvoice(
        string $amount,
        string $currency,
        string $coin,
        string $description,
        array $metadata,
        string $api_key
    ): array {
        return $this->request('POST', '/invoices-create', [
            'amount'      => $amount,
            'currency'    => strtoupper($currency),
            'coin'        => strtoupper($coin),
            'description' => $description,
            'metadata'    => $metadata,
        ], $api_key);
    }

    /**
     * Retrieve an invoice.
     */
    public function getInvoice(string $id, string $api_key): array
    {
        return $this->request('GET', '/invoices-get?id=' . urlencode($id), null, $api_key);
    }

    /**
     * Verify a webhook signature.
     */
    public function verifySignature(string $payload, string $signature, string $secret, int $tolerance = 300): bool
    {
        $parts = [];
        foreach (explode(',', $signature) as $kv) {
            $kv = trim($kv);
            if ($kv === '' || strpos($kv, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $kv, 2);
            $parts[trim($k)] = trim($v);
        }

        if (empty($parts['t']) || empty($parts['v1'])) {
            return false;
        }

        $t = (int) $parts['t'];
        if (abs(time() - $t) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        return hash_equals($expected, $parts['v1']);
    }

    protected function request(string $method, string $path, ?array $body, string $api_key): array
    {
        $url = self::BASE_URL . $path;
        $options = [
            'headers' => [
                'Authorization'     => 'Bearer ' . $api_key,
                'X-NakoPay-Version' => self::API_VERSION,
                'User-Agent'        => 'NakoPay-Drupal/' . self::SDK_VERSION,
                'Accept'            => 'application/json',
                'Content-Type'      => 'application/json',
            ],
            'timeout' => 20,
        ];

        if ($body !== null && $method !== 'GET') {
            $options['headers']['Idempotency-Key'] = 'idem_' . bin2hex(random_bytes(16));
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $code = $response->getStatusCode();
            $raw = (string) $response->getBody();
            $json = json_decode($raw, true) ?: [];

            return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'body' => $json];
        } catch (\Exception $e) {
            $this->logger->error('NakoPay API error: @msg', ['@msg' => $e->getMessage()]);
            return ['ok' => false, 'status' => 0, 'body' => ['error' => $e->getMessage()]];
        }
    }
}
