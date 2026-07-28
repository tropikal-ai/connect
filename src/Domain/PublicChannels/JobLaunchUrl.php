<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\PublicChannels;

final class JobLaunchUrl
{
    public static function build(
        string $controlPlaneUrl,
        string $installationPublicId,
        string $returnUrl,
    ): string {
        $controlPlaneOrigin = Origin::fromUrl($controlPlaneUrl);
        if (! str_starts_with(trim($installationPublicId), 'cfi_')) {
            throw new \InvalidArgumentException('A connected installation is required.');
        }
        Origin::fromUrl($returnUrl);

        return $controlPlaneOrigin->value.'/jobs/create?'.http_build_query([
            'installation_public_id' => trim($installationPublicId),
            'return_url' => $returnUrl,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
