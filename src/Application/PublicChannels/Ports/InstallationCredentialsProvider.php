<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\PublicChannels\Ports;

use TropikalAI\Connect\Domain\PublicChannels\InstallationCredentials;

interface InstallationCredentialsProvider
{
    public function current(): ?InstallationCredentials;
}
