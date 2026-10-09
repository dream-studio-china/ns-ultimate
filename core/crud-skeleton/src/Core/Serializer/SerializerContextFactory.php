<?php

namespace App\Core\Serializer;

final class SerializerContextFactory
{
    /**
     * Build a canonical serializer context.
     *
     * Options recognized:
     * - groups: array|string
     * - max_depth: int
     * - enable_max_depth: bool
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function create(array $options = []): array
    {
        $context = [];

        if (isset($options['groups'])) {
            $context['groups'] = (array) $options['groups'];
        }

        if (isset($options['max_depth'])) {
            $context['max_depth'] = (int) $options['max_depth'];
            $context['enable_max_depth'] = $options['enable_max_depth'] ?? true;
        }

        // Add any other defaults you want here
        return $context;
    }
}

