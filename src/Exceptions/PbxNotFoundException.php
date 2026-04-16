<?php

namespace ApnTalk\LaravelFreeswitchEsl\Exceptions;

class PbxNotFoundException extends FreeSwitchEslException
{
    public static function forId(int $id): self
    {
        return new self("PBX node with ID [{$id}] not found.");
    }

    public static function forSlug(string $slug): self
    {
        return new self("PBX node with slug [{$slug}] not found.");
    }
}
