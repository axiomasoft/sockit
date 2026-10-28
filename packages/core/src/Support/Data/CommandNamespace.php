<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use SocKit\Attributes\Api;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class CommandNamespace
{
    public function __construct(public InstallationRef $installation, public PanelId $panel, public string $integration, public string $commandType)
    {
        if (! preg_match('~^[a-z][a-z0-9_.-]{0,63}$~D', $integration) || ! preg_match('~^[a-z][a-z0-9_.-]{0,119}$~D', $commandType) || strlen($this->toWire()) > 384) {
            throw new InvalidPayloadException('Invalid command namespace');
        }
    }

    public function toWire(): string
    {
        return $this->installation->id.'/'.$this->panel->value.'/'.$this->integration.'/'.$this->commandType;
    }

    public static function fromWire(string $wire): self
    {
        $parts = explode('/', $wire);
        if (count($parts) !== 4) {
            throw new InvalidPayloadException('Invalid namespace wire');
        }

        return new self(installation: new InstallationRef($parts[0]), panel: new PanelId($parts[1]), integration: $parts[2], commandType: $parts[3]);
    }

    public function equals(self $other): bool
    {
        return $this->toWire() === $other->toWire();
    }
}
