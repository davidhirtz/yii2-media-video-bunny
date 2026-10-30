<?php

declare(strict_types=1);

namespace Hirtz\Media\Video\Bunny;

use Hirtz\Media\Video\Streaming\StreamManager;
use Hirtz\Skeleton\Base\ConfigBootstrapInterface;
use Hirtz\Skeleton\Console\Application as ConsoleApplication;
use Hirtz\Skeleton\Web\Application;
use Override;
use Yii;

/**
 * The component exists without the library params, so `video-stream/setup` can create the library; until then
 * media-video wires nothing else ({@see StreamManager::isSetUp()}).
 */
class Bootstrap implements ConfigBootstrapInterface
{
    #[Override]
    public static function getDefaultConfig(): array
    {
        return [
            'components' => [
                'videoStream' => [
                    'class' => StreamManager::class,
                    'provider' => static fn (): BunnyStreamProvider => self::createProvider(),
                ],
            ],
            'sensitiveParams' => [
                'bunnyStreamApiKey',
            ],
        ];
    }

    /**
     * @param Application<\Hirtz\Skeleton\Models\User>|ConsoleApplication $app
     */
    #[Override]
    public function bootstrap($app): void
    {
        Yii::setAlias('@media-video-bunny', __DIR__);
    }

    protected static function createProvider(): BunnyStreamProvider
    {
        $params = Yii::$app->params;

        return Yii::$container->get(BunnyStreamProvider::class, [], [
            'libraryId' => self::getParam($params, 'bunnyStreamLibraryId'),
            'apiKey' => self::getParam($params, 'bunnyStreamApiKey'),
            'cdnHost' => self::getParam($params, 'bunnyStreamCdnHost'),
        ]);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private static function getParam(array $params, string $name): ?string
    {
        $value = $params[$name] ?? null;
        return is_string($value) || is_int($value) ? (string)$value : null;
    }
}
