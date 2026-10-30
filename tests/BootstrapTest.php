<?php

declare(strict_types=1);

namespace Hirtz\Media\Video\Bunny\Tests;

use Hirtz\Media\Models\File;
use Hirtz\Media\Video\Bunny\BunnyStreamProvider;
use Hirtz\Media\Video\Console\Controllers\VideoStreamController;
use Hirtz\Media\Video\Streaming\StreamManager;
use Hirtz\Skeleton\Console\Application as ConsoleApplication;
use Hirtz\Skeleton\Test\TestCase;
use Yii;

class BootstrapTest extends TestCase
{
    public function testTheApiKeyIsASensitiveParam(): void
    {
        self::assertContains('bunnyStreamApiKey', Yii::$app->sensitiveParams);
    }

    public function testWithoutTheLibraryParamsOnlySetupIsWired(): void
    {
        $this->applicationClass = ConsoleApplication::class;
        $this->reloadApplication();

        $manager = StreamManager::getComponent();

        self::assertInstanceOf(BunnyStreamProvider::class, $manager->getProvider());
        self::assertFalse($manager->isSetUp());
        self::assertInstanceOf(VideoStreamController::class, Yii::$app->createController('video-stream')[0] ?? null);
        self::assertFalse(File::create()->hasAttribute(StreamManager::ATTRIBUTE));
    }

    public function testTheLibraryParamsSetUpTheProvider(): void
    {
        $this->config['params'] = [
            ...$this->config['params'] ?? [],
            'bunnyStreamLibraryId' => 794367,
            'bunnyStreamApiKey' => 'library-key',
            'bunnyStreamCdnHost' => 'vz-test.b-cdn.net',
        ];

        $this->reloadApplication();

        $manager = StreamManager::getComponent();
        $provider = $manager->getProvider();

        self::assertTrue($manager->isSetUp());
        self::assertInstanceOf(BunnyStreamProvider::class, $provider);
        self::assertSame('794367', $provider->libraryId);
        self::assertSame('vz-test.b-cdn.net', $provider->cdnHost);
        self::assertTrue(File::create()->hasAttribute(StreamManager::ATTRIBUTE));
    }
}
