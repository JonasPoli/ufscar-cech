<?php
declare(strict_types=1);

namespace App\Twig;

use BadMethodCallException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/*****************
 *  to use in twig:
 *  {% set enumName = enum('EnumName') %}
 *  {{ enumName.ITEM.value }}
******************/

class EnumExtension extends AbstractExtension
{
    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('enum', [$this, 'getEnumClass']),
        ];
    }

    public function getEnumClass(string $enumName): object
    {
        // allow either short names (LanguageEnum) or fully qualified
        if (str_contains($enumName, '\\')) {
            $enumFQN = '\\' === $enumName[0] ? $enumName : '\\' . $enumName;
        } else {
            $candidates = [
                '\\App\\Enum\\' . $enumName,
                '\\App\\Entity\\Enum\\' . $enumName,
            ];

            $enumFQN = null;
            foreach ($candidates as $candidate) {
                if (enum_exists(ltrim($candidate, '\\'))) {
                    $enumFQN = $candidate;
                    break;
                }
            }

            $enumFQN ??= $candidates[0];
        }

        if (!enum_exists(ltrim($enumFQN, '\\'))) {
            throw new BadMethodCallException(\sprintf('Enum "%s" does not exist.', $enumFQN));
        }

        return new class($enumFQN) {
            public function __construct(private readonly string $enum)
            {
            }

            public function __call(string $name, array $arguments)
            {
                $enumFQN = sprintf('%s::%s', $this->enum, $name);

                if (defined($enumFQN)) {
                    return constant($enumFQN);
                }

                if (method_exists($this->enum, $name)) {
                    return $this->enum::$name(...$arguments);
                }

                throw new BadMethodCallException("Neither \"{$enumFQN}\" or \"{$enumFQN}::{$name}()\" exist in this runtime.");
            }
        };
    }
}
