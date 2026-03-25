<?php

class shopPrefillPluginFillParamsCollection
{
    private array $collection = [];

    public function get(): array
    {
        return $this->collection;
    }

    public function add(shopPrefillPluginFillParams $params): void
    {
        $this->collection[] = $params;
    }

    public function toArray(?int $limit = null): array
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

        return $result;
    }

}
