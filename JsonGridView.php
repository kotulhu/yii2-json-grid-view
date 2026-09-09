<?php

namespace app\widgets;

use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\widgets\ActiveForm;

/**
 * Example
 *
 * --- Action ---
 *
 * public function actionCreate()
 * {
 *     $model = new model();
 *     $model->load(Yii::$app->request->post());
 *
 *     if ($model->validate()) {
 *         $model->save();
 *         return $this->asJson(['success' => true, 'id' => $model->id]);
 *     }
 *
 *     return $this->asJson([
 *         'success' => false,
 *         'errors' => \yii\widgets\ActiveForm::validate($model),
 *     ]);
 * }
 *
 * --- view ---
 *
 * echo JsonGridView::widget([
 *     'url' => ['/controller/view'],
 *     'columns' => [
 *         ['attribute' => 'name', 'label' => 'Name'],
 *         ['attribute' => 'value', 'label' => 'Value'],
 *     ],
 *     'createModel' => new model(),
 *     'createUrl' => ['/controller/create'],
 *     'createFields' => [
 *         'name',
 *         'percent',
 *         ['attribute' => 'is_active', 'type' => 'checkbox'],
 *         ['attribute' => 'type', 'type' => 'dropDownList', 'items' => model::getTypeList()],
 *     ],
 * ]);
 */
class JsonGridView extends Widget
{
    public $containerId = 'json-grid';
    public $url;
    public $columns = [];
    public $defaultPageSize = 20;
    public $defaultSort = null;
    public $defaultFilters = [];
    public $paginationType = 'buttons';
    public $registerCss = true;
    public $keyAttribute = 'id';
    public $onAfterRender = null; // Колбек, вызываемый после отрисовки
    public $searchFields = []; // массив полей для формы поиска; необязательный ключ 'order' задаёт порядок отображения (меньше — раньше)
    public $syncUrl = true; // false — не хранить page/sort/filters в query string

    // --- Форма добавления записи ---
    public $createModel = null;        // экземпляр модели (new Model()), используется для rules()/attributeLabels()
    public $createUrl = null;          // экшен, куда постится форма (например ['/controller/create'])
    public $createFields = [];         // список полей: ['attr1', 'attr2'] либо ['attribute'=>'attr','type'=>'dropDownList',...]
    public $createButtonLabel = 'Додати';
    public $createModalTitle = 'Додати запис';
    public $customHtml = null;

    // --- Alertigo (уведомления об ошибках) ---
    public $alertigoCssPath = 'css/alertigo/jquery-alertigo.css'; // относительно @webroot
    public $alertigoJsPath = 'js/alertigo/jquery-alertigo.js';    // относительно @webroot
    private $_alertigoAvailable = null; // кэш результата проверки наличия библиотеки

    // Список attribute, разрешённых для сортировки — берётся из тех же columns, что и в виджете
    public static function getSortableAttributes(array $columns): array
    {
        $result = [];
        foreach ($columns as $col) {
            if (!empty($col['attribute']) && ($col['sortable'] ?? true) !== false) {
                $result[] = $col['attribute'];
            }
        }
        return $result;
    }

    // Безопасно разбирает sort/dir из запроса: значение вне белого списка отбрасывается в $default
    // (защита от SQL-инъекции через имя колонки в ORDER BY)
    public static function resolveSort(array $params, array $allowedAttributes, ?array $default = null): array
    {
        $default = $default ?? ['attribute' => null, 'direction' => 'desc'];
        $attribute = $params['sort'] ?? null;
        $direction = ($params['dir'] ?? null) === 'asc' ? 'asc' : 'desc';

        if ($attribute !== null && in_array($attribute, $allowedAttributes, true)) {
            return ['attribute' => $attribute, 'direction' => $direction];
        }
        return $default;
    }

    // Зажимает pageSize сверху, чтобы клиент не запросил count=100000 и не положил БД
    public static function clampPageSize($count, int $max = 200, int $default = 20): int
    {
        $count = (int)$count;
        return $count > 0 ? min($count, $max) : $default;
    }

    public function run()
    {
        $this->registerAssets();
        $createHtml = $this->renderCreateModal();
        $formHtml = $this->renderSearchForm();
        $containerHtml = "<div id=\"{$this->containerId}\"></div>";
        $customHtml = $this->customHtml;
        $alertigoHtml = $this->isAlertigoAvailable() ? '<div id="alertigo"></div>' : '';
        return $alertigoHtml . $createHtml . $formHtml . $customHtml . $containerHtml;
    }

