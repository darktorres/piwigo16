<?php

declare(strict_types=1);

namespace Piwigo\Language;

use Piwigo\Core\Paths;

final readonly class LanguageService
{
    public function __construct(
        private LanguageRepository $languageRepository,
        private Paths $paths,
    ) {
    }

    /** @return array<string,string> */
    public function getActiveLanguages(): array
    {
        $languages = [];
        foreach ($this->languageRepository->findIdNameMap() as $id => $name) {
            if (is_dir($this->paths->root . 'language/' . $id)) {
                $languages[$id] = $name;
            }
        }
        return $languages;
    }
}
