<?php

class shopPrefillPluginFillParamsCollection
{
    private array $collection = [];

    public function get(): array
    {
        return $this->collection;
    }

    public function getById(int $id = 0): ?shopPrefillPluginFillParams
    {
        if (isset($this->collection[$id])) {
            return $this->collection[$id];
        }

        return null;
    }


    public function add(shopPrefillPluginFillParams $params): void
    {
        $this->collection[] = $params;
    }

    public function toArray(bool $sort = false, ?int $limit = null): array
    {
        $result = [];

        $count = 0;
        foreach ($this->get() as $fill_params) {
            if ($limit !== null && $count >= $limit) {
                break; // Прекращаем добавление элементов, если достигнут лимит
            }
            $result[] = $fill_params->toArray();
            $count++;
        }

        if ($sort) {
            uasort($result, function ($a, $b) {
                return strcmp($a["sort"], $b["sort"]);
            });
        }

        return $result;
    }

}