    /**
     * Проверяет физическое наличие файлов библиотеки alertigo в @webroot.
     * Результат кэшируется на время запроса.
     */
    protected function isAlertigoAvailable()
    {
        if ($this->_alertigoAvailable === null) {
            $webroot = \Yii::getAlias('@webroot');
            $this->_alertigoAvailable = is_file($webroot . '/' . ltrim($this->alertigoCssPath, '/'))
                && is_file($webroot . '/' . ltrim($this->alertigoJsPath, '/'));
        }
        return $this->_alertigoAvailable;
    }

    /**
     * Регистрирует ассеты alertigo, если библиотека физически присутствует в проекте.
     * Если её нет — ничего не делает, и клиентский JS сам fallback'нется на alert().
     */
    protected function registerAlertigoAssets()
    {
        if (!$this->isAlertigoAvailable()) {
            return;
        }
        $view = $this->getView();
        $view->registerCssFile(\Yii::$app->homeUrl . $this->alertigoCssPath);
        $view->registerJsFile(\Yii::$app->homeUrl . $this->alertigoJsPath, ['position' => \yii\web\View::POS_BEGIN]);
    }

    /**
     * Сортирует поля поиска по необязательному ключу 'order' (число, меньше — раньше).
     * Поля без 'order' сохраняют исходный взаимный порядок и располагаются после
     * полей, у которых 'order' указан явно (сортировка устойчивая).
     *
     * Пример:
     *   'searchFields' => [
     *       ['attribute' => 'name', 'order' => 20],
     *       ['attribute' => 'status', 'order' => 10],
     *       ['attribute' => 'created_at'], // order не задан — уйдёт в конец
     *   ]
     */
    protected function sortSearchFields(array $fields)
    {
        $indexed = [];
        foreach (array_values($fields) as $i => $field) {
            $order = is_array($field) && isset($field['order']) ? (float)$field['order'] : null;
            $indexed[] = ['field' => $field, 'order' => $order, 'i' => $i];
        }

        usort($indexed, function ($a, $b) {
            // поля без order считаем бесконечно большими, чтобы они шли после упорядоченных
            $orderA = $a['order'] === null ? PHP_FLOAT_MAX : $a['order'];
            $orderB = $b['order'] === null ? PHP_FLOAT_MAX : $b['order'];
            if ($orderA === $orderB) {
                return $a['i'] <=> $b['i'];
            }
            return $orderA <=> $orderB;
        });

        return array_map(function ($item) {
            return $item['field'];
        }, $indexed);
    }

    protected function renderSearchForm()
    {
        if (empty($this->searchFields)) {
            return '';
        }

        $formId = $this->containerId . '-search-form';
        $html = '<div class="panel-body flex-wrap">';
        $html .= '<form id="' . $formId . '" class="form-group" method="post">';
        // CSRF (если нужен) – можно добавить через Yii::$app->request->csrfToken
        $html .= '<input type="hidden" name="_csrf" value="' . \Yii::$app->request->csrfToken . '">';

        $html .= '<div class="flex-row">';

        $fields = $this->sortSearchFields($this->searchFields);

        $firstCol = '';
        $secondCol = '';
        $i = 0;
        foreach ($fields as $field) {
            $fieldHtml = $this->renderField($field);
            if ($i % 2 == 0) {
                $firstCol .= '<div class="flex-item">' . $fieldHtml . '</div>';
            } else {
                $secondCol .= '<div class="flex-item">' . $fieldHtml . '</div>';
            }
            $i++;
        }
        $html .= '<div class="flex-col">' . $firstCol . '</div>';
        $html .= '<div class="flex-col">' . $secondCol . '</div>';
        $html .= '</div>';

        $html .= '<br><div class="pull-left">';
        // Можно добавить дополнительные кнопки (например, SendPulse)
        $html .= '</div><div class="pull-right btn-blc">';
        $html .= '<a class="btn btn-danger z_button_search_button search-reset" href="#"><i class="fa fa-eraser"></i> Очистити</a>';
        $html .= '<button type="button" class="btn btn-success z_button z_button_search_button search-submit"><i class="fa fa-search"></i> Показати</button>';
        $html .= '</div></form></div>';

        return $html;
    }

