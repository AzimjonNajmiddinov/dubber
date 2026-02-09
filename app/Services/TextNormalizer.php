<?php

namespace App\Services;

/**
 * Normalizes text for TTS - converts numbers to words, expands abbreviations, etc.
 */
class TextNormalizer
{
    /**
     * Normalize text for TTS in the given language.
     */
    public static function normalize(string $text, string $language): string
    {
        $lang = strtolower($language);

        // Normalize Uzbek special characters (o', g') for TTS
        if (in_array($lang, ['uz', 'uzbek'])) {
            $text = self::normalizeUzbekCharacters($text);
        }

        // Expand units/abbreviations FIRST (while numbers are still digits)
        $text = self::expandAbbreviations($text, $lang);

        // Convert numbers to words AFTER abbreviations
        $text = self::convertNumbersToWords($text, $lang);

        return $text;
    }

    /**
     * Normalize Uzbek special characters for TTS.
     *
     * Uzbek Latin uses o' and g' for special sounds:
     * - o' (oʻ) - open O sound (like "ö" or "aw" in "law")
     * - g' (gʻ) - voiced uvular fricative (like French "r" or soft "gh")
     *
     * XTTS doesn't natively understand these, so we convert them to
     * phonetic approximations that produce more accurate sounds.
     */
    private static function normalizeUzbekCharacters(string $text): string
    {
        // First, normalize all apostrophe variants to ASCII apostrophe
        $apostrophes = [
            "\u{2019}",  // RIGHT SINGLE QUOTATION MARK '
            "\u{2018}",  // LEFT SINGLE QUOTATION MARK '
            "\u{02BB}",  // MODIFIER LETTER TURNED COMMA ʻ
            "\u{02BC}",  // MODIFIER LETTER APOSTROPHE ʼ
            "\u{0060}",  // GRAVE ACCENT `
            "\u{00B4}",  // ACUTE ACCENT ´
            "\u{02C8}",  // MODIFIER LETTER VERTICAL LINE ˈ
            "\u{055A}",  // ARMENIAN APOSTROPHE ՚
        ];
        $text = str_replace($apostrophes, "'", $text);

        // Convert o' to phonetic "ö" (XTTS understands German/Turkish ö)
        // This produces the correct open-mid back rounded vowel
        $text = preg_replace("/o'/iu", "ö", $text);
        $text = preg_replace("/O'/u", "Ö", $text);

        // Convert g' to phonetic "gh" (voiced velar/uvular approximation)
        // "gh" is commonly understood by TTS as a softer g sound
        $text = preg_replace("/g'/iu", "gh", $text);
        $text = preg_replace("/G'/u", "Gh", $text);

        return $text;
    }

    /**
     * Convert numbers to words in the target language.
     */
    private static function convertNumbersToWords(string $text, string $lang): string
    {
        // Match numbers (including decimals and negative)
        return preg_replace_callback(
            '/\b(\d{1,3}(?:,\d{3})*(?:\.\d+)?|\d+(?:\.\d+)?)\b/',
            function ($matches) use ($lang) {
                $number = str_replace(',', '', $matches[1]);
                return self::numberToWords((float)$number, $lang);
            },
            $text
        );
    }

    /**
     * Convert a number to words in the target language.
     */
    private static function numberToWords(float $number, string $lang): string
    {
        // Handle decimals
        if (floor($number) != $number) {
            $intPart = (int)floor($number);
            $decPart = substr((string)$number, strpos((string)$number, '.') + 1);
            $intWords = self::integerToWords($intPart, $lang);
            $decWords = self::digitsToWords($decPart, $lang);
            $point = match($lang) {
                'uz', 'uzbek' => 'butun',
                'ru', 'russian' => 'целых',
                default => 'point',
            };
            return "{$intWords} {$point} {$decWords}";
        }

        return self::integerToWords((int)$number, $lang);
    }

