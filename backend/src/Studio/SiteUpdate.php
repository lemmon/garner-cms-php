<?php

declare(strict_types=1);

namespace Garner\Studio;

use Garner\Content\SiteRepository;
use Illuminate\Support\Str;

final class SiteUpdate
{
    public function __construct(
        private readonly SiteRepository $siteRepository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function update(string $title): array
    {
        $site = $this->siteRepository->read();
        $normalizedTitle = Str::squish($title);
        $site['title'] = $normalizedTitle;
        $site['updated_at'] = gmdate(DATE_ATOM);

        $this->siteRepository->save($site);

        return [
            'ok' => true,
            'site' => [
                'id' => is_string($site['id'] ?? null) ? $site['id'] : 'site',
                'title' => $normalizedTitle,
                'error_page_id' => is_string($site['error_page_id'] ?? null)
                    ? $site['error_page_id']
                    : null,
                'home_page_id' => is_string($site['home_page_id'] ?? null)
                    ? $site['home_page_id']
                    : null,
            ],
        ];
    }
}