    /**
     *  Render add button using $this->createModel and $this->createFields. Labels & rules (client-side also) are from model.
     *  $createFields can be:
     *  'attribute_name'
     *  text input
     *  ['attribute' => 'status', 'type' => 'dropDownList', 'items' => [...]]
     *  ['attribute' => 'comment', 'type' => 'textarea']
     *  ['attribute' => 'password', 'type' => 'textInput', 'htmlType' => 'password']
     *  ['attribute' => 'active', 'type' => 'checkbox']
     *  ['attribute' => 'user_id', 'type' => 'widget', 'widgetClass' => ..., 'widgetOptions' => [...]]
     */
    protected function renderCreateModal()
    {
        if (!$this->createModel || !$this->createUrl) {
            return '';
        }

        $modalId = $this->containerId . '-create-modal';
        $formId = $this->containerId . '-create-form';

        ob_start();
        $form = ActiveForm::begin([
            'id' => $formId,
            'action' => Url::to($this->createUrl),
            'options' => ['class' => 'json-grid-create-form'],
            'enableClientValidation' => true,
            'enableAjaxValidation' => false,
        ]);

        foreach ($this->createFields as $field) {
            if (is_string($field)) {
                $field = ['attribute' => $field];
            }
            $attribute = $field['attribute'];
            $type = $field['type'] ?? 'textInput';
            $activeField = $form->field($this->createModel, $attribute, $field['fieldOptions'] ?? []);

            switch ($type) {
                case 'dropDownList':
                    echo $activeField->dropDownList($field['items'] ?? [], $field['inputOptions'] ?? []);
                    break;
                case 'textarea':
                    echo $activeField->textarea($field['inputOptions'] ?? []);
                    break;
                case 'checkbox':
                    echo $activeField->checkbox($field['inputOptions'] ?? []);
                    break;
                case 'widget':
                    echo $activeField->widget($field['widgetClass'], $field['widgetOptions'] ?? []);
                    break;
                default:
                    $inputOptions = $field['inputOptions'] ?? [];
                    if (!empty($field['htmlType'])) {
                        $inputOptions['type'] = $field['htmlType'];
                    }
                    echo $activeField->textInput($inputOptions);
            }
        }

        echo Html::submitButton('Зберегти', ['class' => 'btn btn-success']);
        echo ' ';
        echo Html::button('Скасувати', ['class' => 'btn btn-default json-grid-create-cancel']);
        ActiveForm::end();
        $formContent = ob_get_clean();

        $html = '<div class="json-grid-create-block">';
        $html .= Html::button($this->createButtonLabel, [
            'class' => 'btn btn-success json-grid-create-open',
            'data-modal' => $modalId,
        ]);

        $html .= '<div id="' . $modalId . '" class="json-grid-modal" style="display:none;">';
        $html .= '<div class="json-grid-modal-backdrop"></div>';
        $html .= '<div class="json-grid-modal-dialog">';
        $html .= '<div class="json-grid-modal-header">';
        $html .= '<span>' . Html::encode($this->createModalTitle) . '</span>';
        $html .= '<a href="#" class="json-grid-modal-close">&times;</a>';
        $html .= '</div>';
        $html .= '<div class="json-grid-modal-body">' . $formContent . '</div>';
        $html .= '</div></div></div>';

        return $html;
    }

    /**
     * Приводит style к строке для атрибута HTML.
     * Принимает либо готовую строку ("color:red;font-weight:bold;"),
     * либо массив ['color' => 'red', 'font-weight' => 'bold'].
     */
    protected function normalizeStyle($style)
    {
        if (is_array($style)) {
            $parts = [];
            foreach ($style as $prop => $val) {
                $parts[] = $prop . ':' . $val;
            }
            return Html::encode(implode(';', $parts));
        }
        return Html::encode((string)$style);
    }

