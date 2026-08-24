<?php

class shopPrefillPluginFillParams
{
    private ?int $id = null;

    private ?string $country      = null;
    private ?string $country_name = null;
    private ?string $region       = null;
    private ?string $region_name  = null;
    private ?string $city         = null;
    private ?string $zip          = null;
    private ?string $street       = null;

    private ?int    $shipping_id             = null;
    private ?string $shipping_rate_id        = null;
    private ?string $shipping_name           = null;
    private ?string $shipping_plugin         = null;
    private ?array  $shipping_custom         = null;
    private array   $shipping_address_custom = [];

    private ?int    $payment_id     = null;
    private ?string $payment_name   = null;
    private ?string $payment_plugin = null;
    private ?array  $payment_custom = null;

    private ?string $comment = null;

    // Auth секция
    private ?string $customer_type = null; // "person" или "company"
    private array   $auth_data     = [];         // Все поля auth[data] (email, phone, кастомные)

    private array $region_params   = ['country', 'region', 'city', 'zip', 'street'];
    private array $shipping_params
        = [
            'shipping_id',
            'shipping_rate_id',
            'shipping_name',
            'shipping_plugin',
            'shipping_custom',
        ];

    /**
     * Есть ли у источника данные для секции — в отличие от SectionChecker (тот же вопрос
     * про сессию), здесь про сам источник предзаполнения. Сейчас без вызовов — оставлен
     * намеренно как примитив для issue-84 §2 (полнота источника по группе delivery).
     */
    public function hasDataForSection(string $section_id): bool
    {
        switch ($section_id) {
            case 'auth':
                return ! empty($this->auth_data);
            case 'region':
                return ! empty($this->city) || ! empty($this->region_name) || ! empty($this->country_name);
            case 'shipping':
                // Вариант — единственная идентичность выбора доставки; shipping_plugin
                // намеренно не заполняется (см. FillParamsProvider — комментарий у
                // getFillParamsByOrderParams() про shipping_params_ и isSameDeliveryOption()).
                return $this->getShippingVariantId() !== null;
            case 'details':
                return ! empty($this->street) || ! empty($this->zip) || ! empty($this->shipping_address_custom);
            case 'payment':
                return ! empty($this->payment_id) || ! empty($this->payment_plugin);
            case 'confirm':
                return ! empty($this->comment);
            default:
                return false;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

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

    public function getRegion(): ?string
    {
        return $this->region;
    }

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

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): void
    {
        $this->city = $city;
    }

    public function getZip(): ?string
    {
        return $this->zip;
    }

    public function setZip(?string $zip): void
    {
        $this->zip = $zip;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): void
    {
        $this->street = $street;
    }

    public function getShippingId(): ?int
    {
        return $this->shipping_id;
    }

    public function setShippingId(?int $shipping_id): void
    {
        $this->shipping_id = $shipping_id;
    }

    public function getShippingRateId(): ?string
    {
        return $this->shipping_rate_id;
    }

    public function setShippingRateId(?string $shipping_rate_id): void
    {
        $this->shipping_rate_id = $shipping_rate_id;
    }

    public function getShippingName(): ?string
    {
        return $this->shipping_name;
    }

    public function setShippingName(?string $shipping_name): void
    {
        $this->shipping_name = $shipping_name;
    }

    public function getShippingPlugin(): ?string
    {
        return $this->shipping_plugin;
    }

    public function setShippingPlugin(?string $shipping_plugin): void
    {
        $this->shipping_plugin = $shipping_plugin;
    }

    public function getShippingCustom(): ?array
    {
        return $this->shipping_custom;
    }

    public function setShippingCustom(?array $shipping_custom): void
    {
        $this->shipping_custom = $shipping_custom;
    }

    /**
     * Возвращает кастомные поля адреса доставки (подъезд, этаж, домофон и т.д.)
     *
     * @return array Ассоциативный массив [field_id => value]
     */
    public function getShippingAddressCustom(): array
    {
        return $this->shipping_address_custom;
    }

    /**
     * Устанавливает кастомные поля адреса доставки
     *
     * @param array $shipping_address_custom Массив [field_id => value]
     */
    public function setShippingAddressCustom(array $shipping_address_custom): void
    {
        $this->shipping_address_custom = $shipping_address_custom;
    }

    public function getPaymentId(): ?int
    {
        return $this->payment_id;
    }

    public function setPaymentId(?int $payment_id): void
    {
        $this->payment_id = $payment_id;
    }

    public function getPaymentName(): ?string
    {
        return $this->payment_name;
    }

    public function setPaymentName(?string $payment_name): void
    {
        $this->payment_name = $payment_name;
    }

    public function getPaymentPlugin(): ?string
    {
        return $this->payment_plugin;
    }

    public function setPaymentPlugin(?string $payment_plugin): void
    {
        $this->payment_plugin = $payment_plugin;
    }

    public function getPaymentCustom(): ?array
    {
        return $this->payment_custom;
    }

    public function setPaymentCustom(?array $payment_custom): void
    {
        $this->payment_custom = $payment_custom;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $this->comment = $comment;
    }

    /**
     * Возвращает тип покупателя
     *
     * @return string|null "person" или "company"
     */
    public function getCustomerType(): ?string
    {
        return $this->customer_type;
    }

    /**
     * Устанавливает тип покупателя
     *
     * @param string|null $customer_type "person" или "company"
     */
    public function setCustomerType(?string $customer_type): void
    {
        $this->customer_type = $customer_type;
    }

    /**
     * Возвращает все данные auth секции
     *
     * @return array Ассоциативный массив [field_id => value]
     */
    public function getAuthData(): array
    {
        return $this->auth_data;
    }

    /**
     * Устанавливает данные auth секции
     *
     * @param array $auth_data Ассоциативный массив [field_id => value]
     */
    public function setAuthData(array $auth_data): void
    {
        $this->auth_data = $auth_data;
    }

    public function getShippingVariantId(): ?string
    {
        if (! is_null($this->getShippingId()) && ! is_null($this->getShippingRateId())) {
            return $this->getShippingId() . '.' . $this->getShippingRateId();
        }

        return null;
    }

    public function setShippingVariantId(string $variant_id): void
    {
        $parts = explode('.', $variant_id, 2);

        if (count($parts) === 2) {
            if ($parts[0] !== '' && $parts[1] !== '') {
                $this->setShippingId((int) $parts[0]);
                $this->setShippingRateId($parts[1]);
            }
        }
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** Возвращает true при полном совпадении параметров доставки. */
    public function isSameDeliveryOption(shopPrefillPluginFillParams $other): bool
    {
        $delivery_properties = array_merge($this->region_params, $this->shipping_params);

        foreach ($delivery_properties as $property) {
            // Имя доставки - это лишь текстовое описание, его нет в сессии
            if ($property === 'shipping_name') {
                continue;
            }

            if (! $this->areDeliveryValuesEqual($this->$property, $other->$property)) {
                return false;
            }
        }

        // Сравниваем кастомные поля адреса доставки
        if ($this->shipping_address_custom != $other->shipping_address_custom) {
            return false;
        }

        return true;
    }

    /**
     * Симметрично сравнивает одно скалярное или массивное поле доставки.
     *
     * null и '' считаются одной и той же незаполненностью, но null никогда
     * не совпадает с непустым значением или с массивом (иначе он снова
     * становится wildcard'ом, как было до issue-67). Сравнение скаляров
     * идёт через строковый (string) + строгий ===, чтобы не словить
     * приведение типов PHP для числовых строк (например '01' == '1').
     */
    private function areDeliveryValuesEqual($left, $right): bool
    {
        if (is_array($left) || is_array($right)) {
            return is_array($left) && is_array($right) && $left == $right;
        }

        return (string) ($left ?? '') === (string) ($right ?? '');
    }
}
