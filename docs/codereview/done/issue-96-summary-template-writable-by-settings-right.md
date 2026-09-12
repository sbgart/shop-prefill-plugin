# Issue 96 — Правило B4 называет не тот гейт: `summary_template` пишется по праву `shop:settings`, а не `isAdmin('shop')`

**Статус:** ✅ Исправлена 09.09.2026, в день находки — гейт `isAdmin('shop')` добавлен на пути записи (`stripTemplateWritesForNonAdmin()`), вход на запись прогоняется через схему (`filterKnown()`), B4 переформулирован
**Приоритет:** 🟠 Важно до продажи
**Сложность фикса:** 🔨 Средний (решение — какой должна быть модель доступа; правка кода мелкая)
**Файлы:** `lib/shopPrefill.plugin.php` (`saveSettings()`), `lib/classes/settings/providers/shopPrefillPluginStorefrontSettingProvider.class.php` (`saveSettings()`), `docs/concept/RULES.md` (B4)

## Как это работает сейчас

B4 утверждает, что редактор шаблона сводки — это исполнение произвольного PHP, и что защищает его
единственная вещь:

> Единственная защита — `isAdmin('shop')` в базовом контроллере настроек + CSRF ядра: доступ к
> редактору эквивалентен доступу к штатному редактору тем Webasyst, не выше и не ниже.

Первая половина верна **только для чтения и превью**. Экшены плагина действительно закрыты:

```php
// shopPrefillPluginSettingsBaseAction::execute()
if (!wa()->getUser()->isAdmin(shopPrefillPlugin::APP_ID)) {
    throw new waRightsException();
}
```

Но **сохранение настроек через них не идёт**. Форма плагина отправляется штатным контроллером ядра:

```html
<!-- wa-apps/shop/templates/actions/plugins/PluginsSettings.html:26 -->
<form action="?module=plugins&id={$plugin_id}&action=save" method="post" …>
```

```php
// wa-system/plugin/actions/waPlugins.actions.php:94
public function saveAction()
{
    $plugin = waSystem::getInstance()->getPlugin($plugin_id);
    $settings = (array)$this->getRequest()->post($namespace);
    …
    $response = (array)$plugin->saveSettings($settings);
}
```

Собственной проверки прав у `saveAction()` нет. Гейт — `preExecute()` подкласса приложения:

```php
// wa-apps/shop/lib/actions/plugins/shopPlugins.actions.php
protected function preExecute() {
    if (!$current_user->getRights('shop', 'settings')) {
        throw new waException(_w('Access denied'));
    }
}
```

`settings` — гранулярное право, отдельный пункт в интерфейсе доступа
(`shopRightConfig.class.php:43`, `$this->addItem('settings', _w('Can manage settings'))`), выдаваемое
независимо от административного. `isAdmin('shop')` его включает, обратное неверно.

Второе наблюдение того же места: на записи схема настроек не применяется вовсе. `validate()` зовётся
только в `getSettings()`; `saveSettings()` плоско разворачивает то, что пришло:

```php
// shopPrefillPluginStorefrontSettingProvider::saveSettings()
foreach ($settings as $key => $value) {
    $this->flattenSettings($key, $value, null, $collect);
}
$this->model->setBulk($storefront_code, $entries);
```

Белого списка ключей нет — в `shop_prefill_settings` осядет любая структура из POST.

## Что из этого следует

Конкретный сценарий. Сотрудник магазина, которому выдали право «Управление настройками», но не
администратора, открывает бэкенд (CSRF-токен у него легитимный) и шлёт:

```
POST ?module=plugins&id=prefill&action=save
shop_prefill[storefront][*][zen][groups][payment][summary_template]={$x|shell_exec}
```

`findStorefront('*')` возвращает глобальную витрину — код угадывать не нужно. Шаблон сохраняется и
дальше рендерится `$view->fetch('string:' . $template)` на **каждой** странице оформления заказа у
**каждого** покупателя. Smarty 3.1.14 в Webasyst работает без `enableSecurity()`, поэтому модификатор
в шаблоне — любая PHP-функция (это и есть содержание B4).

При этом сам редактор шаблона такому пользователю недоступен: `SettingsAction`, `TemplateEditor` и
`TemplatePreview` требуют `isAdmin('shop')`. То есть форма закрыта, а запись — нет.

Насколько это повышение привилегий по сравнению со стоковым магазином — открытый вопрос, и ответ на
него определяет фикс. Право `design` («Can edit design», редактор тем — то, с чем B4 сравнивает
риск) — **отдельный** пункт, не следующий из `settings`. Поэтому владелец только `settings` получает
здесь исполнение кода, которого через темы у него нет. Но сравнивать надо не с темами, а со всем, до
чего `settings` дотягивается в стоковом Shop-Script (печатные формы и настройки плагинов доставки
и оплаты гейтятся тем же правом — `shopPrintformPlugin.class.php:56`). Этот замер не проводился.

Что можно утверждать без него: **B4 описывает механизм защиты, которого на пути записи нет.**
Читатель правила, принимая решение о новом редактируемом поле, будет опираться на неверную посылку.

## Как исправлено

Решение по п.2 — гейт нужен: `settings` открывает доступ к печатным формам и настройкам плагинов
доставки/оплаты в стоковом Shop-Script, но не к исполнению произвольного PHP на каждой странице
чекаута каждого покупателя — такого прироста прав через `settings` в стоке нет, а именно это давал
незакрытый путь записи.

