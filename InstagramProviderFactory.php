<?php

namespace Omnipost\Instagram;

use Omnipost\Config;
use Omnipost\ProviderFactory;
use Omnipost\ProviderInterface;

/**
 * Instagram, through the "Instagram API with Instagram Login": the account
 * and its feed, pictures, carousels, reels and stories published, the
 * long-lived token refreshed, the account connected from a back office.
 *
 *   options:
 *     access_token: '%env(default::INSTAGRAM_ACCESS_TOKEN)%' # the long-lived token (60 days): everything but the OAuth dance
 *     app_id: '%env(default::INSTAGRAM_APP_ID)%'             # the Instagram app's id and secret: authorizationUrl(), exchange()
 *     app_secret: '%env(default::INSTAGRAM_APP_SECRET)%'
 *     user_id: me                                            # the account the token belongs to
 *     share_to_feed: true                                    # a reel also shown on the profile's grid
 *     api_version: v23.0
 *     base_uri: https://graph.instagram.com
 *
 * Nothing is required to build the provider: capabilities() and
 * authorizationUrl() need no token. A call that needs one and finds none
 * throws InvalidConfigException.
 */
final class InstagramProviderFactory extends ProviderFactory
{
    protected function populate(Config $config): void
    {
        $config->defaults([
            'omnipost.factory_name' => 'instagram',
            'omnipost.required_options' => [],
            'access_token' => null,
            'app_id' => null,
            'app_secret' => null,
            'user_id' => 'me',
            'share_to_feed' => true,
            'api_version' => Api::VERSION,
            'base_uri' => Api::BASE_URI,
        ]);
    }

    protected function build(Config $config): ProviderInterface
    {
        return new InstagramProvider(
            new Api($config['access_token'] ? (string) $config['access_token'] : null, (string) $config['base_uri'], (string) $config['api_version'], $this->http),
            (string) $config['user_id'],
            $config['app_id'] ? (string) $config['app_id'] : null,
            $config['app_secret'] ? (string) $config['app_secret'] : null,
            filter_var($config['share_to_feed'], \FILTER_VALIDATE_BOOL),
        );
    }
}
