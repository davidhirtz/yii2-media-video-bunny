<?php

declare(strict_types=1);

namespace Hirtz\Media\Video\Bunny;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use Hirtz\Media\Models\File;
use Hirtz\Media\Video\Streaming\ListableStreamProviderInterface;
use Hirtz\Media\Video\Streaming\ProvisionableStreamProviderInterface;
use Hirtz\Media\Video\Streaming\StreamState;
use Hirtz\Media\Video\Streaming\StreamStatus;
use Override;
use Yii;
use yii\base\BaseObject;
use yii\base\NotSupportedException;
use yii\web\Request;

class BunnyStreamProvider extends BaseObject implements ListableStreamProviderInterface, ProvisionableStreamProviderInterface
{
    final public const string NAME = 'bunny';

    public ?string $libraryId = null;
    public ?string $apiKey = null;

    /**
     * The library's pull zone, `vz-….b-cdn.net`.
     */
    public ?string $cdnHost = null;

    /**
     * @var list<string> regions a new library replicates to besides its main one, `UK`, `SE`, `NY`, …
     */
    public array $replicationRegions = [];

    public string $apiUrl = 'https://video.bunnycdn.com';
    public string $accountApiUrl = 'https://api.bunny.net';
    public ?HandlerStack $handlerStack = null;
    public float $timeout = 30;
    public float $connectTimeout = 10;

    /**
     * Uploading the bytes takes as long as the file is large.
     */
    public float $uploadTimeout = 3600;

    #[Override]
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * The library: a database copied to another installation keeps the video ids of the library it came from.
     */
    #[Override]
    public function getScope(): string
    {
        return (string)$this->libraryId;
    }

    #[Override]
    public function isProvisioned(): bool
    {
        return (bool)$this->libraryId && (bool)$this->apiKey && (bool)$this->cdnHost;
    }

    /**
     * Bunny calls the library's webhook URL, set by {@see provision()}; `$webhookUrl` is not sent per video.
     */
    #[Override]
    public function submit(File $file, ?string $url, string $webhookUrl): StreamState
    {
        $title = "#$file->id {$file->getFilename()}";

        try {
            $id = $url !== null ? $this->fetch($url, $title) : $this->upload($file, $title);
        } catch (ClientException $exception) {
            // Bunny limits the fetch jobs queued per library, which `video-stream/submit --all` reaches after a copy.
            if (in_array($exception->getResponse()->getStatusCode(), [408, 429], true)) {
                return new StreamState(self::NAME, null, StreamStatus::Pending, 'Bunny Stream is busy');
            }

            return StreamState::failed(self::NAME, $this->getErrorMessage($exception));
        } catch (ServerException|ConnectException) {
            return new StreamState(self::NAME, null, StreamStatus::Pending, 'Bunny Stream is unavailable');
        } catch (Exception $exception) {
            return StreamState::failed(self::NAME, $exception->getMessage());
        }

        return new StreamState(self::NAME, $id, StreamStatus::Processing);
    }

    /**
     * A transient error is thrown, so the state the file has is kept; a video Bunny no longer knows has failed.
     */
    #[Override]
    public function fetchState(StreamState $state): StreamState
    {
        try {
            $video = $this->request('GET', $this->getVideoPath($state));
        } catch (ClientException $exception) {
            if ($exception->getResponse()->getStatusCode() === 404) {
                return $state->withStatus(StreamStatus::Failed, 'The video no longer exists at Bunny Stream');
            }

            throw $exception;
        }

        return match ($video['status'] ?? null) {
            4 => $state->withStatus(StreamStatus::Ready),
            5 => $state->withStatus(StreamStatus::Failed, 'Bunny Stream could not encode the video'),
            6 => $state->withStatus(StreamStatus::Failed, 'The upload to Bunny Stream failed'),
            default => $state->withStatus(StreamStatus::Processing),
        };
    }

