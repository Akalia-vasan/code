@php
    $request = request();
    $monthFrom = null;
    $monthTo = null;
    $staff = auth()->user()->staff()->first();
    $defOffice = $staff->defaultOffice()->first();
    $tz = $defOffice->timezone ?? config('app.timezone');
    $now = \Carbon\Carbon::now()->timezone($tz);
    $noOfDays = isset($timestamp) ? (StrToTime($now) - StrToTime(carbon()->parse($timestamp)))/( 60 * 60 * 24 ) : 'Null';
    $formattedOffice = is_array($office) ? $office['id'] : $office;
    $formattedOffice = !is_numeric($formattedOffice) && !is_string($formattedOffice)? $formattedOffice->id : $formattedOffice;
    $formattedCompany = is_array($company) ? $company['id'] : $company;
    $formattedCompany = !is_numeric($formattedCompany) && !is_string($formattedCompany) ? $formattedCompany->id : $formattedCompany;
    $formattedPreMonth = is_array($prevMonth) ? carbon($prevMonth['date']) : $prevMonth;
    $formattedNextMonth = is_array($nextMonth) ? carbon($nextMonth['date']) : $nextMonth;
    $formattedIsCurrentMonth = is_array($isCurrentMonth) ? carbon($isCurrentMonth['date']) : $isCurrentMonth;
    $formattedIsLastMonth = is_array($isLastMonth) ? carbon($isLastMonth['date']) : $isLastMonth;
    $formattedIsNextMonth = is_array($isNextMonth) ? carbon($isNextMonth['date']) : $isNextMonth;
    $formattedCurrentMonth = is_array($currentMonth) ? carbon($currentMonth['date']) : $currentMonth;
    $formattedCurrentWeek = is_array($currentWeek) ? carbon($currentWeek['date']) : $currentWeek;
    $selectedOffice = \App\Office::find($formattedOffice);
    $selectedCompany = \App\Company::find($formattedCompany);
    if($isCurrentMonth)
    {
        $monthFrom = $formattedIsCurrentMonth->startOfMonth()->format('M j');
        $monthTo = $formattedIsCurrentMonth->endOfMonth()->format('M j, Y');
    }
    else if($isLastMonth)
    {
        $monthFrom = $formattedIsLastMonth->startOfMonth()->format('M j');
        $monthTo = $formattedIsLastMonth->endOfMonth()->format('M j, Y');
    }
    elseif($isNextMonth)
    {
        $monthFrom = $formattedIsNextMonth->startOfMonth()->format('M j');
        $monthTo = $formattedIsNextMonth->endOfMonth()->format('M j, Y');
    }

    else
    {
        $monthFrom = $formattedCurrentMonth->startOfMonth()->format('M j');
        $monthTo = $formattedCurrentMonth->endOfMonth()->format('M j, Y');
    }

