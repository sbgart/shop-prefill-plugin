<?php

/**
 * Город покупателя, который плагин передаёт сторонним плагинам выбора города.
 *
 * Неизменяемый объект-значение. Формат полей совпадает с тем, что хранит заказ
 * (`shipping_address.*`) и что ждут оба соседних плагина: страна — iso3 (`rus`),
 * регион — код региона Webasyst (`77`), город — название, индекс — строка.
 * Конвертации между ними не требуется.
 *
 * Подпись (`signature()`) — единственный способ сравнивать состояния: по ней плагин
 * отличает «в чужом хранилище лежит то, что записали мы» от «покупатель сменил город
 * сам». Сравнивать по `order.region` нельзя — туда пишет и ядро (P2).
 */
class shopPrefillPluginGeoTarget
{
    private string $country;
    private string $region;
    private string $city;
    private string $zip;

    public function __construct(?string $country, ?string $region, ?string $city, ?string $zip)
    {
        $this->country = trim((string) $country);
        $this->region  = trim((string) $region);
        $this->city    = trim((string) $city);
        $this->zip     = trim((string) $zip);
    }

    public static function fromFillParams(shopPrefillPluginFillParams $params): self
    {
        return new self(
            $params->getCountry(),
            $params->getRegion(),
            $params->getCity(),
            $params->getZip()
        );
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        if (! is_array($data)) {
            return new self(null, null, null, null);
        }

        return new self(
            $data['country'] ?? null,
            $data['region'] ?? null,
            $data['city'] ?? null,
            $data['zip'] ?? null
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'country' => $this->country,
            'region'  => $this->region,
            'city'    => $this->city,
            'zip'     => $this->zip,
        ];
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getZip(): string
    {
        return $this->zip;
    }

    /**
     * Без города подставлять нечего: именно он — предмет всей интеграции.
     */
    public function isEmpty(): bool
    {
        return $this->city === '';
    }

    /**
     * Подпись для сравнения состояний. Индекс намеренно не входит: сторонние плагины
     * его теряют и восстанавливают по-своему, и расхождение по одному индексу не
     * означает, что город сменил человек.
     */
    public function signature(): string
    {
        return mb_strtolower($this->country . '|' . $this->region . '|' . $this->city);
    }

    public function equals(shopPrefillPluginGeoTarget $other): bool
    {
        return $this->signature() === $other->signature();
    }
}
