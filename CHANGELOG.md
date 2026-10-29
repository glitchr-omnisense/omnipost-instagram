# Changelog

## [Unreleased]

### Fixed

- A failure no longer carries the token: every trace of what the API threw kept the options (the
  `access_token` in the query or the body) in its arguments. No exception it throws keeps them now;
  an unreachable Instagram's message has the token masked (`Http\Redaction::text()`) and its
  previous is a copy of the client's exception (the same class and code), where none was chained.
  Needs omnisense/omnipost's `Http\Redaction`.
- The post's link (`Post::$link`) is left out of the caption, where Instagram makes no link of an
  address (`Capabilities::$linkInCaption` false), and the validator does not count it.