@endphp
@extends('layouts.v2.master')
@section('title', 'MIS Report')
@section('content')
    <section class="content-header">
        <h1 ng-cloak>
            Resource Utilisation <small>Reports and Graphs</small>
        </h1>
        <ul class="breadcrumb">
            <li>{!! link_to_route('home.index', 'Home') !!}</li>
            <li>{!! link_to_route('mis.report.index', 'MIS') !!}</li>
            <li class="active">Resource Utilisation</li>
        </ul>
    </section>
    <section class="content" data-ng-controller="MISReportController">
        <div class="ui segments">
            <div class="ui secondary segment">
{{--                @include('mis.inc._type-list')--}}
                @include('mis.inc._monthly-location')
                @include('mis.inc._period')
                <div class="btn-group btn-group-sm" role="group">
                    <a href="{{ route('mis.report.index', ['view' => $view, 'office' => $formattedOffice, 'year' => $formattedPreMonth->year, 'month' => $formattedPreMonth->month, 'company' => $formattedCompany]) }}"
                       class="btn btn-default">&lt;</a>
                    <button type="button" class="btn btn-default" id="date-button">
                        @if($isCurrentMonth)
                            Current month ({{ $formattedIsCurrentMonth->startOfMonth()->format('M j') }}
                            - {{ $formattedIsCurrentMonth->endOfMonth()->format('M j, Y') }})
                        @elseif($isLastMonth)
                            Last month ({{ $formattedIsLastMonth->startOfMonth()->format('M j') }}
                            - {{ $formattedIsLastMonth->endOfMonth()->format('M j, Y') }})
                        @elseif($isNextMonth)
                            Next month ({{ $formattedIsNextMonth->startOfMonth()->format('M j') }}
                            - {{ $formattedIsNextMonth->endOfMonth()->format('M j, Y') }})
                        @else
                            Month {{ $formattedCurrentMonth->month }} ({{ $formattedCurrentMonth->startOfMonth()->format('M j') }}
                            - {{ $formattedCurrentMonth->endOfMonth()->format('M j, Y') }})
                        @endif
                    </button>
                    <a href="{{ route('mis.report.index', ['view' => $view , 'office' => $formattedOffice, 'year' => $formattedNextMonth->year, 'month' => $formattedNextMonth->month, 'company' => $formattedCompany ]) }}"
                       class="btn btn-default">&gt;</a>
                </div>
                <div class="pull-right">
                    @if(isset($staffs))
                    <div class="ui small labeled icon top teal left pointing dropdown right floated button export-dropdown">
                        <span class="text">Export Report</span>
                        <i class="chevron down icon"></i>
                        <div class="menu">
                            @if($noOfDays > 1)
                                <a href="#"
                                   class="item export-confirm-btn" data-type="pdf">Export as PDF</a>
                                <a href="#"
                                   class="item export-confirm-btn" data-type="excel">Export as Excel</a>
                                <a href="#"
                                   class="item export-confirm-btn" data-type="csv">Export as CSV</a>
                            @else
                                <a href="{{ route('mis.report.export.pdf', ['year' => request()->get('year'), 'office' => request()->get('office'), 'month' => $month, 'view' => $view, 'company' => request()->get('company')]) }}" class="item">Export as PDF</a>
                                <a href="{{ route('mis.report.export.excel', ['year' => request()->get('year'), 'office' => request()->get('office'), 'month' => $month, 'view' => $view, 'company' => request()->get('company')]) }}" class="item">Export as Excel</a>
                                <a href="{{ route('mis.report.export.excel', ['type' => 'csv', 'year' => request()->get('year'), 'office' => request()->get('office'), 'month' => $month, 'view' => $view, 'company' => request()->get('company')]) }}" class="item">Export as CSV</a>
                            @endif
                        </div>
                    </div>
                    @if($noOfDays > 1)
                        <a class="ui floating orange right floated small button share-confirm-btn"
                           href="#">
                            <i class="share alternate icon"></i>
                            Share
                        </a>
                    @else
                        <a class="ui floating orange right floated small button"
                           href="{{ route('mis.report.view.share', [
                                     'view' => $view,
                                     'year' => $year,
                                     'month' => $month,
                                     'office' => $request->get('office'),
                                     'company' => $request->get('company')])
                                     }}">
                            <i class="share alternate icon"></i>
                            Share
                        </a>
                    @endif
                    <a class="ui small blue button generate-btn" id="report-generate-button">Re-generate</a>
                    @endIf
                </div>
            </div><br>
            <div class="ui orange secondary segment">
                @if(isset($staffs))
                    <div class="table-responsive">
                        @if($formattedOffice == 'all' && $formattedCompany == 'all')
                            <h2 data-ng-cloak="">Resource utilisation report of All companies and All offices for the period of {{ $monthFrom }} to {{ $monthTo }}</h2>
                        @elseif($formattedOffice == 'all' && !is_string($formattedCompany))
                            <h2 data-ng-cloak="">Resource utilisation report of {{ $selectedCompany->name }} - All offices for the period of {{ $monthFrom }} to {{ $monthTo }}</h2>
                        @elseif(!is_string($formattedOffice) && !is_string($formattedCompany))
                            <h2 data-ng-cloak="">Resource utilisation report of {{ $selectedCompany->name }} - {{ $selectedOffice->name }} office for the period of {{ $monthFrom }} to {{ $monthTo }}</h2>
                        @endif
                        <p style="color: grey; font-size: 15px"> Last cached: {{$timestamp}}</p>
                        @if($noOfDays > 1)
                            <div class="ui red inverted segment">
                                <p>Report last generated on: {{$timestamp}} (more than 1 day)</p>
                            </div>
                        @endif
                        <table class="ui celled unstackable table" id="my-table" style="font-family: 'Open Sans', sans-serif;">
                            <thead>
                            <tr>
                                <th style="background-color: #dcedef">Location</th>
                                <th style="background-color: #dcedef">
                                    <label onclick="sortTable(1)">Names <i class="sort icon"></i></label>
                                    <input type="text" title="Type in a name" placeholder="search" data-ng-model="searchName.short_name" class="form-control">
                                </th>
                                <th style="background-color: #dcedef;">Major Project Involved</th>
                                <th style="background-color: #e8efe8;">Customer Billable (H)</th>
                                <th style="background-color: #e8efe8;">Internal Project (H)</th>
                                <th style="background-color: #e8efe8;">Sales Support (H)</th>
                                <th style="background-color: #e8efe8;">Non-Billable (H)</th>
                                <th style="background-color: #e8efe8;">Leave (H)</th>
                                <th style="background-color: #e8efe8;">Total (H)</th>
                                <th style="background-color: #f4f3ed;">Customer Billable (%)</th>
                                <th style="background-color: #f4f3ed;">Internal Project (%)</th>
                                <th style="background-color: #f4f3ed;">Sales Support (%)</th>
                                <th style="background-color: #f4f3ed;">Non-Billable (%)</th>
                                <th style="background-color: #f4f3ed;">Leave (%)</th>
                                <th style="background-color: #e9eff2;">Comments</th>
                                <th style="background-color: #f4eff3;">Timesheet</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr data-ng-cloak="" data-ng-repeat="staff in staffs | filter:searchName">
                                <td style="background-color: #d1e9ed;">@{{ staff.location }}</td>
                                <td style="background-color: #d1e9ed;"><a data-tooltip="@{{ staff.name }}" href="@{{ staff | staffGraphURL }}">@{{ staff.short_name }}</a></td>
                                <td style="background-color: #d1e9ed;">@{{ staff.major_project_involved }}</td>
                                <td style="text-align: right; background-color: #d8e5d8;">@{{ staff.customer_bill }}</td>
                                <td style="text-align: right; background-color: #d8e5d8;">@{{ staff.internal_project }}</td>
                                <td style="text-align: right; background-color: #d8e5d8;">@{{ staff.sale_support }}</td>
                                <td style="text-align: right; background-color: #d8e5d8;">@{{ staff.non_billable }}</td>
                                <td style="text-align: right; background-color: #d8e5d8;">@{{ staff.totalLeave }}</td>
                                <td style="text-align: right; background-color: #d8e5d8;">@{{ staff.total }}</td>
                                <td style="text-align: right; background-color: #edebdf;">@{{ staff.billablePercentage }}</td>
                                <td style="text-align: right; background-color: #edebdf;">@{{ staff.interProjectPercentage }}</td>
                                <td style="text-align: right; background-color: #edebdf;">@{{ staff.saleSupportPercentage }}</td>
                                <td style="text-align: right; background-color: #edebdf;">@{{ staff.nonBillablePercentage }}</td>
                                <td style="text-align: right; background-color: #edebdf;">@{{ staff.leavePercentage }}</td>
                                <td data-tooltip="comments" class="comment-td" data-id="@{{  staff.id }}" style="background-color: #d3dadd;">
                                    <a class="ui small button add-comment-btn" data-id="@{{ staff.id }}" id="add-comment-btn-id" data-ng-click="clickModel(staff)"><i class="comments outline icon"></i></a>
                                </td>
                                <td style="background-color: #eae4e8;">
                                    <a class="ui small button" data-tooltip="timesheet" data-id="@{{ staff.id }}"
                                       href="@{{ staff | timesheetURL }}">
                                        <i class="fa fa-clock-o"></i>
                                    </a>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="empty-box">
                        <div class="center">
                            <i class="file alternate icon"></i>
                            <p id="report-generate-helper">{{ isset($isProcessing) && $isProcessing
                            ? 'Your report is being generated, please wait and once the report is generated it will be automatically refreshed'
                            : 'No reports created for the given period, please click generate button to create the report for this period.' }}</p>
                            <a class="ui small blue button generate-btn {{ isset($isProcessing) && $isProcessing
                            ? 'loading disabled' : '' }}" id="report-generate-button">Generate</a>
                        </div>
                    </div>
                @endif
            </div>
            @if(isset($staffs))
            <div class="ui segment table-responsive">
                <canvas id="canvas" style="overflow-x: scroll;"></canvas>
            </div>
            @endif
        </div>
       @include('mis.inc._comment-model')
       @include('mis.inc._custom-date-model')
    </section>
