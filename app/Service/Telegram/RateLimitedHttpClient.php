<?php

namespace App\Service\Telegram;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Psr\Http\Message\ResponseInterface;
use Telegram\Bot\HttpClients\GuzzleHttpClient;
use Telegram\Bot\HttpClients\HttpClientInterface;
use Throwable;

final class RateLimitedHttpClient implements HttpClientInterface
{
    private const string LUA_THROTTLE = <<<'LUA'
local current = redis.call('INCR', KEYS[1])
if current == 1 then
  redis.call('PEXPIRE', KEYS[1], ARGV[1])
end
if current > tonumber(ARGV[2]) then
  local ttl = redis.call('PTTL', KEYS[1])
  if ttl < 0 then ttl = ARGV[1] end
  return ttl
end
return 0
LUA;

    private HttpClientInterface $inner;

    public function __construct(
        private readonly RedisFactory $redis,
        ?HttpClientInterface $inner = null,
        private readonly array $limits = [],
        private readonly string $redisConnection = 'default',
        private readonly string $keyPrefix = 'tg:rl:',
    ) {
        $this->inner = $inner ?? new GuzzleHttpClient();
    }

    public function getTimeOut(): int
    {
        return $this->inner->getTimeOut();
    }

    public function setTimeOut(int $timeOut): static
    {
        $this->inner->setTimeOut($timeOut);
        return $this;
    }

    public function getConnectTimeOut(): int
    {
        return $this->inner->getConnectTimeOut();
    }

    public function setConnectTimeOut(int $connectTimeOut): static
    {
        $this->inner->setConnectTimeOut($connectTimeOut);
        return $this;
    }

    public function send(
        string $url,
        string $method,
        array $headers = [],
        array $options = [],
        bool $isAsyncRequest = false
    ): ResponseInterface|PromiseInterface|null {
        $endpoint = $this->endpointFromUrl($url);
        $botKey   = $this->botKeyFromUrl($url);
        $bucket   = $this->bucketForEndpoint($endpoint);
        $apiRps  = (int) ($this->limits['api_rps']  ?? 80);
        $msgRps  = (int) ($this->limits['msg_rps']  ?? 28);
        $chatRps = (int) ($this->limits['chat_rps'] ?? 1);

        $this->waitIfPaused($botKey, $bucket);
        if ($bucket === 'msg') {
            $chatId = $this->extractChatId($options);
            if ($chatId !== null) {
                $this->throttle("{$botKey}:chat:{$chatId}", $chatRps, 1000);
            }
            $this->throttle("{$botKey}:msg", $msgRps, 1000);
        }

        $this->throttle("{$botKey}:api", $apiRps, 1000);
        if ($isAsyncRequest) {
            return $this->inner->send($url, $method, $headers, $options, true);
        }

        $lastResponse = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $result = $this->inner->send($url, $method, $headers, $options);
                if ($result instanceof PromiseInterface || $result === null) {
                    return $result;
                }

                $lastResponse = $result;
                if ($result->getStatusCode() !== 429) {
                    return $result;
                }

                $retryAfter = $this->retryAfterFromResponse($result) ?? 1;
            } catch (Throwable $e) {
                $retryAfter = $this->detectRetryAfterSeconds($e);
                if ($retryAfter === null) {
                    throw $e;
                }
            }

            $this->pause($botKey, $bucket, $retryAfter);
            $this->sleepMs($retryAfter * 1000 + random_int(0, 250));
            $this->waitIfPaused($botKey, $bucket);
        }

        return $lastResponse;
    }

    private function throttle(string $key, int $limit, int $windowMs): void
    {
        if ($limit <= 0) {
            return;
        }

        try {
            $conn = $this->redis->connection($this->redisConnection);
            $ttl = (int) $conn->eval(self::LUA_THROTTLE,1, ($this->keyPrefix . $key), (string)$windowMs, (string)$limit);

            if ($ttl > 0) {
                $this->sleepMs($ttl);
            }
        } catch (Throwable) {
        }
    }

    private function waitIfPaused(string $botKey, string $bucket): void
    {
        try {
            $conn = $this->redis->connection($this->redisConnection);
            $pauseKey = $this->keyPrefix . "{$botKey}:pause:{$bucket}";
            $ttl = (int) $conn->pttl($pauseKey);
            if ($ttl > 0) {
                $this->sleepMs($ttl);
            }
        } catch (Throwable) {
        }
    }

    private function pause(string $botKey, string $bucket, int $retryAfterSec): void
    {
        $retryAfterSec = max(1, $retryAfterSec);

        try {
            $conn = $this->redis->connection($this->redisConnection);
            $pauseKey = $this->keyPrefix . "{$botKey}:pause:{$bucket}";

            $conn->psetex($pauseKey, $retryAfterSec * 1000, 1);
        } catch (Throwable) {
        }
    }

    private function endpointFromUrl(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        return $path !== '' ? basename($path) : '';
    }

    private function botKeyFromUrl(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match('~/bot([^/]+)/~', $path, $m)) {
            return substr(hash('sha256', $m[1]), 0, 12);
        }

        return substr(hash('sha256', $path), 0, 12);
    }

    private function bucketForEndpoint(string $endpoint): string
    {
        foreach (['send', 'editMessage', 'forward', 'copy'] as $prefix) {
            if ($endpoint !== '' && str_starts_with($endpoint, $prefix)) {
                return 'msg';
            }
        }
        return 'api';
    }

    private function extractChatId(array $options): ?string
    {
        $params = $options['form_params'] ?? $options['json'] ?? null;

        if (is_array($params) && array_key_exists('chat_id', $params) && is_scalar($params['chat_id'])) {
            return (string) $params['chat_id'];
        }

        // на будущее (upload): multipart
        if (!empty($options['multipart']) && is_array($options['multipart'])) {
            foreach ($options['multipart'] as $part) {
                if (($part['name'] ?? null) === 'chat_id' && isset($part['contents']) && is_scalar($part['contents'])) {
                    return (string) $part['contents'];
                }
            }
        }

        return null;
    }

    private function retryAfterFromResponse(ResponseInterface $resp): ?int
    {
        try {
            $body = (string) $resp->getBody();
            if ($resp->getBody()->isSeekable()) {
                $resp->getBody()->rewind();
            }

            $json = json_decode($body, true);
            $ra = $json['parameters']['retry_after'] ?? null;

            if (is_int($ra) || ctype_digit((string) $ra)) {
                return (int) $ra;
            }
        } catch (Throwable) {
        }

        return null;
    }

    private function detectRetryAfterSeconds(Throwable $e): ?int
    {
        if (preg_match('~retry after\s+(\d+)~i', $e->getMessage(), $m)) {
            return (int) $m[1];
        }
        if (method_exists($e, 'getResponse')) {
            $resp = $e->getResponse();
            if ($resp instanceof ResponseInterface) {
                return $this->retryAfterFromResponse($resp);
            }
        }

        return null;
    }

    private function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