    /**
     * Convert integer to words.
     */
    private static function integerToWords(int $number, string $lang): string
    {
        if ($number == 0) {
            return match($lang) {
                'uz', 'uzbek' => 'nol',
                'ru', 'russian' => 'ноль',
                default => 'zero',
            };
        }

        $isNegative = $number < 0;
        $number = abs($number);

        $words = match($lang) {
            'uz', 'uzbek' => self::integerToUzbek($number),
            'ru', 'russian' => self::integerToRussian($number),
            default => self::integerToEnglish($number),
        };

        if ($isNegative) {
            $minus = match($lang) {
                'uz', 'uzbek' => 'minus',
                'ru', 'russian' => 'минус',
                default => 'minus',
            };
            $words = "{$minus} {$words}";
        }

        return $words;
    }

    /**
     * Convert digits to individual words (for decimals).
     */
    private static function digitsToWords(string $digits, string $lang): string
    {
        // Use phonetic representations for Uzbek: o' → ö
        $digitWords = match($lang) {
            'uz', 'uzbek' => ['nol', 'bir', 'ikki', 'uch', 'tört', 'besh', 'olti', 'yetti', 'sakkiz', 'töqqiz'],
            'ru', 'russian' => ['ноль', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'],
            default => ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'],
        };

        $words = [];
        for ($i = 0; $i < strlen($digits); $i++) {
            $words[] = $digitWords[(int)$digits[$i]];
        }
        return implode(' ', $words);
    }

    /**
     * Convert integer to Uzbek words.
     */
    private static function integerToUzbek(int $n): string
    {
        if ($n == 0) return 'nol';

        // Use phonetic representations: o' → ö, g' → gh
        $ones = ['', 'bir', 'ikki', 'uch', 'tört', 'besh', 'olti', 'yetti', 'sakkiz', 'töqqiz'];
        $tens = ['', 'ön', 'yigirma', 'öttiz', 'qirq', 'ellik', 'oltmish', 'yetmish', 'sakson', 'töqson'];

        $words = [];

        // Billions
        if ($n >= 1000000000) {
            $words[] = self::integerToUzbek((int)($n / 1000000000)) . ' milliard';
            $n %= 1000000000;
        }

        // Millions
        if ($n >= 1000000) {
            $words[] = self::integerToUzbek((int)($n / 1000000)) . ' million';
            $n %= 1000000;
        }

        // Thousands
        if ($n >= 1000) {
            $thousands = (int)($n / 1000);
            if ($thousands == 1) {
                $words[] = 'ming';
            } else {
                $words[] = self::integerToUzbek($thousands) . ' ming';
            }
            $n %= 1000;
        }

        // Hundreds
        if ($n >= 100) {
            $hundreds = (int)($n / 100);
            if ($hundreds == 1) {
                $words[] = 'yuz';
            } else {
                $words[] = $ones[$hundreds] . ' yuz';
            }
            $n %= 100;
        }

        // Tens
        if ($n >= 10) {
            $words[] = $tens[(int)($n / 10)];
            $n %= 10;
        }

        // Ones
        if ($n > 0) {
            $words[] = $ones[$n];
        }

        return implode(' ', array_filter($words));
    }

    /**
     * Convert integer to Russian words.
     */
    private static function integerToRussian(int $n): string
    {
        if ($n == 0) return 'ноль';

        $ones = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
        $teens = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
        $tens = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
        $hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];

        $words = [];

        // Millions
        if ($n >= 1000000) {
            $millions = (int)($n / 1000000);
            $millionWord = self::getRussianPlural($millions, 'миллион', 'миллиона', 'миллионов');
            $words[] = self::integerToRussian($millions) . ' ' . $millionWord;
            $n %= 1000000;
        }

        // Thousands
        if ($n >= 1000) {
            $thousands = (int)($n / 1000);
            $thousandWord = self::getRussianPlural($thousands, 'тысяча', 'тысячи', 'тысяч');
            // Special: 1 = одна, 2 = две for thousands
            if ($thousands == 1) {
                $words[] = 'одна ' . $thousandWord;
            } elseif ($thousands == 2) {
                $words[] = 'две ' . $thousandWord;
            } else {
                $words[] = self::integerToRussian($thousands) . ' ' . $thousandWord;
            }
            $n %= 1000;
        }

        // Hundreds
        if ($n >= 100) {
            $words[] = $hundreds[(int)($n / 100)];
            $n %= 100;
        }

        // Teens
        if ($n >= 10 && $n < 20) {
            $words[] = $teens[$n - 10];
            $n = 0;
        }

        // Tens
        if ($n >= 20) {
            $words[] = $tens[(int)($n / 10)];
            $n %= 10;
        }

        // Ones
        if ($n > 0) {
            $words[] = $ones[$n];
        }

        return implode(' ', array_filter($words));
    }

