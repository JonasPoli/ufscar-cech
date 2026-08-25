<?php

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

class IntToBooleanTransformer implements DataTransformerInterface
{
    /**
     * Transforms an integer (1 or 0) to a boolean (true or false).
     *
     * @param  int|null $value
     * @return bool
     */
    public function transform($value): bool
    {
        if (null === $value) {
            return false;
        }

        return (bool)$value;
    }

    /**
     * Transforms a boolean (true or false) to an integer (1 or 0).
     *
     * @param  bool $value
     * @return int
     */
    public function reverseTransform($value): int
    {
        return $value ? 1 : 0;
    }
}
