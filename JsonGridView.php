<?php
namespace app\widgets;

use yii\base\Widget;
use yii\helpers\Json;
use yii\helpers\Url;

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
    public $searchFields = []; // массив полей для формы поиска

    public function run()
    {
        $this->registerAssets();
        $formHtml = $this->renderSearchForm();
        $containerHtml = "<div id=\"{$this->containerId}\"></div>";
        return $formHtml . $containerHtml;
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
        // Разбиваем поля по двум колонкам (как в примере) – можно кастомизировать
        $firstCol = '';
        $secondCol = '';
        $i = 0;
        foreach ($this->searchFields as $field) {
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
        $html .= '</div><div class="pull-right">';
        $html .= '<a class="btn btn-danger z_button_search_button search-reset" href="#"><i class="fa fa-eraser"></i> Очистить</a>';
        $html .= '<button type="button" class="btn btn-success z_button z_button_search_button search-submit"><i class="fa fa-search"></i> Показати</button>';
        $html .= '</div></form></div>';

        return $html;
    }

    protected function renderField($field)
    {
        $name = $field['name'] ?? '';
        $label = $field['label'] ?? '';
        $type = $field['type'] ?? 'text'; // text, select
        $options = $field['options'] ?? [];
        $placeholder = $field['placeholder'] ?? '';
        $value = $field['value'] ?? '';
        $class = $field['class'] ?? 'z_input_text';
        $id = $field['id'] ?? '';

        $attrs = '';
        if ($id) $attrs .= ' id="' . $id . '"';
        $attrs .= ' class="' . $class . '"';
        $attrs .= ' name="' . $name . '"';
        if ($placeholder) $attrs .= ' placeholder="' . $placeholder . '"';
        if ($value) $attrs .= ' value="' . $value . '"';

        $html = '<div class="form-group z_float_tab">';
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

    protected function registerAssets()
    {
        $view = $this->getView();

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
        ];
        $jsonOptions = Json::encode($options);

        $jsCode = $this->getJsCode();
        $view->registerJs($jsCode, \yii\web\View::POS_HEAD);
        $view->registerJs("$(function() { new JsonGridView({$jsonOptions}); });");
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
        var params = new URLSearchParams(window.location.search);
        var page = parseInt(params.get('page'));
        if (page > 0) this.state.page = page;
        var sortAttr = params.get('sort');
        var sortDir = params.get('dir');
        if (sortAttr && (sortDir === 'asc' || sortDir === 'desc')) {
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
            if (event.originalEvent.state && event.originalEvent.state.state) {
                self.state = event.originalEvent.state.state;
                self.loadData();
            }
        });
        
        // Обработка кнопки "Показати" (класс search-submit)
        this.container.parent().on('click', '.search-submit', function(e) {
            e.preventDefault();
            self.collectFiltersFromForm();
            self.goToPage(1);
        });
    
        // Обработка кнопки "Очистить" (класс search-reset)
        this.container.parent().on('click', '.search-reset', function(e) {
            e.preventDefault();
            var form = $(this).closest('form');
            form.find('input, select').val(''); // сброс значений
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
        if (page < 1 || page > this.pages) return;
        this.state.page = page;
        this.updateUrl();
        this.loadData();
    }

    refresh() {
        this.loadData();
    }

    showLoading() {
        // Если есть таблица – добавляем класс анимации к tbody
        var tbody = this.container.find('table tbody');
        if (tbody.length) {
            tbody.addClass('table-loading');
        } else {
            // Если таблицы ещё нет (первая загрузка) – показываем индикатор
            if (!this.container.find('.grid-loader').length) {
                this.container.prepend('<div class="grid-loader">Загрузка...</div>');
            }
        }
    }
    hideLoading() {
        // Убираем класс анимации (если есть)
        this.container.find('table tbody').removeClass('table-loading');
        // Убираем текстовый индикатор
        this.container.find('.grid-loader').remove();
    }
    showError(msg) {
        this.container.html('<div class="grid-error">Ошибка: ' + msg + '</div>');
    }
}
JS;
    }
}