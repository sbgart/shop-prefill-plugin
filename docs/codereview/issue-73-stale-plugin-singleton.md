# Issue 73 — `getInstance()` отдаёт объект от первого сработавшего хука; per-instance кэши значат не то, что написано в комментариях

**Статус:** ⬜ Открыта
**Приоритет:** 🟢 Низкий (сейчас не проявляется, но это мина под будущими правками)
**Сложность фикса:** 🔧 Небольшой
**Файлы:** `lib/shopPrefill.plugin.php` (`__construct`, `getInstance`), `lib/classes/sessionstorage/…` (`$prefilled`), `lib/classes/view/shopPrefillPluginAssetsManager.class.php` (`$assets_initialized`)

## Как это работает на самом деле

`waEvent::runPlugins()` (`wa-system/event/waEvent.class.php:238`) создаёт объект плагина заново на **каждый вызов хука**:

```php
$plugin = new $class($plugin_info);
```

За один рендер чекаута это 7 разных объектов `shopPrefillPlugin` (`checkout_before_auth` + шесть `checkout_render_*`), плюс отдельный на `frontend_head`.

При этом:

```php
public function __construct($info)
{
    parent::__construct($info);
    self::$instance ??= $this;   // ← фиксируется ПЕРВЫЙ объект и больше не меняется
}
```

Значит `shopPrefillPlugin::getInstance()` (его вызывают debug-контроллеры, `shopPrefillPluginDebug`, `StorefrontSettingProvider::syncCssFile`, все frontend-контроллеры) возвращает объект **не тот**, внутри которого сейчас выполняется хук — со своим набором лениво созданных провайдеров.

## Что из этого следует

1. **`SessionStorageProvider::$prefilled`** — поле экземпляра. Его комментарий обещает «уже предзаполнено в текущем запросе», но реально он защищает только от повторного вызова внутри одного хука. Между `frontend_head` и `checkout_before_auth` он всегда `false`, и вся цепочка предзаполнения выполняется заново. Так и задумано по факту, но по коду читается иначе — [issue-10](done/issue-10-double-get-fill-params.md) закрывали именно потому, что дублирование дорогое.
2. **`AssetsManager::$assets_initialized`** — та же история: защищает от двойного `init()` в одном хуке, а не в запросе.
3. **Расхождение состояний.** Debug-панель через `getInstance()` читает `SessionStorageProvider` первого экземпляра; хук работает со своим. Пока все провайдеры без собственного состояния (всё берётся из `wa()`), разницы нет — но любая попытка закэшировать что-то в поле объекта (например, fill_params) даст расхождение, которое будет крайне неприятно отлаживать.
4. Статические кэши работают как ожидается и переживают смену экземпляров: `self::$effective_storefront`, `SettingsModel::$cache`, `PluginsProvider::$*_cache`, `LocationProvider::$*_name_cache`, `Log::$configured_level`. Это правильный уровень для кэширования в этом плагине.

## Рекомендация

1. Либо обновлять синглтон (`self::$instance = $this;`), либо, что лучше, убрать `$instance` и всегда ходить через `wa('shop')->getPlugin('prefill')`, который кэшируется системой.
2. Кэши, которые должны жить весь запрос, делать **статическими** — как это уже сделано для эффективной витрины. Кандидат номер один — `getFillParams()` для гостя (см. [issue-63](issue-63-guest-hash-lookup-full-scan.md)).
3. Поправить комментарии у `$prefilled` и `$assets_initialized`, чтобы они описывали фактическую область действия («в пределах одного вызова хука»), а не запрос.
