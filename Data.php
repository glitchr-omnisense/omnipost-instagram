<?php

namespace Omnipost\Instagram;

use Omnipost\Exception\ProviderException;
use Omnisense\Contracts\Http\Data as KernelData;

/**
 * Instagram's decoded answers read without trusting their shape: the
 * kernel's Data (get() by key or path, has(), data(), list()...), and the
 * reads that keep the values the provider handed on as they came. What PHP
 * refused with a TypeError (a list where a string or an object belongs) is
 * malformed(): a ProviderException.
 *
 * @internal
 */
final readonly class Data extends KernelData
{
    /**
     * What a ?string parameter took: null, a scalar as PHP gives it ("" kept, nothing trimmed);
     * a list or an object is malformed().
     */
    public function stringOrNull(string|int $key): ?string
    {
        $value = $this->get($key);
        if (null === $value || \is_scalar($value)) {
            return null === $value ? null : (string) $value;
        }

        throw self::malformed();
    }

    /**
     * The objects of a list (none when absent); a list that is not one, or an item that is not
     * an object, is malformed().
     *
     * @return list<static>
     */
    public function objects(string|int $key): array
    {
        $value = $this->get($key);
        if (null === $value) {
            return [];
        }
        if (!\is_array($value)) {
            throw self::malformed();
        }
        $objects = [];
        foreach ($value as $item) {
            $objects[] = \is_array($item) ? static::of($item) : throw self::malformed();
        }

        return $objects;
    }

    public static function malformed(): ProviderException
    {
        return new ProviderException(Api::PROVIDER, 'Instagram answered in another shape than its API\'s.');
    }
}
