<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\PublicChannels;

use TropikalAI\Connect\Application\PublicChannels\Ports\PublicComponentSettingsStore;
use TropikalAI\Connect\Domain\PublicChannels\PublicComponentPlacement;
use TropikalAI\Connect\Domain\PublicChannels\PublicComponentType;

final readonly class ChangePublicComponentPlacement
{
    public function __construct(private PublicComponentSettingsStore $settings) {}

    public function handle(PublicComponentType $type, bool $autoInject): PublicComponentPlacement
    {
        $placement = new PublicComponentPlacement($type, $autoInject);
        $this->settings->save($placement);

        return $placement;
    }
}
