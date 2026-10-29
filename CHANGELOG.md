# Changelog

## [Unreleased]

### Fixed

- The post's link (`Post::$link`) is left out of the caption, where Instagram makes no link of an
  address (`Capabilities::$linkInCaption` false), and the validator does not count it.
