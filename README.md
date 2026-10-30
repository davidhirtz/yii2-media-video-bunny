# yii2-media-video-bunny

[Bunny Stream](https://bunny.net/stream/) provider for the adaptive video of
[yii2-media-video](https://github.com/davidhirtz/yii2-media-video): uploaded videos are encoded by Bunny Stream and
played as HLS from its CDN, with the local file as the fallback until then. Requires `davidhirtz/yii2-media-video`
and Guzzle.

## Installation

```bash
composer require davidhirtz/yii2-media-video-bunny
```

The bundle bootstraps itself through `extra.bootstrap` and configures the `videoStream` component with
`BunnyStreamProvider`. Until the library params below are set, media-video wires nothing but
`video-stream/setup`: no `stream` attribute, no webhook, no admin action.

## Setup

Each installation gets a video library of its own, since Bunny calls one webhook URL per library. Create it with the
account API key (Account settings → API key), which is used once and never stored:

```bash
./yii video-stream/setup --account-key=…
```

The command creates a library named after the application (`--name` for another), points its webhook at
`/video-stream/webhook`, reads its pull zone's hostname and saves three params to `config/params.php`:

| Param | Meaning |
|---|---|
| `bunnyStreamLibraryId` | The library's id |
| `bunnyStreamApiKey` | The library's API key (a sensitive param) |
| `bunnyStreamCdnHost` | The library's pull zone, `vz-….b-cdn.net` |

It needs `urlManager.hostInfo` for the webhook URL. A second run asks before it creates another library and never
does so unattended. A library created in the dashboard works the same: copy the three values from its API page and
set the webhook URL there.

## Configuration

`Hirtz\Media\Video\Bunny\BunnyStreamProvider` is built through the container, so a definition configures it:

| Property | Default | Meaning |
|---|---|---|
| `replicationRegions` | `[]` | Regions a library `setup` creates replicates to (`UK`, `SE`, `NY`, …); they cannot be removed later |
| `timeout` / `connectTimeout` | `30` / `10` | Seconds per API request |
| `uploadTimeout` | `3600` | Seconds for uploading a file's bytes |

The `videoStream` component's own options (`submitByUrl`, `webhookEnabled`) are media-video's. A host Bunny cannot
reach, a local one for instance, sets `submitByUrl` to `false` and uploads with `video-stream/submit`, and polls with
`video-stream/refresh` instead of webhooks.

## Behaviour

- A video is fetched by Bunny from its public URL, or created and uploaded when `submitByUrl` is off. It is titled
  `#<file id> <filename>`, so the dashboard maps back to the media library.
- Bunny's status 4 (finished) is `ready`; 5 (error) and 6 (upload failed) are `failed`; everything else, including
  4's earlier "first resolution playable", is `processing`. A video Bunny no longer knows has failed.
- The webhook names the library and the video; a payload for another library is ignored, and the state is always
  fetched again, so the request's signature is not checked.
- Playback is `https://<cdn host>/<video id>/playlist.m3u8`, the poster `…/thumbnail.jpg`. The CDN host is added to
  the admin's content security policy; a project's frontend policy names it in `media-src`, `connect-src` and
  `img-src`, plus `blob:` in `media-src`.
- A new library blocks requests without a `Referer`. The skeleton's `strict-origin-when-cross-origin` sends one; a page
  with `Referrer-Policy: no-referrer` cannot play the stream.
- `registerWebhook()` is not supported: Bunny sets a library's webhook with the account key, which `setup` has.
- Bunny's fetch answers the new video's `id`, which its API reference does not document.
