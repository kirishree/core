<script>
    var arrow_up = '<span class="fa fa-arrow-up text-success"></span>';
    var arrow_down = '<span class="fa fa-arrow-down text-danger"></span>';
    // avoid running code twice due to <script> location within <body>
    if (typeof senseiWidgetInit === 'undefined') {
        senseiWidgetInit = false;
    } else {
        senseiWidgetInit = true;
    }

    function sensei_widget_data() {
        $.ajax("/api/sensei/widget", {
                type: 'get',
                cache: false,
                dataType: "json"
            })
            .done(function(rows) {
                $.each(rows, function(key, row) {
                    // $('#sensei_connfact_id > tbody').append('<tr><td>' + row.key + '</td><td>' + row.value +  '</td></tr>');
                    $('#' + key + '_key').html(row.key + ':');
                    $('#' + key + '_val').html(row.value);
                });
                // schedule next update
                setTimeout('sensei_widget_data()', 60000);
                $('#dt_id').html(new Date())
            });
    }
    if (senseiWidgetInit === false)
        sensei_widget_data();
</script>

<style>
    .key {
        font-weight: bold;
        width: 30%;
        display: table-cell;
        padding-right: 10px;
    }

    .val {
        width: 70%;
        display: table-cell;
        padding-right: 10px;
    }

    .sensei-row {
        display: flex;
    }

    .sensei-col {
        position: relative;
        float: left;
        margin-right: 15px;
        width: auto;
    }
</style>

<table class="table table-striped table-condensed" id="sensei_connfact_id">
    <tbody>
        <tr>
            <td class="sensei-row">
                <div id="engine_key" style="font-weight: bold; width:30%;display: table-cell;padding-right: 10px;"></div>
                <div id="engine_val" style="width:20%;display: table-cell;padding-right: 10px;"></div>
                <div id="database_key" style="font-weight: bold;width:25%;display: table-cell;padding-right: 10px;"></div>
                <div id="database_val" style="width:25%;display: table-cell;padding-right: 10px;"></div>
            </td>
        </tr>
        <tr>
            <td class="sensei-row">
                <div id="topblocks_key" class="key"></div>

                <div id="topblocks_val" class="val"></div>

            </td>
        </tr>
        <tr>
            <td class="sensei-row">
                <div id="topapps_key" class="key"></div>
                <div id="topapps_val" class="val"></div>
            </td>
        </tr>
        <tr>
            <td class="sensei-row">
                <div id="topwebcategories_key" class="key"></div>
                <div id="topwebcategories_val" class="val"></div>
            </td>
        </tr>
        <tr>
            <td class="sensei-row">
                <div id="topauthuses_key" class="key"></div>
                <div id="topauthuses_val" class="val"></div>
            </td>
        </tr>
        <tr>
            <td class="sensei-row">
                <div id="toplocalhost_key" class="key"></div>
                <div id="toplocalhost_val" class="val"></div>
            </td>
        </tr>
        <tr>
            <td class="sensei-row">
                <div id="activeuses_key" class="sensei-col" style="font-weight: bold;"></div>
                <div id="activeuses_val" class="sensei-col"> </div>
                <div id="uniquelocaldevices_key" class="sensei-col" style="font-weight: bold;"></div>
                <div id="uniquelocaldevices_val" class="sensei-col"></div>
                <div id="uniquelocalipaddress_key" class="sensei-col" style="font-weight: bold;"></div>
                <div id="uniquelocalipaddress_val" class="sensei-col"></div>
                <div id="uniqueremoteipaddress_key" class="sensei-col" style="font-weight: bold;"></div>
                <div id="uniqueremoteipaddress_val" class="sensei-col"></div>
            </td>
        </tr>
        <tr>
            <td class="sensei-row" style="font-size: 8pt;">
                <div id="dt_id"></div>
            </td>
        </tr>
    </tbody>
</table>