1. `RULES.md` B4 переписан: называет оба реальных гейта (чтение/превью — `isAdmin('shop')` в
   `shopPrefillPluginSettingsBaseAction`; запись — `getRights('shop','settings')` в
   `shopPluginsActions::preExecute()` + CSRF ядра, теперь дополненный собственным `isAdmin` на
   опасной ветке) вместо одного `isAdmin()`, которого на пути записи не было.
2. `shopPrefillPluginStorefrontSettingProvider::saveSettings()` получил
   `stripTemplateWritesForNonAdmin()`: если сохраняющий не `isAdmin(shopPrefillPlugin::APP_ID)`,
   из входа вырезаются `zen.groups.{customer,delivery,payment}.{summary_template,custom_templates}`
   — отсутствующие в `$entries` ключи `setBulk()` просто не трогает, старое значение в БД остаётся.
   Цвета, тумблеры и прочие поля той же формы не тронуты — под правом `settings` пишутся как раньше.
3. Обе `saveSettings()` (глобальная и per-storefront) прогоняют вход через новый
   `shopPrefillPluginSettingGroup::filterKnown()` — зеркало `validate()`, но идёт от входа, а не от
   схемы: оставляет только известные схеме ключи и применяет фильтр известного листа, не
   подставляя дефолт за отсутствующую ветку (иначе частичное сохранение стирало бы остальное дерево
   до дефолтов — комментарий у `GeoIntegrations::sanitize()` подтверждает, что частичные сохранения
   штатны). Неизвестный ключ POST (сценарий из issue) в БД больше не попадает.
4. Проверено и кодом, и вживую. `tests/StorefrontSettingsWriteGateTest.php` (13 проверок) —
   `filterKnown()` режет неизвестные ключи и не подставляет дефолты при частичном входе,
   `stripTemplateWritesForNonAdmin()` вырезает шаблонные ветки для не-админа и не трогает дерево для
   админа. Живой сценарий на стенде: контакт с `shop:backend=1`+`shop:settings=1`, без `isAdmin`
   (в частности, **без** `webasyst:backend` — этот флаг делает суперадмином на все приложения сразу,
   `waContactRightsModel::get()` строка 48 — первая попытка сценария по этой причине ложно не
   воспроизвела баг, разобрано и переиграно правильно) шлёт POST на
   `?module=plugins&id=prefill&action=save` с `zen.groups.payment.summary_template` — запрос
   проходит (право `settings` есть), `active` в том же запросе сохраняется, а `summary_template`
   остаётся прежним. Admin-путь проверен той же формой — `summary_template` пишется как раньше.
   Тестовый контакт/группа/права и изменённые значения убраны сразу после проверки.

   **Что именно наблюдалось, а что нет.** Состояние «после фикса» прогнано вживую, оба сценария
   (не-админ и админ). Состояние «до фикса» вживую **не воспроизводилось**: в исходном коде на пути
   записи не было проверки прав вообще, это видно из диффа и не требует замера. Первые две попытки
   сценария шаблон записали, но не как доказательство «до фикса» — у тестового контакта тогда было
   право `webasyst:backend`, а оно делает суперадмином на все приложения сразу
   (`waContactRightsModel::get()`, строка 48: `if ($app_id != 'webasyst' && $this->get($id,
   'webasyst', 'backend')) return PHP_INT_MAX`), то есть гейт правильно пропускал администратора.
   Права переписали на `shop:backend=1` + `shop:settings=1`, после чего сценарий стал осмысленным.

## Исходная рекомендация

1. Немедленно и дёшево — поправить B4: назвать фактический гейт (`getRights('shop','settings')` в
   `shopPluginsActions::preExecute()` + CSRF ядра) и убрать утверждение про `isAdmin()`. Это верно
   независимо от того, что решим по коду.
2. Решить, нужен ли отдельный гейт. Если да — проверять `isAdmin(APP_ID)` в самом
   `shopPrefillPlugin::saveSettings()` перед записью ветки `zen.*.summary_template`/`custom_templates`
   (шаблоны — да, цвета и тумблеры — нет: их запрет сломал бы штатную работу с правом `settings`).
   Если нет — зафиксировать в B4 явное решение и его основание, чтобы вопрос не открывали заново.
3. Независимо от п.2 — прогонять входной массив через схему на записи, а не только на чтении:
   `saveSettings()` сейчас пишет в `shop_prefill_settings` любые ключи из POST. Схема уже есть
   (`shopPrefillPluginSettingGroup::validate()` итерирует схему, а не вход), нужен зеркальный метод
   «оставить только известные листья».
4. Проверить сценарием: завести контакт с правом `shop:settings` без администраторского, убедиться,
   что SPA настроек ему недоступна, и повторить POST выше — до фикса шаблон сохранится, после нет.

## Связанное

[issue-54](done/issue-54-backend-actions-no-rights-check.md) — тогда закрыли собственные экшены
плагина; ядровый путь сохранения в тот разбор не попал.
[issue-74](issue-74-minor-findings-pass-2.md) §6 — исходный разбор редактора шаблона.
Правило B4 в [RULES.md](../concept/RULES.md).
