<?php

declare(strict_types=1);

namespace Piwigo\Language;

final readonly class LanguageService
{
    public function __construct(
        private LanguageRepository $languageRepository,
    ) {
    }

    /** @return array<string,string> */
    public function getActiveLanguages(): array
    {
        $languages = [];
        foreach ($this->languageRepository->findIdNameMap() as $id => $name) {
            if (is_dir(PHPWG_ROOT_PATH . 'language/' . $id)) {
                $languages[$id] = $name;
            }
        }
        return $languages;
    }
}