    /**
     * Get Russian plural form.
     */
    private static function getRussianPlural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        if ($n >= 11 && $n <= 19) return $many;
        $n %= 10;
        if ($n == 1) return $one;
        if ($n >= 2 && $n <= 4) return $few;
        return $many;
    }

    /**
     * Convert integer to English words.
     */
    private static function integerToEnglish(int $n): string
    {
        if ($n == 0) return 'zero';

        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

        $words = [];

        // Billions
        if ($n >= 1000000000) {
            $words[] = self::integerToEnglish((int)($n / 1000000000)) . ' billion';
            $n %= 1000000000;
        }

        // Millions
        if ($n >= 1000000) {
            $words[] = self::integerToEnglish((int)($n / 1000000)) . ' million';
            $n %= 1000000;
        }

        // Thousands
        if ($n >= 1000) {
            $words[] = self::integerToEnglish((int)($n / 1000)) . ' thousand';
            $n %= 1000;
        }

        // Hundreds
        if ($n >= 100) {
            $words[] = $ones[(int)($n / 100)] . ' hundred';
            $n %= 100;
        }

        // Tens and ones
        if ($n >= 20) {
            $word = $tens[(int)($n / 10)];
            if ($n % 10 > 0) {
                $word .= '-' . $ones[$n % 10];
            }
            $words[] = $word;
        } elseif ($n > 0) {
            $words[] = $ones[$n];
        }

        return implode(' ', array_filter($words));
    }

    /**
     * Expand common abbreviations.
     */
    private static function expandAbbreviations(string $text, string $lang): string
    {
        $abbreviations = match($lang) {
            'uz', 'uzbek' => [
                // Use phonetic: o' → ö, g' → gh
                '/\bAQSh\b/i' => 'Amerika Qöshma Shtatlari',
                '/\bBMT\b/i' => 'Birlashgan Millatlar Tashkiloti',
                '/(?<=\d)\s*km\b/' => ' kilometr',
                '/(?<=\d)\s*m\b/' => ' metr',
                '/(?<=\d)\s*kg\b/' => ' kilogramm',
                '/(?<=\d)\s*sm\b/' => ' santimetr',
                '/(?<=\d)\s*mm\b/' => ' millimetr',
            ],
            'ru', 'russian' => [
                '/\bСША\b/i' => 'Соединённые Штаты Америки',
                '/\bООН\b/i' => 'Организация Объединённых Наций',
                '/(?<=\d)\s*км\b/' => ' километров',
                '/(?<=\d)\s*м\b/' => ' метров',
                '/(?<=\d)\s*кг\b/' => ' килограмм',
                '/(?<=\d)\s*см\b/' => ' сантиметров',
                '/(?<=\d)\s*мм\b/' => ' миллиметров',
            ],
            default => [
                '/\bUSA\b/i' => 'United States of America',
                '/\bUN\b/i' => 'United Nations',
                '/(?<=\d)\s*km\b/' => ' kilometers',
                '/(?<=\d)\s*kg\b/' => ' kilograms',
                '/(?<=\d)\s*m\b/' => ' meters',
                '/(?<=\d)\s*cm\b/' => ' centimeters',
                '/(?<=\d)\s*mm\b/' => ' millimeters',
            ],
        };

        foreach ($abbreviations as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }
}
