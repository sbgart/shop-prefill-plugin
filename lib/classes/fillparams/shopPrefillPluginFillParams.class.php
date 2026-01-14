<?php

class shopPrefillPluginFillParams
{
    private bool $active = false;

    private ?int $id = null;

    private ?string $country      = null;
    private ?string $country_name = null;
    private ?string $region       = null;
    private ?string $region_name  = null;
    private ?string $city         = null;
    private ?string $zip          = null;
    private ?string $street       = null;

    private ?int    $shipping_id      = null;
    private ?string $shipping_type_id = null;
    private ?string $shipping_rate_id = null;
    private ?string $shipping_name    = null;
    private ?string $shipping_plugin  = null;
    private ?array  $shipping_custom  = null;

    private ?int    $payment_id     = null;
    private ?string $payment_name   = null;
    private ?string $payment_plugin = null;
    private ?array  $payment_custom = null;

    private ?string $comment = null;

    // Auth секция
    private ?string $customer_type = null; // "person" или "company"
    private array   $auth_data     = [];         // Все поля auth[data] (email, phone, кастомные)

    // Главные поля контактов
    private ?string $title      = null; // Обращение
    private ?string $firstname  = null; // Имя
    private ?string $middlename = null; // Отчество
    private ?string $lastname   = null; // Фамилия
    private ?string $jobtitle   = null; // Должность
    private ?string $company    = null; // Компания
    private ?string $email      = null; // Email
    private ?string $phone      = null; // Телефон

    private array $region_params   = ['country', 'region', 'city', 'zip', 'street'];
    private array $auth_params     = ['customer_type', 'auth_data'];
    private array $contact_params  = ['title', 'firstname', 'middlename', 'lastname', 'jobtitle', 'company', 'email', 'phone'];
    private array $payment_params  = ['payment_id', 'payment_name', 'payment_plugin', 'payment_custom'];
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

    /**
     * Возвращает значение конкретного поля auth
     *
     * @param string $field_id ID поля
     * @return string|null Значение или null
     */
    public function getAuthField(string $field_id): ?string
    {
        return $this->auth_data[$field_id] ?? null;
    }

    /**
     * Устанавливает значение конкретного поля auth
     *
     * @param string $field_id ID поля
     * @param string|null $value Значение
     */
    public function setAuthField(string $field_id, ?string $value): void
    {
        if ($value !== null) {
            $this->auth_data[$field_id] = $value;
        } else {
            unset($this->auth_data[$field_id]);
        }
    }

    // === Геттеры и сеттеры для главных полей контактов ===

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(?string $firstname): void
    {
        $this->firstname = $firstname;
    }

    public function getMiddlename(): ?string
    {
        return $this->middlename;
    }

    public function setMiddlename(?string $middlename): void
    {
        $this->middlename = $middlename;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(?string $lastname): void
    {
        $this->lastname = $lastname;
    }

    public function getJobtitle(): ?string
    {
        return $this->jobtitle;
    }

    public function setJobtitle(?string $jobtitle): void
    {
        $this->jobtitle = $jobtitle;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): void
    {
        $this->company = $company;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
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

    public function mergeAuthParams(shopPrefillPluginFillParams $other): void
    {
        $this->mergeWith($other, $this->auth_params);
    }

    public function mergeContactParams(shopPrefillPluginFillParams $other): void
    {
        $this->mergeWith($other, $this->contact_params);
    }

    public function mergeWith(shopPrefillPluginFillParams $other, array $properties): void
    {
        foreach ($properties as $property) {
            $this->$property = $other->$property;
        }
    }
}
