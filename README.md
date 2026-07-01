# yii2-json-grid-view
JSON GridView for Yii2

A lightweight, high-performance Yii2 widget that renders tables entirely on the client side using JSON responses. It is a modern replacement for the default GridView: the server sends only the data (JSON), and the widget builds the table, pagination, sorting, and filter controls directly in the browser using jQuery. This significantly reduces network traffic and server load, making your application feel snappier.

Features

JSON‑only communication – the server returns pure JSON, no HTML overhead.
Client‑side rendering – table and pagination are built with jQuery on the client side.
Sorting & pagination – built‑in support with URL state (browser history).
Filter form – optional search form with automatic integration (fields are sent as filters).
Initial data – pass an existing ActiveDataProvider to render data on the first load instantly (avoid extra AJAX call).
Custom column rendering – define renderer functions per column (raw HTML or logic).
After‑render callback – execute any JavaScript code after the table is refreshed (e.g., collect IDs and call custom functions).
Loading animation – optional striped overlay on the table while loading.
No external CSS/JS dependencies – all styles and scripts are embedded (but you can override).
Easy integration – just one PHP file, no extra assets to deploy.
Requirements

Yii Framework 2.x
jQuery (included with Yii2 by default)
Installation

Copy the JsonGridView.php file into your @app/widgets/ directory (or any folder you prefer). Then, use it in any view:
use app\widgets\JsonGridView;
Quick Start

In your controller action, prepare the data (either via AJAX endpoint or using ActiveDataProvider for initial data):

php
// AJAX endpoint that returns JSON
public function actionData()
{
    Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
    
    $query = YourModel::find();
    // apply filters, sorting, pagination from request...
    $dataProvider = new ActiveDataProvider(['query' => $query]);
    
    return [
        'data' => $dataProvider->getModels(),
        'pagination' => [
            'total' => $dataProvider->getTotalCount(),
            'page'  => $dataProvider->getPagination()->getPage() + 1,
            'pageSize' => $dataProvider->getPagination()->getPageSize(),
            'pages' => $dataProvider->getPagination()->getPageCount(),
        ],
    ];
}
In your view, render the widget:

php
<?= JsonGridView::widget([
    'containerId' => 'my-grid',
    'url' => ['site/data'], // AJAX endpoint
    'columns' => [
        ['attribute' => 'id', 'label' => 'ID'],
        ['attribute' => 'name', 'label' => 'Name'],
        [
            'attribute' => 'status',
            'label' => 'Status',
            'render' => 'function(item) {
                return "<span class=\"badge bg-success\">" + item.status + "</span>";
            }',
        ],
    ],
    'defaultPageSize' => 20,
    'defaultSort' => ['attribute' => 'id', 'direction' => 'desc'],
]); ?>
Parameters

Parameter	Type	Description
containerId	string	ID of the container element where the grid will be rendered. Default: json-grid.
url	string/array	URL of the AJAX endpoint (will be passed to Url::to()).
columns	array	Array of column definitions (see below).
defaultPageSize	integer	Number of items per page. Default: 20.
defaultSort	array	Default sort: ['attribute' => 'field', 'direction' => 'asc|desc'].
defaultFilters	array	Default filter values (sent as JSON when the page loads).
paginationType	string	'buttons' or 'dropdown'. Default: 'buttons'.
keyAttribute	string	The field name that holds the record ID (used for data-key attribute). Default: 'id'.
searchFields	array	Configuration for the built‑in search form (see example below).
onAfterRender	string/function	JavaScript function to execute after each render. Receives data (array of records).
registerCss	boolean	Whether to register default CSS. Default: true.
Column Definition

Each column is defined as an array with the following keys:

attribute (string) – the field name in the data.
label (string) – column header text.
sortable (boolean) – whether the column can be sorted. Default: true.
render (string) – a JavaScript function as a string that takes item (the data record) and returns HTML for the cell.
Example:

php
[
    'attribute' => 'created_at',
    'label' => 'Date',
    'sortable' => true,
    'render' => 'function(item) {
        return new Date(item.created_at).toLocaleDateString();
    }',
]
Filter Form

To add a search form, define searchFields:

php
'searchFields' => [
    [
        'name' => 'YourModel[name]',
        'label' => 'Search by name',
        'type' => 'text',
        'placeholder' => 'Enter name...',
        'id' => 'yourmodel-name',
        'class' => 'z_input_text',
    ],
    [
        'name' => 'YourModel[status]',
        'label' => 'Status',
        'type' => 'select',
        'options' => [
            '' => 'All',
            'active' => 'Active',
            'inactive' => 'Inactive',
        ],
        'id' => 'yourmodel-status',
        'class' => 'z_input_text',
    ],
],
The widget will generate a form with «Show» and «Clear» buttons. Clicking «Show» sends the form data as POST parameters to the AJAX endpoint.

Using a DataProvider for Initial Data

To avoid an extra AJAX request on the first load, pass an ActiveDataProvider:

php
// In your controller
$dataProvider = new ActiveDataProvider([
    'query' => YourModel::find(),
    'pagination' => ['pageSize' => 20],
]);

// In the view
<?= JsonGridView::widget([
    'containerId' => 'my-grid',
    'url' => ['site/data'],
    'dataProvider' => $dataProvider, // initial data
    // ... other params
]); ?>
The widget will instantly render the table with the provided data, and subsequent sorting/pagination will still use AJAX.

Expected JSON Response

The AJAX endpoint must return a JSON object with the following structure:

json
{
    "data": [ { "id": 1, "name": "John", "status": "active" }, ... ],
    "pagination": {
        "total": 150,
        "page": 1,
        "pageSize": 20,
        "pages": 8
    }
}
Advanced: After‑Render Callback

You can execute custom JavaScript after each grid refresh (e.g., to collect all IDs and call an external function):

php
'onAfterRender' => 'function(data) {
    var ids = data.map(function(item) { return item.id; });
    if (ids.length) {
        aj_req("ids=" + ids.join(","), "statall", window.event, "/mail/ajax", "statall");
    }
}',
Customisation

CSS – You can override all styles by setting registerCss to false and providing your own styles.
Templates – The widget generates <table class="table table-bordered table-striped">. You can modify the createTable() method in the JavaScript code directly (it is inside the PHP file).
Roadmap

Support for inline editing
Export to CSV/Excel
Column visibility toggle
Server‑side filter validation
License

MIT – use it anywhere, modify it, share it.

Contributing

Issues and pull requests are welcome. Please open an issue first to discuss major changes.

Enjoy fast, JavaScript‑powered grids with Yii2 and JsonGridView!