    #[Override]
    public function delete(StreamState $state): void
    {
        try {
            $this->request('DELETE', $this->getVideoPath($state));
        } catch (ClientException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 404) {
                throw $exception;
            }
        }
    }

    /**
     * Bunny answers `dateUploaded` in UTC without a zone.
     */
    #[Override]
    public function getVideos(): iterable
    {
        $utc = new DateTimeZone('UTC');
        $page = 1;

        do {
            $answer = $this->request('GET', "/library/$this->libraryId/videos", [
                'query' => ['page' => $page, 'itemsPerPage' => 100, 'orderBy' => 'date'],
            ]);

            $items = is_array($answer['items'] ?? null) ? $answer['items'] : [];

            foreach ($items as $item) {
                if (is_array($item) && is_string($item['guid'] ?? null)) {
                    $date = $item['dateUploaded'] ?? null;
                    yield $item['guid'] => is_string($date) ? new DateTimeImmutable($date, $utc) : null;
                }
            }

            $total = is_int($answer['totalItems'] ?? null) ? $answer['totalItems'] : 0;
        } while ($items && $page++ * 100 < $total);
    }

    #[Override]
    public function getPlaylistUrl(StreamState $state): string
    {
        return "https://$this->cdnHost/$state->id/playlist.m3u8";
    }

    /**
     * Bunny names the thumbnail it extracts `thumbnail.jpg`; only one set through its API is named otherwise.
     */
    #[Override]
    public function getPosterUrl(StreamState $state): ?string
    {
        return "https://$this->cdnHost/$state->id/thumbnail.jpg";
    }

    /**
     * Unsigned on purpose: the state is fetched again, so a forged request changes nothing.
     */
    #[Override]
    public function getVideoIdFromWebhook(Request $request): ?string
    {
        $payload = json_decode($request->getRawBody(), true);

        if (!is_array($payload) || (string)($payload['VideoLibraryId'] ?? '') !== $this->libraryId) {
            return null;
        }

        $id = $payload['VideoGuid'] ?? null;

        return is_string($id) && preg_match('/^[0-9a-f-]{36}$/i', $id) ? $id : null;
    }

    /**
     * @throws NotSupportedException
     */
    #[Override]
    public function registerWebhook(string $url): void
    {
        throw new NotSupportedException('Bunny Stream sets a library\'s webhook URL with the account key: run `video-stream/setup` or set it in the dashboard.');
    }

    #[Override]
    public function getContentSecurityPolicy(): array
    {
        return ["https://$this->cdnHost"];
    }

    /**
     * A library is created, then its webhook URL set and its pull zone's hostname read.
     */
    #[Override]
    public function provision(string $accountKey, string $name, string $webhookUrl): array
    {
        $library = $this->request('POST', '/videolibrary', [
            'json' => array_filter([
                'Name' => $name,
                'ReplicationRegions' => $this->replicationRegions,
            ]),
        ], $accountKey, $this->accountApiUrl);

        $id = $library['Id'] ?? null;
        $apiKey = $library['ApiKey'] ?? null;

        if (!is_int($id) || !is_string($apiKey)) {
            throw new Exception('Bunny Stream answered no library id or API key');
        }

        try {
            if ($webhookUrl !== '') {
                $this->request('POST', "/videolibrary/$id", [
                    'json' => ['WebhookUrl' => $webhookUrl],
                ], $accountKey, $this->accountApiUrl);
            }

            $pullZone = $this->request('GET', '/pullzone/' . ($library['PullZoneId'] ?? 0), [], $accountKey, $this->accountApiUrl);
            $cdnHost = $this->getCdnHost($pullZone);
        } catch (Exception $exception) {
            throw new Exception("The library #$id was created, but setting it up failed: {$exception->getMessage()}", 0, $exception);
        }

        return [
            'bunnyStreamLibraryId' => (string)$id,
            'bunnyStreamApiKey' => $apiKey,
            'bunnyStreamCdnHost' => $cdnHost,
        ];
    }

    /**
     * Bunny's own answer carries the new video's `id`, which its API reference leaves out.
     */
    protected function fetch(string $url, string $title): string
    {
        $answer = $this->request('POST', "/library/$this->libraryId/videos/fetch", [
            'json' => ['url' => $url, 'title' => $title],
        ]);

        $id = $answer['id'] ?? null;

        if (!is_string($id) || $id === '') {
            throw new Exception(is_string($answer['message'] ?? null) ? $answer['message'] : 'Bunny Stream answered no video id');
        }

        return $id;
    }

    /**
     * Opened before the video is created, and the video deleted when the bytes do not arrive: an empty video whose id
     * no state holds could never be deleted.
     */
    protected function upload(File $file, string $title): string
    {
        $stream = @fopen($file->getFilePath(), 'rb');

        if ($stream === false) {
            throw new Exception("The file {$file->getFilename()} cannot be read");
        }

        $video = $this->request('POST', "/library/$this->libraryId/videos", [
            'json' => ['title' => $title],
        ]);

        $id = $video['guid'] ?? null;

        if (!is_string($id) || $id === '') {
            throw new Exception('Bunny Stream answered no video id');
        }

        try {
            $this->request('PUT', "/library/$this->libraryId/videos/$id", [
                'body' => $stream,
                'timeout' => $this->uploadTimeout,
                'headers' => ['Content-Type' => 'application/octet-stream'],
            ]);
        } catch (GuzzleException $exception) {
            try {
                $this->request('DELETE', "/library/$this->libraryId/videos/$id");
            } catch (GuzzleException $deleteException) {
                Yii::error($deleteException, __METHOD__);
            }

            throw $exception;
        }

        return $id;
    }

    /**
     * Bunny's own `message` ("Origin returned HTTP 401 (Unauthorized)."), rather than Guzzle's request dump.
     */
    protected function getErrorMessage(ClientException $exception): string
    {
        $answer = json_decode((string)$exception->getResponse()->getBody(), true);
        $message = is_array($answer) ? ($answer['message'] ?? null) : null;

        return is_string($message) && $message !== '' ? $message : $exception->getMessage();
    }

    /**
     * @param array<string, mixed> $pullZone
     */
    protected function getCdnHost(array $pullZone): string
    {
        $hosts = [];

        foreach ((array)($pullZone['Hostnames'] ?? []) as $hostname) {
            if (is_array($hostname) && is_string($hostname['Value'] ?? null)) {
                $hosts[] = $hostname['Value'];
            }
        }

        $cdnHosts = array_values(array_filter($hosts, static fn (string $host): bool => str_ends_with($host, '.b-cdn.net')));

        return $cdnHosts[0] ?? $hosts[0] ?? throw new Exception('The library\'s pull zone has no hostname');
    }

    protected function getVideoPath(StreamState $state): string
    {
        return "/library/$this->libraryId/videos/$state->id";
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws GuzzleException
     */
    protected function request(string $method, string $path, array $options = [], ?string $accessKey = null, ?string $baseUrl = null): array
    {
        $client = new Client([
            'handler' => $this->handlerStack ?? HandlerStack::create(),
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ]);

        $options['headers'] = [
            'AccessKey' => $accessKey ?? (string)$this->apiKey,
            'Accept' => 'application/json',
            ...($options['headers'] ?? []),
        ];

        $answer = json_decode((string)$client->request($method, ($baseUrl ?? $this->apiUrl) . $path, $options)->getBody(), true);

        /** @var array<string, mixed> */
        return is_array($answer) ? $answer : [];
    }
}
