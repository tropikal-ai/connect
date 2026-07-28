<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\PublicChannels;

final readonly class InstallationCredentials
{
    public Origin $origin;

    public string $controlPlaneUrl;

    public function __construct(
        public string $installationPublicId,
        public string $signingSecret,
        public string $siteUrl,
        string $controlPlaneUrl = 'https://app.tropikal.ai',
    ) {
        if (! str_starts_with(trim($installationPublicId), 'cfi_')) {
            throw new \InvalidArgumentException('A connected installation public id is required.');
        }
        if (trim($signingSecret) === '') {
            throw new \InvalidArgumentException('A connected installation signing key is required.');
        }
        $this->origin = Origin::fromUrl($siteUrl);
        $this->controlPlaneUrl = Origin::fromUrl($controlPlaneUrl)->value;
    }
}
