<!-- OPNsense Libraries -->
<script src="/ui/js/moment-with-locales.min.js"></script>
<script src="/ui/js/d3.min.js"></script>

<!-- Zenarmor Libraries -->
<script src="/ui/js/d3.layout.cloud.min.js"></script>
<script src="/ui/js/bootstrap-datetimepicker.min.js"></script>
<script src="/ui/js/jquery.bootstrap-growl.min.js"></script>
<script src="/ui/js/jquery-ui.min.js" type="text/javascript"></script>
<script src="/ui/js/jquery.scrolling-tabs.min.js" type="text/javascript"></script>
<link rel="stylesheet" type="text/css" href="/ui/css/jquery.scrolling-tabs.min.css">
<link rel="stylesheet" type="text/css" href="/ui/css/bootstrap-tagsinput.css">

<!-- Zenarmor Application END -->
<!-- <script src="https://js.stripe.com/v3/" type="text/javascript"></script> -->
<div aurelia-app="main" data-theme="{{ theme }}" data-version="1668540336">
    <div class="panel panel-default">
        <div class="panel-body">
            <div class="progress">
                <div class="progress-bar progress-bar-primary progress-bar-striped active" role="progressbar" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100" style="width:50%" id="init-progress"></div>
            </div>
            <h2 class="text-center text-primary" id="init-text">{{ lang._('Initializing...') }}</h2>
        </div>
    </div>
    <script>
      var Sensei = {
          wizardRequired: {{ wizardRequired }},
          premium: {{ premium }},
          premium_plan: '{{ premium_plan }}',
          license_extdata: '{{ license_extdata }}',
          license_size: {{ license_size }},
          license_key: '{{ license_key }}',
          license_plan: '{{ license_plan }}',
          support: '{{ support }}',
          supportPlan: '{{ supportPlan }}',
          uiStartText: "{{ lang._('Starting user interface...') }}",
          language: "{{ language }}",
          dbtype: "{{ dbtype }}",
          dbversion: "{{ dbversion }}",
          theme: "{{ theme }}",
          opnsense_version: "{{ opnsense_version}}",
          grant: "{{ grant }}",
          partner_id: "{{ partner_id }}",
          partner_name: "{{ partner_name }}",
          redirect: "{{ redirect }}"
          };
        $('#Sensei>a.active').removeClass('active');
    </script>
    <script type="text/javascript" src="/ui/js/sensei-app.js?v=1668540336"></script>
    <script type="text/javascript" src="/ui/js/sensei-vendor.js?v=1668540336"></script>
</div>