    protected function renderField($field)
    {
        $type = $field['type'] ?? 'text'; // text, select, widget, html

        // 1. Поле полностью произвольного рендеринга — колбэк вернёт готовый HTML.
        //    Позволяет встроить что угодно, не ограничиваясь предопределёнными типами.
        if (isset($field['render']) && is_callable($field['render'])) {
            return call_user_func($field['render'], $field, $this);
        }

        // 2. Готовый заранее сформированный HTML-блок.
        if ($type === 'html') {
            return $field['html'] ?? '';
        }

        // 3. Встраивание произвольного Yii-виджета (например, автокомплит юзера/банка).
        if ($type === 'widget') {
            return $this->renderWidgetField($field);
        }

        $name = $field['name'] ?? '';
        $label = $field['label'] ?? '';
        $options = $field['options'] ?? [];
        $placeholder = $field['placeholder'] ?? '';
        $value = $field['value'] ?? '';
        $class = $field['class'] ?? 'z_input_text';
        $id = $field['id'] ?? '';
        $disabled = $field['disabled'] ?? false;
        $style = $field['style'] ?? ''; // строка "color:red;" или массив ['color' => 'red']
        $extraAttrs = $field['attrs'] ?? []; // любые прочие HTML-атрибуты: ['data-foo' => 'bar', 'readonly' => true]
        $required = $field['required'] ?? false;

        $attrs = '';
        if ($id) $attrs .= ' id="' . $id . '"';
        $attrs .= ' class="' . $class . '"';
        $attrs .= ' name="' . $name . '"';
        if ($placeholder) $attrs .= ' placeholder="' . $placeholder . '"';
        if ($value) $attrs .= ' value="' . $value . '"';
        if ($disabled) $attrs .= ' disabled';
        if ($required) $attrs .= ' required';
        if ($style) $attrs .= ' style="' . $this->normalizeStyle($style) . '"';
        foreach ($extraAttrs as $attrName => $attrVal) {
            if ($attrVal === true) {
                $attrs .= ' ' . Html::encode($attrName);
            } elseif ($attrVal !== false && $attrVal !== null) {
                $attrs .= ' ' . Html::encode($attrName) . '="' . Html::encode($attrVal) . '"';
            }
        }

        // Обёртка поля тоже может получить свой класс/стиль (например, чтобы прятать всю группу целиком)
        $wrapClass = 'form-group z_float_tab';
        if (!empty($field['wrapClass'])) $wrapClass .= ' ' . $field['wrapClass'];
        $wrapStyle = $field['wrapStyle'] ?? '';
        $wrapAttrs = ' class="' . $wrapClass . '"';
        if ($wrapStyle) $wrapAttrs .= ' style="' . $this->normalizeStyle($wrapStyle) . '"';

        $html = '<div' . $wrapAttrs . '>';
        if ($type === 'select') {
            $html .= '<select ' . $attrs . '>';
            if ($placeholder) $html .= '<option value="">' . $placeholder . '</option>';
            foreach ($options as $val => $text) {
                $selected = ($val == $value) ? ' selected' : '';
                $html .= '<option value="' . $val . '"' . $selected . '>' . $text . '</option>';
            }
            $html .= '</select>';
        } else {
            $html .= '<input type="' . $type . '" ' . $attrs . '>';
        }
        if ($label) {
            $html .= '<label class="z_float_app" for="' . $id . '">' . $label . '</label>';
        }
        $html .= '<div class="help-block"></div>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Рендерит произвольный Yii-виджет как поле фильтра.
     *
     * Конфиг поля:
     * [
     *     'type' => 'widget',
     *     'name' => 'user_id',          // имя поля, попадёт в filters при сабмите
     *     'value' => '',                 // текущее значение (id)
     *     'label' => 'Пользователь',
     *     'id' => 'user-filter',         // необязательно, id корневого элемента виджета
     *     'widgetClass' => \app\widgets\WidgetUserAutocomplete::class,
     *     'widgetOptions' => [           // любые публичные свойства виджета
     *         'searchUrl' => '/b2/user/autocomplete',
     *         'labelValue' => '...',
     *         'onSelect' => 'myCallback',
     *     ],
     *     'wrap' => true,                // false — не оборачивать в .form-group/label
     * ]
     */
    protected function renderWidgetField($field)
    {
        $widgetClass = $field['widgetClass'] ?? null;
        $widgetOptions = $field['widgetOptions'] ?? [];
        $label = $field['label'] ?? '';
        $wrap = $field['wrap'] ?? true;

        if (!$widgetClass || !class_exists($widgetClass)) {
            return '<!-- JsonGridView: widget class "' . Html::encode((string)$widgetClass) . '" not found -->';
        }

        // Подставляем name/value/id из настроек поля, если они явно не заданы в widgetOptions,
        // чтобы не дублировать их в конфиге каждый раз.
        if (!isset($widgetOptions['name']) && isset($field['name'])) {
            $widgetOptions['name'] = $field['name'];
        }
        if (!isset($widgetOptions['value']) && array_key_exists('value', $field)) {
            $widgetOptions['value'] = $field['value'];
        }
        if (isset($field['id']) && !isset($widgetOptions['options']['id'])) {
            $widgetOptions['options']['id'] = $field['id'];
        }
        // Пробрасываем disabled в сам виджет, если он его поддерживает (проверяется через свойство класса)
        if (isset($field['disabled']) && property_exists($widgetClass, 'disabled')) {
            $widgetOptions['disabled'] = $field['disabled'];
        }

        $widgetHtml = call_user_func([$widgetClass, 'widget'], $widgetOptions);

        if (!$wrap) {
            return $widgetHtml;
        }

        $wrapClass = 'form-group z_float_tab';
        if (!empty($field['wrapClass'])) $wrapClass .= ' ' . $field['wrapClass'];
        $wrapStyle = $field['wrapStyle'] ?? '';
        $wrapAttrs = ' class="' . $wrapClass . '"';
        if ($wrapStyle) $wrapAttrs .= ' style="' . $this->normalizeStyle($wrapStyle) . '"';

        $html = '<div' . $wrapAttrs . '>';
        $html .= $widgetHtml;
        if ($label) {
            $html .= '<label class="z_float_app">' . Html::encode($label) . '</label>';
        }
        $html .= '<div class="help-block"></div>';
        $html .= '</div>';

        return $html;
    }

    protected function registerAssets()
    {
        $view = $this->getView();

        $this->registerAlertigoAssets();

        if ($this->registerCss) {
            /*$css = <<<CSS
            .json-grid-table { border-collapse: collapse; width: 100%; }
            .json-grid-table th, .json-grid-table td { border: 1px solid #ddd; padding: 8px; }
            .json-grid-table th { background: #f4f4f4; cursor: pointer; user-select: none; }
            .json-grid-table th.sorted { background: #e0e0e0; }
            .json-grid-pagination { margin-top: 15px; }
            .json-grid-pagination ul.pagination { display: flex; list-style: none; padding: 0; gap: 5px; margin: 0; flex-wrap: wrap; }
            .json-grid-pagination ul.pagination li { display: inline; }
            .json-grid-pagination ul.pagination li a { display: block; padding: 5px 12px; border: 1px solid #ddd; text-decoration: none; color: #007bff; border-radius: 3px; }
            .json-grid-pagination ul.pagination li.active a { background: #007bff; color: white; border-color: #007bff; }
            .json-grid-pagination ul.pagination li.disabled a { color: #ccc; pointer-events: none; background: #f9f9f9; }
            .json-grid-pagination ul.pagination li a:hover:not(.disabled) { background: #f0f0f0; }
            .json-grid-pagination .pagination-info { margin-left: 10px; font-size: 14px; color: #555; display: inline-block; }
            .grid-loader { padding: 10px; color: #007bff; font-weight: bold; }
            .grid-error { padding: 10px; color: #d9534f; background: #f2dede; border: 1px solid #ebccd1; border-radius: 4px; }
            CSS;
            $view->registerCss($css);*/

            $css = <<<CSS
            #{$this->containerId}-search-form .btn-blc {
               display: flex;
               gap: 10px;
            }
            #{$this->containerId}-search-form .z_button_search_button {
                width:auto;
            }
            .json-grid-pagination {
                display: flex;
                flex-direction: column;
            }
            CSS;
            $view->registerCss($css);

            // loading animation
            $css = <<<CSS
        @keyframes stripes {
            0% { background-position: 100% 0; }
            100% { background-position: 0 0; }
        }
        .table-loading {
            background: repeating-linear-gradient(
                -45deg,
                #f0f0f0,
                #f0f0f0 10px,
                #e0e0e0 10px,
                #e0e0e0 20px
            );
            background-size: 200% 200%;
            animation: stripes 10s linear infinite;
            opacity: 0.7;
        }
        CSS;
            $view->registerCss($css);

            $css = <<<CSS
            .json-grid-create-block { margin-bottom: 15px; }
            .json-grid-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1050; }
            .json-grid-modal-backdrop { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
            .json-grid-modal-dialog { position: relative; background: #fff; width: 500px; max-width: 90%; margin: 60px auto; border-radius: 4px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); max-height: 80vh; display: flex; flex-direction: column; }
            .json-grid-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #eee; }
            .json-grid-modal-header span { font-weight: bold; }
            .json-grid-modal-close { text-decoration: none; font-size: 20px; color: #888; }
            .json-grid-modal-body { padding: 16px; overflow-y: auto; }
            CSS;
            $view->registerCss($css);
        }

        $options = [
            'container' => "#{$this->containerId}",
            'url' => Url::to($this->url),
            'columns' => $this->prepareColumns(),
            'defaultPageSize' => $this->defaultPageSize,
            'defaultSort' => $this->defaultSort,
            'defaultFilters' => $this->defaultFilters,
            'paginationType' => $this->paginationType,
            'keyAttribute' => $this->keyAttribute,
            'onAfterRender' => $this->onAfterRender,
            'syncUrl' => $this->syncUrl,
        ];
        $jsonOptions = Json::encode($options);

        $jsCode = $this->getJsCode();
        $view->registerJs($jsCode, \yii\web\View::POS_HEAD);
        $instanceVar = 'jsonGridInstance_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $this->containerId);
        $view->registerJs("$(function() { window['{$instanceVar}'] = new JsonGridView({$jsonOptions}); });");

        if ($this->createModel && $this->createUrl) {
            $this->registerCreateModalJs($instanceVar);
        }
    }

    /**
     * JS для открытия/закрытия модалки и ajax-сабмита формы добавления записи.
     * Ошибки валидации с сервера ожидаются в формате yii\widgets\ActiveForm::validate($model),
     * т.е. массив ["ModelName-attribute" => ["текст ошибки", ...]].
     */
    protected function registerCreateModalJs($instanceVar)
    {
        $view = $this->getView();
        $containerId = $this->containerId;
        $modalId = $containerId . '-create-modal';
        $formId = $containerId . '-create-form';

        $js = <<<JS
(function() {
    var \$modal = $('#{$modalId}');
    var \$form = $('#{$formId}');

    $(document).on('click', '[data-modal="{$modalId}"]', function(e) {
        e.preventDefault();
        \$modal.show();
    });

    function closeModal() {
        \$modal.hide();
        \$form[0].reset();
        \$form.find('.help-block').text('');
        \$form.find('.has-error').removeClass('has-error');
    }

    \$modal.find('.json-grid-modal-close, .json-grid-create-cancel, .json-grid-modal-backdrop').on('click', function(e) {
        e.preventDefault();
        closeModal();
    });

    \$form.on('beforeSubmit', function(e) {
        var form = $(this);
        $.post(form.attr('action'), form.serialize(), function(data) {
            if (data && data.success) {
                closeModal();
                if (window['{$instanceVar}']) {
                    window['{$instanceVar}'].refresh();
                }
            } else if (data && data.errors) {
                form.yiiActiveForm('updateAjaxErrors', data.errors);
            }
        }, 'json').fail(function() {
            jsonGridAlert('Помилка збереження запису');
        });
        return false;
    });
})();
JS;
        $view->registerJs($js);
    }

    protected function prepareColumns()
    {
        $result = [];
        foreach ($this->columns as $col) {
            $attribute = $col['attribute'] ?? '';
            $item = [
                'attribute' => $attribute,
                'label' => $col['label'] ?? ($attribute ?: ''),
                'sortable' => $col['sortable'] ?? true,
            ];
            if (isset($col['render']) && is_string($col['render'])) {
                $item['render'] = $col['render'];
            }
            $result[] = $item;
        }
        return $result;
    }

    protected function getJsCode()
    {
        return <<<'JS'
function jsonGridAlert(message, options) {
    if (window.alertigo) {
        alertigo(message || 'Помилка запиту', $.extend({ color: 'red', life: '5000' }, options || {}));
    } else {
        alert(message || 'Помилка запиту');
    }
}

class JsonGridView {
    constructor(options) {
        this.container = $(options.container);
        if (!this.container.length) {
            console.error('JsonGridView: контейнер не найден');
            return;
        }
        this.url = options.url;
        this.columns = options.columns || [];
        this.defaultPageSize = options.defaultPageSize || 20;
        this.defaultSort = options.defaultSort || null;
        this.defaultFilters = options.defaultFilters || {};
        this.paginationType = options.paginationType || 'buttons';
        this.keyAttribute = options.keyAttribute || 'id';
        this.onAfterRender = options.onAfterRender || null;
        this.syncUrl = options.syncUrl !== undefined ? options.syncUrl : true;

        this.state = {
            page: 1,
            pageSize: this.defaultPageSize,
            sort: this.defaultSort,
            filters: $.extend({}, this.defaultFilters),
        };
        this.data = [];
        this.totalCount = 0;
        this.pages = 0;
        this.loading = false;

        this.init();
    }

    init() {
        this.restoreFromUrl();
        this.loadData();
        this.bindEvents();
    }

    restoreFromUrl() {
        if (!this.syncUrl) return; // не читаем url
    
        var params = new URLSearchParams(window.location.search);
        var page = parseInt(params.get('page'));
        if (page > 0) this.state.page = page;
        var sortAttr = params.get('sort');
        var sortDir = params.get('dir');
        // sort приходит из URL, который пользователь может отредактировать руками —
        // пускаем только «похожее на имя поля» (буквы/цифры/подчёркивание) и только
        // то, что реально объявлено в columns этого виджета
        var isValidFieldName = sortAttr && /^[a-zA-Z0-9_]+$/.test(sortAttr);
        var isKnownColumn = isValidFieldName && this.columns.some(function(col) {
            return col.attribute === sortAttr;
        });
        if (isKnownColumn && (sortDir === 'asc' || sortDir === 'desc')) {
            this.state.sort = { attribute: sortAttr, direction: sortDir };
        }
        var filtersParam = params.get('filters');
        if (filtersParam) {
            try {
                this.state.filters = JSON.parse(decodeURIComponent(filtersParam));
            } catch(e) {}
        }
    }
    
    updateUrl() {
        if (!this.syncUrl) return; // не пишем в URL вовсе
    
        var params = new URLSearchParams();
        params.set('page', this.state.page);
        params.set('count', this.state.pageSize);
        if (this.state.sort) {
            params.set('sort', this.state.sort.attribute);
            params.set('dir', this.state.sort.direction);
        }
        if (Object.keys(this.state.filters).length > 0) {
            params.set('filters', encodeURIComponent(JSON.stringify(this.state.filters)));
        }
        var newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({ state: this.state }, '', newUrl);
    }

    loadData() {
        if (this.loading) return;
        this.loading = true;
        this.showLoading();

        var self = this;
        var params = {
            page: this.state.page,
            count: this.state.pageSize,
        };
        $.extend(params, this.state.filters);
        if (this.state.sort) {
            params.sort = this.state.sort.attribute;
            params.dir = this.state.sort.direction;
        }

        $.post(this.url, params, function(result) {
            self.data = result.data || [];
            self.totalCount = result.pagination ? result.pagination.total : 0;
            self.pages = result.pagination ? result.pagination.pages : 0;
            self.state.page = result.pagination ? result.pagination.page : self.state.page;
            self.render();
            self.loading = false;
            self.hideLoading();
        }).fail(function(xhr, status, error) {
            console.error('Ошибка загрузки:', error);
            self.showError('Ошибка загрузки данных: ' + error);
            jsonGridAlert('Помилка завантаження даних: ' + error);
            self.loading = false;
            self.hideLoading();
        });
    }

    render() {
        this.container.html('');
        var table = this.createTable();
        this.container.append(table);
        var pagination = this.createPagination();
        this.container.append(pagination);

        // Вызов колбека после отрисовки, если он передан
        if (this.onAfterRender) {
            var callback = this.onAfterRender;
            if (typeof callback === 'string') {
                try {
                    callback = eval('(' + callback + ')');
                } catch(e) {
                    console.warn('Ошибка eval для onAfterRender:', callback);
                    return;
                }
            }
            if (typeof callback === 'function') {
                callback(this.data); // Передаём экземпляр виджета
            } else {
                console.warn('onAfterRender не является функцией');
            }
        }
    }

    createTable() {
        var table = $('<table class="table table-bordered table-striped"></table>');
        var thead = $('<thead></thead>');
        var tr = $('<tr></tr>');
        var self = this;

        $.each(this.columns, function(i, col) {
            var th = $('<th></th>').html(col.label || col.attribute || '');
            if (col.sortable !== false && col.attribute) {
                th.css('cursor', 'pointer').attr('data-sort', col.attribute);
                if (self.state.sort && self.state.sort.attribute === col.attribute) {
                    th.addClass('sorted');
                    th.text(th.text() + (self.state.sort.direction === 'asc' ? ' ▲' : ' ▼'));
                }
            }
            tr.append(th);
        });
        thead.append(tr);
        table.append(thead);

        var tbody = $('<tbody></tbody>');
        if (this.data.length === 0) {
            var emptyRow = $('<tr><td colspan="' + this.columns.length + '">Нет данных</td></tr>');
            tbody.append(emptyRow);
        } else {
            $.each(this.data, function(idx, item) {
                var tr = $('<tr></tr>');
                var key = item[self.keyAttribute] || '';
                tr.attr('data-key', key);

                $.each(self.columns, function(j, col) {
                    var td = $('<td></td>');
                    if (col.render) {
                        var renderFn;
                        if (typeof col.render === 'function') {
                            renderFn = col.render;
                        } else if (typeof col.render === 'string') {
                            try {
                                renderFn = eval('(' + col.render + ')');
                            } catch(e) {
                                console.warn('Ошибка eval для render:', col.render);
                            }
                        }
                        if (typeof renderFn === 'function') {
                            td.html(renderFn(item));
                        } else {
                            td.text(item[col.attribute] || '');
                        }
                    } else if (col.attribute) {
                        td.text(item[col.attribute] || '');
                    } else {
                        td.text('');
                    }
                    tr.append(td);
                });
                tbody.append(tr);
            });
        }
        table.append(tbody);
        return table;
    }

    createPagination() {
        var self = this;
        var nav = $('<div class="json-grid-pagination"></div>');
        if (this.pages <= 1) return nav;

        var ul = $('<ul class="pagination"></ul>');

        var prevLi = $('<li class="prev"></li>');
        var prevA = $('<a href="#"></a>').text('«');
        if (this.state.page <= 1) {
            prevLi.addClass('disabled');
        } else {
            prevA.on('click', function(e) {
                e.preventDefault();
                self.goToPage(self.state.page - 1);
            });
        }
        prevLi.append(prevA);
        ul.append(prevLi);

        var maxVisible = 10;
        var start = Math.max(1, this.state.page - 4);
        var end = Math.min(this.pages, start + maxVisible - 1);
        if (end - start < maxVisible - 1) start = Math.max(1, end - maxVisible + 1);

        for (var i = start; i <= end; i++) {
            var li = $('<li></li>');
            if (i === this.state.page) li.addClass('active');
            var a = $('<a href="#"></a>').text(i);
            a.on('click', function(e) {
                e.preventDefault();
                var page = parseInt($(this).text());
                self.goToPage(page);
            });
            li.append(a);
            ul.append(li);
        }

        var nextLi = $('<li class="next"></li>');
        var nextA = $('<a href="#"></a>').text('»');
        if (this.state.page >= this.pages) {
            nextLi.addClass('disabled');
        } else {
            nextA.on('click', function(e) {
                e.preventDefault();
                self.goToPage(self.state.page + 1);
            });
        }
        nextLi.append(nextA);
        ul.append(nextLi);

        nav.append(ul);

        var startItem = (this.state.page - 1) * this.state.pageSize + 1;
        var endItem = Math.min(this.state.page * this.state.pageSize, this.totalCount);
        var info = $('<span class="pagination-info"></span>')
            .text('Записи ' + startItem + '–' + endItem + ' из ' + this.totalCount);
        nav.append(info);

        return nav;
    }

    bindEvents() {
        var self = this;

        this.container.on('click', 'th[data-sort]', function() {
            var attr = $(this).data('sort');
            self.toggleSort(attr);
        });

        this.container.on('change', '.grid-filters input, .grid-filters select', function() {
            var name = $(this).attr('name');
            if (name) {
                self.state.filters[name] = $(this).val();
                self.goToPage(1);
            }
        });

        $(window).on('popstate', function(event) {
            if (!self.syncUrl) return;
            if (event.originalEvent.state && event.originalEvent.state.state) {
                self.state = event.originalEvent.state.state;
                self.loadData();
            }
        });
        
        this.container.parent().on('click', '.search-submit', function(e) {
            e.preventDefault();
            self.collectFiltersFromForm();
            self.goToPage(1);
        });
        
        this.container.parent().on('click', '.search-reset', function(e) {
            e.preventDefault();
            var form = $(this).closest('form');
            form.find('input, select').val(''); // reset
            self.collectFiltersFromForm();
            self.goToPage(1);
        });
    }

    // метод для сбора фильтров из формы
    collectFiltersFromForm() {
        var form = this.container.parent().find('form');
        if (!form.length) return;
        var data = form.serializeArray();
        var filters = {};
        $.each(data, function(i, field) {
            if (field.name && field.value) {
                filters[field.name] = field.value;
            }
        });
        this.state.filters = filters;
    }
    
    toggleSort(attribute) {
        if (!this.state.sort || this.state.sort.attribute !== attribute) {
            this.state.sort = { attribute: attribute, direction: 'asc' };
        } else {
            this.state.sort.direction = this.state.sort.direction === 'asc' ? 'desc' : 'asc';
        }
        this.goToPage(1);
    }

    goToPage(page) {
        page = parseInt(page, 10);
        if (isNaN(page) || page < 1) return; // не число / меньше первой страницы — игнорируем
        if (this.pages > 0 && page > this.pages) {
            page = this.pages; // страница за пределами известного диапазона — зажимаем к последней
        }
        this.state.page = page;
        this.updateUrl();
        this.loadData();
    }

    refresh() {
        this.loadData();
    }

    showLoading() {
        var tbody = this.container.find('table tbody');
        if (tbody.length) {
            tbody.addClass('table-loading');
        } else {
            if (!this.container.find('.grid-loader').length) {
                this.container.prepend('<div class="grid-loader">Завантаження...</div>');
            }
        }
    }
    hideLoading() {
        this.container.find('table tbody').removeClass('table-loading');
        this.container.find('.grid-loader').remove();
    }
    showError(msg) {
        this.container.html('<div class="grid-error">Ошибка: ' + msg + '</div>');
    }
}
JS;
    }
}
