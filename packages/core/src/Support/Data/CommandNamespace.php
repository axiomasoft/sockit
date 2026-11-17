<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use Packman\Attributes\Api;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Exceptions\InvalidPayloadException;
use Stringable;

/**
 * Panel- and installation-qualified idempotency namespace (`installation/panel/integration/commandType`).
 */
#[Api(since: '1.0')]
final readonly class CommandNamespace implements Stringable
{
    public const string INTEGRATION_PATTERN = '/\A[a-z][a-z0-9_.-]{0,63}\z/';

    public const string COMMAND_TYPE_PATTERN = '/\A[a-z][a-z0-9_.-]{0,119}\z/';

    public const int MAX_BYTES = 384;

    public function __construct(
        public InstallationRef $installation,
        public PanelId $panel,
        public string $integration,
        public string $commandType,
    ) {
        if (preg_match(self::INTEGRATION_PATTERN, $integration) !== 1) {
            throw InvalidPayloadException::invalid('CommandNamespace integration is invalid.', ['integration' => $integration], '/integration');
        }

        if (preg_match(self::COMMAND_TYPE_PATTERN, $commandType) !== 1) {
            throw InvalidPayloadException::invalid('CommandNamespace commandType is invalid.', ['command_type' => $commandType], '/command_type');
        }

        if (strlen($this->toWire()) > self::MAX_BYTES) {
            throw InvalidPayloadException::invalid('Serialized CommandNamespace exceeds 384 bytes.');
        }
    }

    public static function fromWire(string $wire): self
    {
        if (strlen($wire) > self::MAX_BYTES) {
            throw InvalidPayloadException::invalid('Serialized CommandNamespace exceeds 384 bytes.');
        }

        $parts = explode('/', $wire);

        if (count($parts) !== 4) {
            throw InvalidPayloadException::invalid('CommandNamespace must have 4 slash-separated segments.');
        }

        return new self(
            new InstallationRef($parts[0]),
            new PanelId($parts[1]),
            $parts[2],
            $parts[3],
        );
    }

    public function toWire(): string
    {
        return $this->installation->id.'/'.$this->panel->value.'/'.$this->integration.'/'.$this->commandType;
    }

    public function equals(self $other): bool
    {
        return $this->toWire() === $other->toWire();
    }

    public function __toString(): string
    {
        return $this->toWire();
    }
}
