<?php

class shopPrefillPluginFillParams
{
    private bool $active = false;

    private ?int $id = null;

    private ?string $country = null;
    private ?string $country_name = null;
    private ?string $region = null;
    private ?string $region_name = null;
    private ?string $city = null;
    private ?string $zip = null;
    private ?string $street = null;

    private ?int $shipping_id = null;
    private ?string $shipping_type_id = null;
    private ?string $shipping_rate_id = null;
    private ?string $shipping_name = null;
    private ?string $shipping_plugin = null;
    private ?array $shipping_custom = null;

    private ?int $payment_id = null;
    private ?string $payment_name = null;
    private ?string $payment_plugin = null;
    private ?array $payment_custom = null;

    private ?string $comment = null;

    private array $region_params = ['country', 'region', 'city', 'zip', 'street'];
    private array $payment_params = ['payment_id', 'payment_name', 'payment_plugin', 'payment_custom'];
    private array $shipping_params
        = [
            'shipping_id',
            'shipping_type_id',
            'shipping_rate_id',
            'shipping_name',
            'shipping_plugin',
            'shipping_custom',
        ];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @param  bool  $active
     */
    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    /**
     * @return string|null
     */
    public function getCountry(): ?string
    {
        return $this->country;
    }

    /**
     * @param  string|null  $country
     */
    public function setCountry(?string $country): void
    {
        $this->country = $country;
    }

    public function getCountryName(): ?string
    {
        return $this->country_name;
    }

    public function setCountryName(?string $country_name): void
    {
        $this->country_name = $country_name;
    }

    /**
     * @return string|null
     */
    public function getRegion(): ?string
    {
        return $this->region;
    }

    /**
     * @param  string|null  $region
     */
    public function setRegion(?string $region): void
    {
        $this->region = $region;
    }

    public function getRegionName(): ?string
    {
        return $this->region_name;
    }

    public function setRegionName(?string $region_name): void
    {
        $this->region_name = $region_name;
    }

    /**
     * @return string|null
     */
    public function getCity(): ?string
    {
        return $this->city;
    }

    /**
     * @param  string|null  $city
     */
    public function setCity(?string $city): void
    {
        $this->city = $city;
    }

    /**
     * @return string|null
     */
    public function getZip(): ?string
    {
        return $this->zip;
    }

    /**
     * @param  string|null  $zip
     */
    public function setZip(?string $zip): void
    {
        $this->zip = $zip;
    }

    /**
     * @return string|null
     */
    public function getStreet(): ?string
    {
        return $this->street;
    }

    /**
     * @param  string|null  $street
     */
    public function setStreet(?string $street): void
    {
        $this->street = $street;
    }

    /**
     * @return int|null
     */
    public function getShippingId(): ?int
    {
        return $this->shipping_id;
    }

    /**
     * @param  int|null  $shipping_id
     */
    public function setShippingId(?int $shipping_id): void
    {
        $this->shipping_id = $shipping_id;
    }

    /**
     * @return string|null
     */
    public function getShippingTypeId(): ?string
    {
        return $this->shipping_type_id;
    }

    /**
     * @param  string|null  $shipping_type_id
     */
    public function setShippingTypeId(?string $shipping_type_id): void
    {
        $this->shipping_type_id = $shipping_type_id;
    }

    /**
     * @return string|null
     */
    public function getShippingRateId(): ?string
    {
        return $this->shipping_rate_id;
    }

    /**
     * @param  string|null  $shipping_rate_id
     */
    public function setShippingRateId(?string $shipping_rate_id): void
    {
        $this->shipping_rate_id = $shipping_rate_id;
    }

    /**
     * @return string|null
     */
    public function getShippingName(): ?string
    {
        return $this->shipping_name;
    }

    /**
     * @param  string|null  $shipping_name
     */
    public function setShippingName(?string $shipping_name): void
    {
        $this->shipping_name = $shipping_name;
    }

    /**
     * @return string|null
     */
    public function getShippingPlugin(): ?string
    {
        return $this->shipping_plugin;
    }

    /**
     * @param  string|null  $shipping_plugin
     */
    public function setShippingPlugin(?string $shipping_plugin): void
    {
        $this->shipping_plugin = $shipping_plugin;
    }

    /**
     * @return array|null
     */
    public function getShippingCustom(): ?array
    {
        return $this->shipping_custom;
    }

    /**
     * @param  array|null  $shipping_custom
     */
    public function setShippingCustom(?array $shipping_custom): void
    {
        $this->shipping_custom = $shipping_custom;
    }

    /**
     * @return int|null
     */
    public function getPaymentId(): ?int
    {
        return $this->payment_id;
    }

    /**
     * @param  int|null  $payment_id
     */
    public function setPaymentId(?int $payment_id): void
    {
        $this->payment_id = $payment_id;
    }

    /**
     * @return string|null
     */
    public function getPaymentName(): ?string
    {
        return $this->payment_name;
    }

    /**
     * @param  string|null  $payment_name
     */
    public function setPaymentName(?string $payment_name): void
    {
        $this->payment_name = $payment_name;
    }

    /**
     * @return string|null
     */
    public function getPaymentPlugin(): ?string
    {
        return $this->payment_plugin;
    }

    /**
     * @param  string|null  $payment_plugin
     */
    public function setPaymentPlugin(?string $payment_plugin): void
    {
        $this->payment_plugin = $payment_plugin;
    }

    /**
     * @return array|null
     */
    public function getPaymentCustom(): ?array
    {
        return $this->payment_custom;
    }

    /**
     * @param  array|null  $payment_custom
     */
    public function setPaymentCustom(?array $payment_custom): void
    {
        $this->payment_custom = $payment_custom;
    }

    /**
     * @return string|null
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * @param  string|null  $comment
     */
    public function setComment(?string $comment): void
    {
        $this->comment = $comment;
    }

    public function getShippingVariantId(): ?string
    {
        if (!is_null($this->getShippingId()) && !is_null($this->getShippingRateId())) {
            return $this->getShippingId() . '.' . $this->getShippingRateId();
        }

        return null;
    }

    public function setShippingVariantId(string $variant_id): void
    {
        $parts = explode('.', $variant_id);

        if (count($parts) === 2) {
            if ($parts[0] !== '' && $parts[1] !== '') {
                $this->setShippingId($parts[0]);
                $this->setShippingRateId($parts[1]);
            }
        }
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function mergePaymentParams(shopPrefillPluginFillParams $other): void
    {
        $this->mergeWith($other, $this->payment_params);
    }

    public function mergeWith(shopPrefillPluginFillParams $other, array $properties = null): void
    {
        // Если $properties не определен, сливаем все параметры
        if ($properties === null) {
            $properties = array_merge($this->region_params, $this->payment_params, $this->shipping_params);
        }

        foreach ($properties as $property) {
            $this->$property = $other->$property;
        }
    }
}