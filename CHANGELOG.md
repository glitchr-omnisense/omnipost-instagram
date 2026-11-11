# Changelog

## [Unreleased]

### Added

- `policy.json` (`extra.omnisense.policy`), read 2026-11-10: 100 API-published posts a day per
  account (`publish`), commercial use allowed, Platform Data kept only while needed and never sold
  or licensed, the business use case limit (4,800 × impressions) and access levels in the notes.
  `InstagramProvider` declares it (`DeclaresUsagePolicyInterface`).
- `Api` takes the provider's wait (its last argument, from `Config::backoff()`): no call while one
  lasts; a 429 and Meta's codes 4, 17, 32, 613 and 80002 (`RATE_LIMIT_CODES`, 80002 added) are a
  `RateLimitedException`, a `ProviderException` with the same message as before, its `retryAfter`
  Meta's `estimated_time_to_regain_access` (X-Business-Use-Case-Usage, `Api::regain()`) or a
  Retry-After, which holds the next calls. Nothing retries: a container or a `media_publish`
  is sent once (`Tests/PolicyTest.php`: a 429 and a 500 on the writes, the 101st post never sent).
- PHPStan at level max (`phpstan.neon`), the 61 errors there were in `phpstan-baseline.neon`.
- PHPStan at level max with an empty baseline, types only: `Data` reads the answers (the same
  values as the `(string)` / `(int)` casts, an array giving "" / 0 rather than "Array" / 1), the
  factory reads its options with `Config::string()`; a `thumb_offset` option that is not a scalar
  is not sent.

### Fixed

- An answer without an id is no post: a container, a carousel item or a `media_publish` answered
  with no id, an empty one or one that is not a string nor a number is a `ProviderException` (it was
  a publication with the id `""` or `"Array"`, and a carousel sent with an empty child); a
  `status_code` that is not a string is never taken for `FINISHED` (`true` was, and published the
  container); a media read back with an id that is not one keeps the id asked for.
- An answer of another shape than the API's is a `ProviderException` ("Instagram answered in another
  shape than its API's."), not a `TypeError`: a feed's `data` or a carousel's children that are not a
  list, an item that is not an object, a caption, URL, timestamp, cursor, account name or permalink
  that is a list (`Data`, internal).
- A failure no longer carries the token: every trace of what the API threw kept the options (the
  `access_token` in the query or the body) in its arguments. No exception it throws keeps them now;
  an unreachable Instagram's message has the token masked (`Http\Redaction::text()`) and its
  previous is a copy of the client's exception (the same class and code), where none was chained.
  Needs omnisense/omnipost's `Http\Redaction`.
- The post's link (`Post::$link`) is left out of the caption, where Instagram makes no link of an
  address (`Capabilities::$linkInCaption` false), and the validator does not count it.
