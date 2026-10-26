# omnipost/instagram

Instagram for [omnisense/omnipost](https://github.com/glitchr-omnisense/omnipost): the account and its
feed read, pictures, carousels, reels and stories published, the long-lived token refreshed and the
account connected from a back office, through the *Instagram API with Instagram Login* - no Facebook
Page needed. The provider calls, on `https://graph.instagram.com/v23.0` (`Api::BASE_URI`,
`Api::VERSION`, the `base_uri` and `api_version` options):

| Operation | Endpoint |
|---|---|
| a container | `POST /<user>/media` |
| its state | `GET /<id>?fields=status_code,status` |
| publishing it | `POST /<user>/media_publish` (`creation_id`) |
| a media | `GET /<id>?fields=id,permalink,timestamp,media_type` |
| the account | `GET /<user>?fields=id,username,name,profile_picture_url,followers_count,media_count,account_type` |
| the feed | `GET /<user>/media?fields=...&limit=&after=` |
| refresh | `GET https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token` (unversioned) |
| connect | `https://www.instagram.com/oauth/authorize`, `POST https://api.instagram.com/oauth/access_token`, `GET https://graph.instagram.com/access_token?grant_type=ig_exchange_token` |

`<user>` is the `user_id` option, `me` by default. The token goes as the `access_token`
parameter of every Graph call. There is no sandbox: an app in development mode and a tester account
are the test setup.

## Installation

```sh
composer require omnisense/omnipost omnipost/instagram
```

Requires PHP 8.2, `omnisense/omnipost` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a Symfony
application with `Omnipost\Bridge\Symfony\OmnipostBundle`, the factory is registered as soon as the
package is installed.

## Credentials

From the README:

- A **professional** Instagram account (Business or Creator).
- A **Meta app** (developers.facebook.com, type *Business*) with the **Instagram API with Instagram
  Login** product, the permissions `instagram_business_basic` and
  `instagram_business_content_publish`, the site's redirect URI declared: its **app id** and **app
  secret**.
- While the app is in development mode, the account added as an **Instagram Tester** and the
  invitation accepted. A site that posts on its own account needs no App Review; App Review comes
  in to serve accounts that are not the app's testers.
- A **token**: generated in the app's dashboard for the tester's account, or obtained with
  `authorizationUrl()` and `exchange()`. It lives 60 days; `refresh()` gives 60 more to a token
  at least 24 hours old and not dead yet.
- The media at a **public URL** Meta's servers can fetch (a signed, temporary URL will do).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `access_token` | string | none | for every call but connecting | the long-lived token |
| `app_id` | string | none | for `authorizationUrl()`, `exchange()` | |
| `app_secret` | string | none | for `exchange()` | |
| `user_id` | string | `me` | no | the account the calls are for |
| `share_to_feed` | bool | `true` | no | a reel also on the profile's grid |
| `api_version` | string | `v23.0` | no | |
| `base_uri` | string | `https://graph.instagram.com` | no | |

No option is required to build the provider (`null` and `''` are as if not given). Post options
read (`Post::$options`, or Instagram's variant's): `share_to_feed`, `thumb_offset`.

## Capabilities

| Call | Supported |
|---|---|
| `publish()` `REEL` | yes: one video, 9:16, 3 s to 15 min, MP4 or MOV, at most 1 GiB |
| `publish()` `IMAGE` | yes: one JPEG, 9:16, 4:5 or 1:1, at most 8 MiB, with its `alt` |
| `publish()` `CAROUSEL` | yes: 2 to 10 pictures or videos |
| `publish()` `STORY` | yes: one picture or video, no caption sent |
| `publish()` `VIDEO` | no: refused by the validator (a video goes out as a reel) |
| caption, hashtags | 2200 characters with the hashtags, 30 hashtags; width at least 320 px |
| scheduling | no: a scheduled post is refused |
| `status()` | yes: publishes the container when it is ready |
| `delete()` | no: `NotSupportedException` (the API cannot) |
| `account()`, `feed()` | yes |
| `refresh()`, `token()` | yes |
| `authorizationUrl()`, `exchange()` | yes |

## Behaviour

**Publishing in three steps.** `publish()` creates a container and answers `PENDING` with its id;
`status(container)` answers `PROCESSING` while Instagram works, `FAILED` on `ERROR` or
`EXPIRED`, and on `FINISHED` publishes it and answers `PUBLISHED` with **the media's id** and its
permalink. `status()` also takes a media's id. Two workers must not poll one container at once (it
would be published twice): hold a lock around `status()`. The container's fields per kind:
[Publishing](https://github.com/glitchr-omnisense/omnipost/blob/1.x/docs/usage-publishing.md#instagram-in-three-steps).

**Validation.** One set of capabilities for every kind (the 10 media are the carousel's, 3 s - 15 min
and the sizes the reel's), plus what depends on the kind: one media unless a carousel (at least 2
for one), a reel is a 9:16 video, a picture post a picture. The format and size checks run only when
the media carries a `mime` and a `size`.

**Feed.** `limit` between 1 and 100; `next` is the `after` cursor only when Instagram announces a
next page. A `VIDEO` with `media_product_type` `REELS` (or none) is a `REEL`, another `VIDEO`;
`CAROUSEL_ALBUM` a `CAROUSEL` with its children; a `STORY` product a `STORY`; anything else an
`IMAGE`. A picture's thumbnail is the picture, a carousel's its first child's.

**Tokens.** `refresh()` and `exchange()` answer a `Token` with its end (`expires_in`) and the
provider uses the new token from then on; `token()` is the token held, its end unknown unless it
came from one of them. `exchange()` strips the `#_` Instagram appends to the code, and gives the
permissions granted as `scopes` and the account's id as `accountId`.

**Messages.** Every `access_token=`, `client_secret=` and `code=` in an error message is masked,
and a transport error is not chained to the client's exception, whose message carries the URL.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnipost\Instagram\InstagramProviderFactory;
use Omnipost\Model\Media;
use Omnipost\Model\Post;
use Omnipost\Platform;
use Symfony\Component\HttpClient\HttpClient;

$instagram = (new InstagramProviderFactory(HttpClient::create()))->create(['access_token' => getenv('INSTAGRAM_ACCESS_TOKEN')]);

$post = Post::reel(Media::video('https://site.example/media/reel.mp4', null, 42.0, 1080, 1920), 'Strauss, tonight.', null, ['violin']);
$publication = $instagram->publish($post->for(Platform::INSTAGRAM));   // PENDING, the container's id
while (!$publication->state->isFinal()) {
    sleep(5);
    $publication = $instagram->status($publication->id);               // PROCESSING, then PUBLISHED with the media's id
}
echo $publication->permalink, "\n";

foreach ($instagram->feed(null, 6)->items as $item) {
    echo $item->kind->value, ' ', $item->permalink, "\n";
}
```

The same flow was run on 2026-10-26 on a `MockHttpClient` (requests and answers in
[Publishing](https://github.com/glitchr-omnisense/omnipost/blob/1.x/docs/usage-publishing.md#one-post-every-network) and
[Reading](https://github.com/glitchr-omnisense/omnipost/blob/1.x/docs/usage-reading.md)); Instagram itself was not called. In Symfony:

```yaml
omnipost:
    providers:
        instagram:
            factory: instagram
            options:
                access_token: '%env(default::INSTAGRAM_ACCESS_TOKEN)%'
                app_id: '%env(default::INSTAGRAM_APP_ID)%'
                app_secret: '%env(default::INSTAGRAM_APP_SECRET)%'
```

## Errors

| Answer | Exception |
|---|---|
| HTTP 401, Graph code 190, subcode 463 or 467 | `UnauthorizedException`: the account is connected again |
| Graph code 4, 17, 32, 613 (call limits) | `ProviderException` (`Too many calls, try again later: ...`, `providerCode` the code) |
| any other error (HTTP 400 or more, or an `error` / `error_type` in the body) | `ProviderException` with the status and Graph's code |
| transport error | `ProviderException` (`Instagram could not be reached: ...`, the token masked) |
| no token in an OAuth answer | `ProviderException` |
| no `access_token`, `app_id` or `app_secret` for the call | `InvalidConfigException` naming it |
| a post the network would refuse | `InvalidPostException`, nothing sent |
| `delete()` | `NotSupportedException` |

Instagram caps the publications made through the API per account and per 24 hours; the provider
does not read the cap (`GET /<user>/content_publishing_limit`).

## Verified, and not

The package's tests (`Tests/InstagramProviderTest.php`, 12 tests) pass (2026-10-26, PHP 8.4) on
answers written in the shapes Instagram documents - not recorded from Instagram. No publication or
read was run against Instagram for this package (neither the README nor the history states one).

## Responsibility

This package is an independent, unofficial client, not affiliated with, endorsed by or sponsored by
Meta Platforms, Inc. (Instagram). Whoever runs it complies with the
[Meta Platform Terms](https://developers.facebook.com/terms/); see the README's Responsibility
section.
