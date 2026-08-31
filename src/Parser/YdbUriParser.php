<?php

namespace Dudev\YdbDoctrine\Parser;

class YdbUriParser
{
    /** @return array<array-key, mixed> */
    public function parse(string $url): array
    {
        $data = parse_url($url);
        if (false === $data) {
            throw new \Exception("Malformed YDB connection URL: $url");
        }
        if ('ydb' !== ($data['scheme'] ?? null)) {
            throw new \Exception();
        }

        $endpoint = $data['host'] ?? throw new \Exception();
        if (isset($data['port'])) {
            $endpoint .= ':' . $data['port'];
        }

        $query = [];
        parse_str($data['query'] ?? '', $query);
        array_walk_recursive($query, fn (&$value) => match ($value) {
            'true' => $value = true,
            'false' => $value = false,
            default => $value,
        });

        return [
            'database' => $data['path'] ?? '',
            'endpoint' => $endpoint,
            'discovery' => false,
        ] + $query;
    }
}
