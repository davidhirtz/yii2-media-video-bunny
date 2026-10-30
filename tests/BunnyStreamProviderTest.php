<?php

declare(strict_types=1);

namespace Hirtz\Media\Video\Bunny\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Hirtz\Media\Models\File;
use Hirtz\Media\Test\TestCase;
use Hirtz\Media\Test\Traits\MediaFileTrait;
use Hirtz\Media\Video\Bunny\BunnyStreamProvider;
use Hirtz\Media\Video\Streaming\StreamState;
use Hirtz\Media\Video\Streaming\StreamStatus;
use Hirtz\Skeleton\Helpers\FileHelper;
use Override;
use Psr\Http\Message\RequestInterface;
use yii\base\NotSupportedException;
use yii\web\Request;

class BunnyStreamProviderTest extends TestCase
{
    use MediaFileTrait;

    private const string GUID = '3f2a8c1e-5b7d-4e9a-8c2f-1a6b9d0e4f7c';

    /**
     * @var list<array{request: RequestInterface}>
     */
    private array $history = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = $this->createFolder('Uploads', 'uploads');
    }

    #[Override]
    protected function tearDown(): void
    {
        FileHelper::removeDirectory((string)File::getModule()->uploadPath);
        parent::tearDown();
    }

    public function testIsProvisionedNeedsAllThreeParams(): void
    {
        self::assertTrue($this->createProvider()->isProvisioned());
        self::assertFalse((new BunnyStreamProvider(['libraryId' => '42', 'apiKey' => 'key']))->isProvisioned());
    }

    public function testSubmitByUrlFetchesTheVideo(): void
    {
        $provider = $this->createProvider(new Response(200, [], self::json(['id' => self::GUID, 'success' => true, 'message' => 'OK', 'statusCode' => 200])));
        $state = $provider->submit($this->buildVideo('Grüße 🎬'), 'https://www.example.com/uploads/clip.mp4', '');

        self::assertSame(self::GUID, $state->id);
        self::assertSame(StreamStatus::Processing, $state->status);
        self::assertSame(BunnyStreamProvider::NAME, $state->provider);

        $request = $this->getRequest(0);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://video.bunnycdn.com/library/42/videos/fetch', (string)$request->getUri());
        self::assertSame('library-key', $request->getHeaderLine('AccessKey'));
        self::assertSame(['url' => 'https://www.example.com/uploads/clip.mp4', 'title' => '#7 Grüße 🎬.mp4'], $this->getJson($request));
    }

    public function testSubmitFailsWithoutAVideoId(): void
    {
        $provider = $this->createProvider(new Response(200, [], self::json(['success' => false, 'message' => 'Unable to fetch'])));
        $state = $provider->submit($this->buildVideo(), 'https://www.example.com/clip.mp4', '');

        self::assertSame(StreamStatus::Failed, $state->status);
        self::assertSame('Unable to fetch', $state->error);
        self::assertNull($state->id);
    }

    public function testSubmitFailsOnAConnectionError(): void
    {
        $provider = $this->createProvider(new ConnectException('Connection refused', new Psr7Request('POST', 'https://video.bunnycdn.com')));
        $state = $provider->submit($this->buildVideo(), 'https://www.example.com/clip.mp4', '');

        self::assertSame(StreamStatus::Failed, $state->status);
        self::assertSame('Connection refused', $state->error);
    }

    public function testSubmitWithoutUrlCreatesTheVideoAndUploadsTheBytes(): void
    {
        $file = $this->buildVideo();
        file_put_contents($file->getFilePath(), 'Mu\u{308}ller video bytes');

        $provider = $this->createProvider(
            new Response(200, [], self::json(['guid' => self::GUID])),
            new Response(200, [], self::json(['success' => true])),
        );

        $state = $provider->submit($file, null, '');

        self::assertSame(self::GUID, $state->id);
        self::assertSame(['title' => '#7 clip.mp4'], $this->getJson($this->getRequest(0)));

        $upload = $this->getRequest(1);

        self::assertSame('PUT', $upload->getMethod());
        self::assertSame('https://video.bunnycdn.com/library/42/videos/' . self::GUID, (string)$upload->getUri());
        self::assertSame('Mu\u{308}ller video bytes', (string)$upload->getBody());
    }

    public function testSubmitFailsWhenTheFileCannotBeRead(): void
    {
        $provider = $this->createProvider(new Response(200, [], self::json(['guid' => self::GUID])));
        $state = $provider->submit($this->buildVideo(), null, '');

        self::assertSame(StreamStatus::Failed, $state->status);
        self::assertSame('The file clip.mp4 cannot be read', $state->error);
    }

    /**
     * @return array<string, array{int, StreamStatus}>
     */
    public static function statusProvider(): array
    {
        return [
            'created' => [0, StreamStatus::Processing],
            'transcoding' => [3, StreamStatus::Processing],
            'finished' => [4, StreamStatus::Ready],
            'error' => [5, StreamStatus::Failed],
            'upload failed' => [6, StreamStatus::Failed],
            'jit playlists' => [8, StreamStatus::Processing],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusProvider')]
    public function testFetchStateMapsBunnysStatus(int $status, StreamStatus $expected): void
    {
        $provider = $this->createProvider(new Response(200, [], self::json(['guid' => self::GUID, 'status' => $status])));
        $state = $provider->fetchState($this->createState());

        self::assertSame($expected, $state->status);
        self::assertSame('GET', $this->getRequest(0)->getMethod());
        self::assertSame('https://video.bunnycdn.com/library/42/videos/' . self::GUID, (string)$this->getRequest(0)->getUri());
    }

    public function testFetchStateFailsAVideoBunnyNoLongerKnows(): void
    {
        $provider = $this->createProvider(new Response(404));
        $state = $provider->fetchState($this->createState());

        self::assertSame(StreamStatus::Failed, $state->status);
        self::assertSame('The video no longer exists at Bunny Stream', $state->error);
    }

    public function testFetchStateThrowsATransientError(): void
    {
        $provider = $this->createProvider(new Response(502));

        $this->expectException(ServerException::class);
        $provider->fetchState($this->createState());
    }

    public function testDeleteIgnoresAVideoAlreadyGone(): void
    {
        $provider = $this->createProvider(new Response(200), new Response(404));

        $provider->delete($this->createState());
        $provider->delete($this->createState());

        self::assertSame('DELETE', $this->getRequest(1)->getMethod());
    }

    public function testDeleteThrowsAServerError(): void
    {
        $provider = $this->createProvider(new Response(500));

        $this->expectException(ServerException::class);
        $provider->delete($this->createState());
    }

    public function testThePlaybackUrlsAndTheContentSecurityPolicyNameTheCdnHost(): void
    {
        $provider = $this->createProvider();
        $state = $this->createState();

        self::assertSame('https://vz-test.b-cdn.net/' . self::GUID . '/playlist.m3u8', $provider->getPlaylistUrl($state));
        self::assertSame('https://vz-test.b-cdn.net/' . self::GUID . '/thumbnail.jpg', $provider->getPosterUrl($state));
        self::assertSame(['https://vz-test.b-cdn.net'], $provider->getContentSecurityPolicy());
    }

    public function testTheWebhookAnswersTheVideoOfItsOwnLibraryOnly(): void
    {
        $provider = $this->createProvider();

        self::assertSame(self::GUID, $provider->getVideoIdFromWebhook($this->createWebhookRequest(['VideoLibraryId' => 42, 'VideoGuid' => self::GUID, 'Status' => 3])));
        self::assertNull($provider->getVideoIdFromWebhook($this->createWebhookRequest(['VideoLibraryId' => 43, 'VideoGuid' => self::GUID])));
        self::assertNull($provider->getVideoIdFromWebhook($this->createWebhookRequest(['VideoLibraryId' => 42, 'VideoGuid' => '../../etc/passwd'])));
        self::assertNull($provider->getVideoIdFromWebhook($this->createWebhookRequest('not json')));
    }

    public function testRegisterWebhookIsNotSupported(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->createProvider()->registerWebhook('https://www.example.com/video-stream/webhook');
    }

    public function testProvisionCreatesTheLibrarySetsItsWebhookAndReadsItsHost(): void
    {
        $provider = new BunnyStreamProvider([
            'replicationRegions' => ['UK'],
            'handlerStack' => $this->createHandlerStack(
                new Response(201, [], self::json(['Id' => 794367, 'ApiKey' => 'new-key', 'PullZoneId' => 55])),
                new Response(204),
                new Response(200, [], self::json(['Hostnames' => [
                    ['Value' => 'www.example.com'],
                    ['Value' => 'vz-72de8b0f-e60.b-cdn.net'],
                ]])),
            ),
        ]);

        $params = $provider->provision('account-key', 'Grüße 🎬', 'https://www.example.com/video-stream/webhook');

        self::assertSame([
            'bunnyStreamLibraryId' => '794367',
            'bunnyStreamApiKey' => 'new-key',
            'bunnyStreamCdnHost' => 'vz-72de8b0f-e60.b-cdn.net',
        ], $params);

        self::assertSame('https://api.bunny.net/videolibrary', (string)$this->getRequest(0)->getUri());
        self::assertSame('account-key', $this->getRequest(0)->getHeaderLine('AccessKey'));
        self::assertSame(['Name' => 'Grüße 🎬', 'ReplicationRegions' => ['UK']], $this->getJson($this->getRequest(0)));
        self::assertSame('https://api.bunny.net/videolibrary/794367', (string)$this->getRequest(1)->getUri());
        self::assertSame(['WebhookUrl' => 'https://www.example.com/video-stream/webhook'], $this->getJson($this->getRequest(1)));
        self::assertSame('https://api.bunny.net/pullzone/55', (string)$this->getRequest(2)->getUri());
    }

    public function testProvisionWithoutWebhooksSkipsTheWebhookUrl(): void
    {
        $provider = new BunnyStreamProvider([
            'handlerStack' => $this->createHandlerStack(
                new Response(201, [], self::json(['Id' => 1, 'ApiKey' => 'key', 'PullZoneId' => 2])),
                new Response(200, [], self::json(['Hostnames' => [['Value' => 'vz-1.b-cdn.net']]])),
            ),
        ]);

        self::assertSame('vz-1.b-cdn.net', $provider->provision('account-key', 'Test', '')['bunnyStreamCdnHost']);
        self::assertSame(['Name' => 'Test'], $this->getJson($this->getRequest(0)));
        self::assertCount(2, $this->history);
    }

    public function testProvisionNamesTheLibraryItCouldNotFinish(): void
    {
        $provider = new BunnyStreamProvider([
            'handlerStack' => $this->createHandlerStack(
                new Response(201, [], self::json(['Id' => 9, 'ApiKey' => 'key', 'PullZoneId' => 2])),
                new Response(200, [], self::json(['Hostnames' => []])),
            ),
        ]);

        $this->expectExceptionMessage('The library #9 was created, but setting it up failed: The library\'s pull zone has no hostname');
        $provider->provision('account-key', 'Test', '');
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function json(array $values): string
    {
        return json_encode($values, JSON_THROW_ON_ERROR);
    }

    private function createProvider(Response|ConnectException ...$responses): BunnyStreamProvider
    {
        return new BunnyStreamProvider([
            'libraryId' => '42',
            'apiKey' => 'library-key',
            'cdnHost' => 'vz-test.b-cdn.net',
            'handlerStack' => $this->createHandlerStack(...$responses),
        ]);
    }

    private function createHandlerStack(Response|ConnectException ...$responses): HandlerStack
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return $stack;
    }

    private function getRequest(int $index): RequestInterface
    {
        return $this->history[$index]['request'] ?? self::fail("No request #$index");
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(RequestInterface $request): array
    {
        $json = json_decode((string)$request->getBody(), true);
        self::assertIsArray($json);

        /** @var array<string, mixed> $json */
        return $json;
    }

    private function buildVideo(string $name = 'clip'): File
    {
        $file = $this->buildFile($name, 'mp4');
        $file->id = 7;

        return $file;
    }

    private function createState(): StreamState
    {
        return new StreamState(BunnyStreamProvider::NAME, self::GUID, StreamStatus::Processing);
    }

    /**
     * @param array<string, mixed>|string $payload
     */
    private function createWebhookRequest(array|string $payload): Request
    {
        $request = new Request();
        $request->setRawBody(is_string($payload) ? $payload : self::json($payload));

        return $request;
    }
}