@endsection
@section('script')
    <script>
        var office = '{!! $formattedOffice !!}';
        var view = "{!! $request->get('view') !!}";
        var year = "{!! $request->get('year') !!}";
        var month = "{!! $request->get('month') !!}";

        var currentRoute = '{!! route('mis.report.index', [
            'office' => 'OFFICE',
            'view' =>  $request->get('view'),
            'year' => $request->get('year'),
            'month' => $request->get('month'),
            'company' => request()->get('company')
        ]) !!}';

        var commentRoute = "{!! route('mis.comments.index', [
            'STAFF_ID',
            'view' => $request->get('view'),
            'year' => $request->get('year'),
            'week' => $request->get('week'),
            'month' => $request->get('month'),
            'quarter' => $request->get('quarter'),
        ]) !!}";

        var el = {
            addCommentBtn: $('#add-comment-btn-id'),
            inOffice: $('#ui_combo_office_id'),
            commentModel: $('#comment-modal'),
            generateBTN: $('.generate-btn'),
            cancelBTN: $('.model-cancel'),
            inCompany: $('#ui_combo_company_id'),
            shareBtn: $('.share-confirm-btn'),
            exportBtn: $('.export-confirm-btn')
        };

        el.generateBTN.on('click', function () {
            $.ajax(({
                method: "get",
                url: currentRoute.replace('OFFICE', office) + "&generate=true",
                success: function () {
                    swal({
                        title: "Success",
                        text: "Report is being generated.",
                        type: "success"
                    }, function () {
                    });
                }
            }));
            $('#report-generate-button').addClass('loading disabled');
            $('#report-generate-helper').text('Your report is being generated, please wait and once the report generated it will be automatically refreshed..');
        });

        el.cancelBTN.on('click', function () {
            el.commentModel.modal('hide');
        });

        el.shareBtn.on('click', function () {
            var timestamp = "{{$timestamp or 'null'}}";
            var days = "{{round($noOfDays, 2)}}";
            var route = "{!!  route('mis.report.view.share', [
                                     'view' => $view,
                                     'year' => $year,
                                     'month' => $month,
                                     'office' => $request->get('office'),
                                     'company' => $request->get('company')])
                                     !!}";
            swal ({
                title: 'This Report was generated on:' +  timestamp + '(more than 1 day), do you want to continue?',
                text: 'To export the latest report, Re-generate" and Share it',
                type: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#DD6B55',
                confirmButtonText: 'Yes',
                cancelButtonText: 'No',
                closeOnConfirm: false,
                closeOnCancel: false
            }, function(isConfirm) {
                if (isConfirm) {
                    window.location.replace(route);
                } else {
                    swal.close();
                }
            });

            return false;
        });

        el.exportBtn.on('click', function () {
            var $type = $(this).data('type');
            var exportUrl = null;
            if ($type === 'pdf') exportUrl = "{!! route('mis.report.export.pdf', [
                                                    'year' => request()->get('year'),
                                                    'office' => request()->get('office'),
                                                    'month' => $month,
                                                    'view' => $view,
                                                    'company' => request()->get('company')
                                            ]) !!}";
            if ($type === 'excel') exportUrl =  "{!! route('mis.report.export.excel', [
                                                    'year' => request()->get('year'),
                                                    'office' => request()->get('office'),
                                                    'month' => $month,
                                                    'view' => $view,
                                                    'company' => request()->get('company')
                                             ]) !!}";
            if ($type === 'csv') exportUrl =  "{!! route('mis.report.export.excel', [
                                                    'type' => 'csv',
                                                    'year' => request()->get('year'),
                                                    'office' => request()->get('office'),
                                                    'month' => $month,
                                                    'view' => $view,
                                                    'company' => request()->get('company')
                                             ]) !!}";
            var timestamp = "{{$timestamp or 'null'}}";
            var days = "{{round($noOfDays, 2)}}";
            var currentURL = '{!! request()->fullUrl() !!}';
            swal ({
                title: 'This Report was generated on:' +  timestamp + '(more than 1 day), do you want to continue?',
                text: 'To export the latest report, Re-generate and Export it',
                type: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#DD6B55',
                confirmButtonText: 'Yes',
                cancelButtonText: 'No',
                closeOnConfirm: false,
                closeOnCancel: false
            }, function(isConfirm) {
                if (isConfirm) {
                    window.location.replace(exportUrl);
                    swal.close();
                } else {
                    swal.close();
                }
            });

            return false;
        });

        app.filter('staffGraphURL', function () {
            return function (staff) {
                var url = "{!! route('mis.report.month.pie', [
                'staff' => 'STAFF',
                'year' => $request->get('year'),
                'view' => $request->get('view'),
                'no' => $request->get('month'),
                'company' => $request->get('company') ?? $formattedCompany,
                'office' => $request->get('office') ?? $formattedOffice,
                ])
                !!}".replace('STAFF', staff.id);
                return url;
            };
        });
        app.filter('timesheetURL', function () {
            return function (staff) {
                var url = "{!! route('timesheet.index', [
                'staff' => 'STAFF',
                'view' => $request->get('view') ?? 'week',
                'year' => $request->get('year') ?? $formattedCurrentWeek->year,
                'month' => $request->get('month') ?? $formattedCurrentWeek->month])
                !!}".replace('STAFF', staff.id);
                return url;
            };
        });

        app.controller('MISReportController', function ($scope, $http) {
            $scope.staffs = [];
            $scope.office = office;
            $scope.officeName = null;
            $scope.view = view;

            $scope.commenting = {
                isLoading: false,
                isSubmitting: false,
                staff: null,
                comments: [],
                comment: null,
                active: null,
            };

            $http.get(currentRoute.replace('OFFICE', office) + '&ajax=true').then(function (response) {
                var officeText = el.inOffice.dropdown('get text');
                var companyText = el.inCompany.dropdown('get text');
                $scope.staffs = response.data;
                $scope.officeName = officeText;
                $scope.companyName = companyText;
            });

            el.inCompany.dropdown('clear');
            el.inCompany.dropdown();

            el.inOffice.dropdown('clear');
            el.inOffice.dropdown({
                {{--apiSettings: {--}}
                    {{--url: '{{ route('movement.office.search') }}/{query}'--}}
                {{--},--}}
                forceSelection: false,
                onChange: function (officeId) {
                    if (officeId == "{!! $selectedOffice->id ?? $office->id ?? $office['id'] ?? 'all' !!}") return;
                    if(!officeId) officeId = 'all';
                    officeId = parseInt(officeId);
                    $scope.office = officeId;

                    window.location.replace(currentRoute.replace('OFFICE', officeId))
                }
            });

            @if(isset($formattedOffice))
            el.inOffice.dropdown('set text', '{{ $selectedOffice->name ?? 'All' }}');
            el.inOffice.dropdown('set value', '{{ $selectedOffice->id ??  'all' }}');
            @endif

            @if(isset($formattedCompany))
            el.inCompany.dropdown('set text', '{{ $selectedCompany->name ?? 'All' }}');
            el.inCompany.dropdown('set value', '{{ $selectedCompany->id ??  'all' }}');
            @endif

            $('.period-dropdown').dropdown('setting', 'onChange', function (view) {
                $scope.view = view;
            });

            $(function () {
                var Url = currentRoute.replace('OFFICE', $scope.office);
                var ctx1 = $("#canvas");
                $.get(Url + '&ajax=true', function (response) {
                    if (response) {
                        var staff = [];
                        var Labels = [];
                        var billablePercentage = [];
                        var interProjectPercentage = [];
                        var saleSupportPercentage = [];
                        var nonBillablePercentage = [];
                        var leavePercentage = [];
                        _.each(response, function (data) {
                            staff.push(data.short_name);
                            Labels.push(data.short_name);
                            billablePercentage.push(data.billablePercentage);
                            interProjectPercentage.push(data.interProjectPercentage);
                            saleSupportPercentage.push(data.saleSupportPercentage);
                            nonBillablePercentage.push(data.nonBillablePercentage);
                            leavePercentage.push(data.leavePercentage);
                        });

                        new Chart(ctx1, {
                            type: 'bar',
                            data: {
                                labels: staff,
                                datasets: [{
                                    label: 'Customer Billable',
                                    data: billablePercentage,
                                    borderWidth: 1,
                                    backgroundColor: '#0c0cff'
                                },
                                    {
                                        label: 'Internal Project',
                                        data: interProjectPercentage,
                                        borderWidth: 1,
                                        backgroundColor: '#0c570b'
                                    },
                                    {
                                        label: 'Sales Support',
                                        data: saleSupportPercentage,
                                        borderWidth: 1,
                                        backgroundColor: '#ac1d18'
                                    },
                                    {
                                        label: 'Non-Billable',
                                        data: nonBillablePercentage,
                                        borderWidth: 1,
                                        backgroundColor: '#63125c'
                                    },
                                    {
                                        label: 'Leaves',
                                        data: leavePercentage,
                                        borderWidth: 1,
                                        backgroundColor: '#ff800a'
                                    },

                                ]
                            },
                            options: {
                                elements: {

                                    rectangle: {
                                        borderWidth: 2,
                                        borderSkipped: 'bottom'
                                    }
                                },
                                scales: {
                                    yAxes: [{
                                        ticks: {
                                            beginAtZero: true
                                        },
                                        scaleLabel: {
                                            display: true,
                                            labelString: 'Hours utilised (%)',
                                            labelFontWeight: 'bold',
                                            labelFontSize: 20
                                        }
                                    }],
                                    xAxes: [{
                                        scaleLabel: {
                                            display: true,
                                            labelString: 'Utilisation by category',
                                            labelFontWeight: 'bold',
                                            labelFontSize: 20
                                        }
                                    }],
                                },
                                responsive: true

                            }
                        });
                    }
                });

                var postURL = '{{ route('mis.report.comment.store') }}';
                var $td = $('.comment-td');
                $td.keyup(function (index) {
                    var $this = $(this);
                    var data = {
                        staff_ID: $this.attr('data-id'),
                        comment: $this.text(),
                        period_type: view,
                        period_no: week,
                        year: year,
                    };
                    $.ajax({
                        type: "POST",
                        url: postURL,
                        data: data,
                        success: function (msg) {
                            showSuccessMessage();
                        }
                    });
                });

                function showSuccessMessage() {
                    toastr.options = {
                        "positionClass": "toast-bottom-right"
                    };
                    toastr.success('Comment update successfully')
                }
            });

            $scope.clickModel = function (staff) {
                $('.add-comment-btn').addClass('disabled');

                var $this = $(this);
                $this.addClass('loading');

                $scope.commenting.isLoading = true;
                $scope.commenting.staff = null;
                $scope.commenting.comments = [];


                $.ajax(({
                    method: "get",
                    url: commentRoute.replace("STAFF_ID", staff.id),
                    success: function (data) {
                        $('.add-comment-btn').removeClass('disabled');
                        $this.removeClass('loading');

                        $scope.commenting.isLoading = false;
                        $scope.commenting.staff = data.staff;
                        $scope.commenting.comments = data.comments;
                        el.commentModel.modal({
                            blurring: true,
                            closable: false,
                            autofocus: false,
                            allowMultiple: false
                        }).modal('setting', 'transition', 'horizontal flip').modal('show').modal('refresh');

                        $scope.$apply();
                        el.commentModel.modal('refresh');
                    }
                }));
            };

            $('#comment-form').submit(function () {
                $scope.commenting.isSubmitting = true;
                $scope.$apply();

                if ($scope.commenting.active) {
                    $.ajax(({
                        method: "POST",
                        url: "{{ route('mis.report.comment.update', ['STAFF_ID']) }}".replace("STAFF_ID", $scope.commenting.staff.id),
                        data: {
                            comment: $scope.commenting.comment,
                            commentID: $scope.commenting.id,
                        },
                        success: function (data) {
                            $scope.commenting.isSubmitting = false;
                            $scope.commenting.active = null;
                            $scope.commenting.comment = null;

                            var index = $scope.commenting.comments.findIndex(function (item) {
                                return item.id == data.comment.id;
                            });

                            $scope.commenting.comments.splice(index, 1, data.comment);
                            el.commentModel.modal('refresh');
                            $scope.$apply();
                        }
                    }));
                } else {
                    $.ajax(({
                        method: "POST",
                        url: commentRoute.replace("STAFF_ID", $scope.commenting.staff.id),
                        data: {
                            comment: $scope.commenting.comment,
                        },
                        success: function (data) {
                            $scope.commenting.isSubmitting = false;
                            $scope.commenting.comment = null;
                            $scope.commenting.comments.push(data.comment);

                            el.commentModel.modal('refresh');
                            $scope.$apply();
                        }
                    }));
                }

                return false;
            });

            $scope.handleEdit = function (comment) {
                $scope.commenting.active = comment;
                $scope.commenting.comment = comment.comment;
                $scope.commenting.id = comment.id;
            };
        });

        function sortTable(n) {
            var table, rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0;
            table = document.getElementById("my-table");
            switching = true;
            dir = "asc";
            while (switching) {
                switching = false;
                rows = table.rows;
                for (i = 1; i < (rows.length - 1); i++) {
                    shouldSwitch = false;
                    x = rows[i].getElementsByTagName("TD")[n];
                    y = rows[i + 1].getElementsByTagName("TD")[n];
                    if (dir == "asc") {
                        if (x.innerHTML.toLowerCase() > y.innerHTML.toLowerCase()) {
                            shouldSwitch = true;
                            break;
                        }
                    } else if (dir == "desc") {
                        if (x.innerHTML.toLowerCase() < y.innerHTML.toLowerCase()) {
                            shouldSwitch = true;
                            break;
                        }
                    }
                }
                if (shouldSwitch) {
                    rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                    switching = true;
                    switchcount ++;
                } else {
                    if (switchcount == 0 && dir == "asc") {
                        dir = "desc";
                        switching = true;
                    }
                }
            }
        }

        $('.export-dropdown').dropdown();
        $('.period-dropdown').dropdown();
    </script>
    @include('mis.inc._custom-date-script')
@endsection
@section('style')
    <style>
        .td-align {
            text-align: justify;
        }

        .empty-box {
            text-align: center;
        }

        .empty-box .center {
            padding: 250px;
        }

        .empty-box .center i {
            font-size: 40px;
            margin-bottom: 0;
        }

        .empty-box .center p {
            font-size: 15px;
        }
    </style>
@endsection