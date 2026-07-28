<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\PublicChannels\Ports;

use TropikalAI\Connect\Domain\PublicChannels\PublicComponentPlacement;
use TropikalAI\Connect\Domain\PublicChannels\PublicComponentType;

interface PublicComponentSettingsStore
{
    public function get(PublicComponentType $type): PublicComponentPlacement;

    public function save(PublicComponentPlacement $placement): void;
}
