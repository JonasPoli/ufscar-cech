<?php

namespace App\Entity\Enum;

enum LanguageEnum: string
{
    case PORTUGUESE = 'pt';
    case SPANISH = 'es';
    case ENGLISH = 'en';

    public static function getDescription(self|string $language): string
    {
        if ($language instanceof self) {
            $languageCase = $language;
        } else {
            try {
                $languageCase = self::from($language);
            } catch (\ValueError) {
                return '<span class="text-slate-500">Idioma inválido</span>';
            }
        }

        return match ($languageCase) {
            self::PORTUGUESE => 'Português',
            self::SPANISH => 'Espanhol',
            self::ENGLISH => 'Inglês',
        };
    }

    public static function getFlag(self|string $language): string
    {
        if ($language instanceof self) {
            $languageCase = $language;
        } else {
            try {
                $languageCase = self::from($language);
            } catch (\ValueError) {
                return '<span class="text-slate-500">Idioma inválido</span>';
            }
        }

        return match ($languageCase) {
            self::PORTUGUESE => '<img src="/path/to/brazil-flag.svg" width="20"> Português',
            self::SPANISH => '<img src="/path/to/spain-flag.svg" width="20"> Espanhol',
            self::ENGLISH => '<img src="/path/to/uk-flag.svg" width="20"> Inglês',
        };
    }

    public static function getOptions(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[self::getDescription($case)] = $case->value;
        }

        return $options;
    }
}
