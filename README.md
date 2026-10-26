# omnipost/instagram

Instagram for [omnisense/omnipost](https://github.com/glitchr-omnisense/omnipost): the account and its
feed, pictures, carousels, reels and stories published, the long-lived token refreshed and the
account connected from a back office - through the *Instagram API with Instagram Login*
(`graph.instagram.com`), no Facebook Page needed.

```php
$instagram = (new InstagramProviderFactory($http))->create(['access_token' => '...']);   // $http: the application's HTTP client; none given, the factory makes its own
```

No framework needed: the package requires `omnisense/omnipost` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnipost:
    providers:
        instagram:
            factory: instagram
            options:
                access_token: '%env(default::INSTAGRAM_ACCESS_TOKEN)%'  # the long-lived token: everything but connecting
                app_id: '%env(default::INSTAGRAM_APP_ID)%'              # the Instagram app's id and secret: authorizationUrl(), exchange()
                app_secret: '%env(default::INSTAGRAM_APP_SECRET)%'
                user_id: me                                             # the account the token belongs to
                share_to_feed: true                                     # a reel also on the profile's grid
                api_version: v23.0
                base_uri: https://graph.instagram.com
```

No option is required to build the provider: `capabilities()` and `authorizationUrl()` need no
token, and a call that needs one and finds none throws `InvalidConfigException`.

## Publishing, in three steps

```php
$publication = $instagram->publish($post->for(Platform::INSTAGRAM));  // PENDING, the container's id
$publication = $instagram->status($publication->id);                  // PROCESSING while Instagram transcodes
$publication = $instagram->status($publication->id);                  // FINISHED: published here - PUBLISHED, the media's id, its permalink
```

1. `publish()` checks the post (`InvalidPostException`, nothing sent), then creates a *container*:
   Instagram fetches the media from its URL (`video_url`, `image_url`). A carousel is a container
   per item (`is_carousel_item`), then theirs (`children`); a story is `media_type=STORIES`.
2. `status(container)` reads it: `IN_PROGRESS` is PROCESSING, `ERROR`/`EXPIRED` FAILED with
   Instagram's reason.
3. Once the container is `FINISHED`, `status()` publishes it (`media_publish`) and answers
   PUBLISHED with the **media's id** - the Publication's id changes there: keep the latest one.
   `status(mediaId)` answers PUBLISHED with the permalink. Two workers must not poll one
   container at once (it would be published twice): hold a lock around `status()`.

`delete()` throws `NotSupportedException`: the API cannot delete a post, the account does it in the
application. Instagram also caps the publications through the API per account and per 24 hours
(`GET /{user}/content_publishing_limit` says where the account stands).

`capabilities()` are one set for every kind - the Validator's - so `mediaMaxCount` (10) is the
carousel's, 3 s - 15 min the reel's, 9:16 the reel's and the story's ratio, 4:5 and 1:1 the
picture's. `validate()` adds what depends on the kind (one media unless a carousel, 2 to 10 for
one, a reel is a 9:16 video) and is what `publish()` runs.

## Reading

`account()`: the username, name, picture, followers, posts count, account type. `feed($cursor,
$limit)`: the posts, newest first - a reel (`media_product_type` REELS) is REEL, another video
VIDEO, CAROUSEL_ALBUM CAROUSEL with its children, a picture IMAGE - with likes and comments, and
`next` the cursor of the next page.

To show **someone else's** public post, no API is needed: Instagram's embed (the
`<blockquote class="instagram-media" data-instgrm-permalink="...">` and `//www.instagram.com/embed.js`).

## What it takes

- A **professional** Instagram account (Business or Creator: Settings → Account type).
- A **Meta app** (developers.facebook.com, type *Business*) with the **Instagram API with
  Instagram Login** product, the permissions `instagram_business_basic` and
  `instagram_business_content_publish`, the site's redirect URI declared.
- While the app is in **development mode**, the artist's account is added as an **Instagram
  Tester** (App roles → Roles) and accepts the invitation (Instagram → Settings → Apps and
  websites). That is all a site posting on its own account needs: **no App Review**. App Review
  (2 to 4 weeks, a screencast of every permission in use, a privacy policy) only comes in to serve
  accounts that are not the app's testers - third parties.
- A **token**: generated in the app's dashboard for the tester's account, or obtained with
  `authorizationUrl()` + `exchange()` (a short-lived token, then the long-lived one). It lives
  **60 days**; `refresh()` gives 60 more to a token at least 24 hours old and not dead yet - a
  monthly cron, the new token stored. A dead token is an `UnauthorizedException`: the account
  is connected again.
- The media at a **public URL** Meta's servers can fetch (a signed, temporary URL will do).
  Reels: MP4 or MOV, H.264 and AAC, 9:16, 3 seconds to 15 minutes, at most 1 GB; pictures: JPEG,
  at most 8 MB, 4:5 to 1.91:1 (here 4:5 and 1:1, the formats a site renders).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Meta Platforms, Inc. (Instagram). It reaches their service on behalf of whoever runs
it, and it does not redistribute their data beyond the short excerpts recorded as test fixtures. Its
MIT licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Meta's terms ([Meta
Platform Terms](https://developers.facebook.com/terms/)), for obtaining whatever they require (an
account, a key, a licence or subscription, an attribution or a citation, a rate limit to respect),
and for any use made of what it returns. The authors publish it free of charge, make no commercial
use of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
