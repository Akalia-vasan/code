<?php

namespace App\Listeners\MIS;

use App\Company;
use App\Event;
use App\Events\ReportRequestCompleteEvent;
use App\Events\MIS\ReportRequestEvent;
use App\EventType;
use App\Leave;
use App\MisComment;
use App\Office;
use App\Project;
use App\Staff;
use App\SubstituteDay;
use App\Worklog;
use Carbon\Carbon;
use DateInterval;
use DatePeriod;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use phpDocumentor\Reflection\Types\Null_;
use Storage;

class ReportRequestEventListener implements ShouldQueue
{
    private $auth;
    private $office;
    private $attributes;


    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  ReportRequestEvent $event
     * @return void
     */
    public function handle(ReportRequestEvent $event)
    {

        $this->auth = $event->auth;
        $this->office = $event->office;
        $this->attributes = $event->attributes;

        if (array_get($this->attributes, 'view') === 'month') {
            $this->getMonthDetails();
        } else if (array_get($this->attributes, 'view') === 'quarter') {
            $this->getQuarterDetail();
        } else if (array_get($this->attributes, 'view') === 'fy') {
            $this->getYearDetail();
        } else if (array_get($this->attributes, 'view') === 'custom') {
            $this->getCustomDetail();
        } else {
            $this->getWeekDetails();
        }
    }

    private function getMonthDetails()
    {
        $view = 'month';
        $staff = $this->auth->staff()->first();
        $year = array_get($this->attributes, 'year');
        $month = array_get($this->attributes, 'month');
        $timestamp = array_get($this->attributes, 'timestamp');
        $office = $this->office;

        if ($office !== 'all') {
            $office = Office::find($office);
        }

        $company = 'all';
        $companyId = array_get($this->attributes, 'company');

        if ($companyId !== 'all') {
            $company = Company::find($companyId);
        }

        $tz = $this->staffTimezone($staff);
        $isCurrentMonth = (carbon()->tz($tz)->year == $year && carbon()->tz($tz)->month == $month) ? carbon()->now($tz) : false;
        $isLastMonth = (carbon()->now($tz)->subMonth()->year == $year && carbon()->now($tz)->subMonth()->month == $month) ? carbon()->now($tz)->subMonth() : false;
        $isNextMonth = (carbon()->now($tz)->addMonth()->year == $year && carbon()->now($tz)->addMonth()->month == $month) ? carbon()->now($tz)->addMonth() : false;
        $currentMonth = carbon()->tz($tz)->setDate($year, $month, 1);
        $prevMonth = carbon()->tz($tz)->setDate($year, $month, 1)->subMonth();
        $nextMonth = carbon()->tz($tz)->setDate($year, $month, 1)->addMonth();

        $from = $currentMonth->copy()->startOfMonth();
        $to = $currentMonth->copy()->endOfMonth();
        $currentWeek = $from->copy()->startOfMonth();
        $interval = new DateInterval('P1D');
        $dateRange = new DatePeriod($from, $interval, $to);

        $staffBuilder = null;

        if ($office === 'all' && $company === 'all') {
            $staffBuilder = Staff::on();
        } elseif ($office === 'all') {
            $staffBuilder = Staff::whereHas('offices', function ($query) use ($companyId) {
                $query->whereHas('company', function ($query) use ($companyId) {
                    $query->where('id', $companyId);
                });
            });
        } else {
            $staffBuilder = $office->staff()->where('is_default', 'Yes');
        }

        $availableStaff = $staffBuilder->orderBy('short_name', 'asc')->where(function ($q) use ($from, $to) {
            $q->where('joined_at', '<=', carbon()->parse($from)->toDateString())
                ->orWhereBetween('joined_at', [carbon()->parse($from)->toDateString(), carbon()->parse($to)->toDateString()]);
        })->with([
            'offices',
            'misComment',
            'worklogs' => function ($q) use ($from, $to) {
                $q->where('logged_for', '>=', carbon()->parse($from))
                    ->where('logged_for', '<=', carbon()->parse($to))
                    ->with([
                        'issue' => function ($q) {
                            $q->with([
                                'task' => function ($q) {
                                    $q->select('id', 'issue_id', 'project_id')->with([
                                        'relatedProject' => function ($q) {
                                            $q->select('id', 'funding_source_id');
                                        },
                                    ]);
                                },
                                'project' => function ($q) {
                                    $q->select('id', 'funding_source_id');
                                },
                            ]);
                        }
                    ]);
            },
        ])->get();
        $notAvailableStaff = $staffBuilder->orderBy('short_name', 'asc')->whereNotIn('left_at', [''])
            ->where('left_at', '<', carbon()->parse($from)->toDateTimeString())->get();
        $diff = $availableStaff->diff($notAvailableStaff);
        $staffs = $diff;

        $staffs = $staffs->transform(function ($staff) use ($from, $to, $interval, $dateRange, $office, $year, $month,  $view) {
            $works = $staff->worklogs;

            $billableIssue = $works->whereNotIn('issue.project_id', [1])->where('issue.project.funding_source_id', 2)->sum('worked');
            $billableTask =  $works->whereIn('issue.project_id', [1])->where('issue.task.relatedProject.funding_source_id', 2)->sum('worked');
            $billable = $billableIssue + $billableTask;

            $total = $works->sum('worked');

            $internalIssue = $works->whereNotIn('issue.project_id', [1])->where('issue.project.funding_source_id', 1)->sum('worked');
            $internalTask =  $works->whereIn('issue.project_id', [1])->where('issue.task.relatedProject.funding_source_id', 1)->sum('worked');
            $internal = $internalIssue + $internalTask;

            $sale = $works->where('issue->issue_type_id', 19)->sum('worked');

            $nonBillableIssue = $works->whereNotIn('issue.project_id', [1])->whereNotIn('issue.project.funding_source_id', [1, 2])->sum('worked');
            $nonBillableTask = $works->whereIn('issue.project_id', [1])->where('issue.task.project_id', null)->sum('worked');
            $nonBillablePro = $works->whereIn('issue.project_id', [1])->whereNotIn('issue.task.relatedProject.funding_source_id', [1, 2])->whereNotIn('issue.task.project_id', [null])->sum('worked');
            $nonBillable = $nonBillableIssue + $nonBillableTask + $nonBillablePro;

            $workedProjects = $staff->worklogs()->whereBetween('worklogs.logged_for', [carbon()->parse($from), carbon()->parse($to)])
                ->join('issues', 'worklogs.issue_id', '=', 'issues.id')
                ->select('issues.project_id', DB::raw("SUM(worked) as total"))
                ->groupBy('project_id')
                ->orderBy('total', 'desc')->get()->first();
            if (isset($workedProjects->project_id) && $workedProjects->project_id == 1) {
                $workedProjects = $staff->worklogs()->whereBetween('worklogs.logged_for', [carbon()->parse($from), carbon()->parse($to)])
                    ->join('issues', 'worklogs.issue_id', '=', 'issues.id')
                    ->join('tasks', 'tasks.issue_id', '=', 'issues.id')
                    ->select('tasks.project_id', DB::raw("SUM(worked) as total"))
                    ->groupBy('project_id')
                    ->orderBy('total', 'desc')->get()->first();
                if (empty($workedProjects->project_id)) {
                    $workedProjects = Project::find(1);
                    $workedProjects->setAttribute('project_id', $workedProjects->id);
                }
            }

            $staff->project = $workedProjects ? Project::where('id', $workedProjects->project_id)->pluck('name')->first() : Null;
            if ($office == 'all') $office = $staff->offices()->wherePivot('is_default', 'Yes')->first();
            $timezone = $office ? $office->timezone : null;

            $allPublicHolidays = $timezone ? $this->publicHolidays($timezone, $from->toDateTimeString(), $to->toDateTimeString(), $staff, $dateRange) : [];
            $holidayLogs = [];
            $holidayTotal = [];
            $holidayTotalByType = [];
            foreach ($allPublicHolidays as $key => $holidays) {
                foreach ($holidays as $holiday) {
                    if (!isset($holidayLogs[$holiday['type']])) {
                        $holidayLogs[$holiday['type']] = [];
                        $holidayTotalByType[$holiday['type']] = 0;
                    }
                    foreach ($dateRange as $date) {
                        $d = $date->toDateString();
                        if (!isset($holidayTotal[$d])) {
                            $holidayTotal[$d] = 0;
                        }
                        if ($d == $key) {
                            $holidayLog = ['reason' => $holiday['what'], 'hours' => $holiday['holiday_hours']];
                            $holidayLogs[$holiday['type']][$d][] = $holidayLog;
                            $holidayTotal[$d] += $holiday['holiday_hours'] * 60;
                            $holidayTotalByType[$holiday['type']] += $holiday['holiday_hours'] * 60;
                        }
                    }
                    if (!$holidayTotalByType[$holiday['type']]) {
                        unset($holidayLogs[$holiday['type']]);
                    }
                }
            }
            $staff->project = $workedProjects ? Project::where('id', $workedProjects->project_id)->pluck('name')->first() : Null;

            $staff->customer_bill = timesheetHoursFormat($billable);
            $staff->internal_project = timesheetHoursFormat($internal);
            $staff->sale_support = timesheetHoursFormat($sale);
            $staff->non_billable = timesheetHoursFormat($nonBillable);
            $staff->totalLeave = count($holidayTotalByType) ? array_sum($holidayTotalByType) > 0 ? timesheetHoursFormat(array_sum($holidayTotalByType)) : 0 : 0;
            $fiTotal = timesheetHoursFormat($total) + $staff->totalLeave;
            $staff->total = $fiTotal;
            $staff->billablePercentage = ($staff->customer_bill && $fiTotal) ? round(($staff->customer_bill / $fiTotal) * 100, 2) : 0;
            $staff->interProjectPercentage = ($staff->internal_project && $staff->total) ? round(($staff->internal_project / $staff->total) * 100, 2) : 0;
            $staff->saleSupportPercentage = ($staff->sale_support && $staff->total) ? round(($staff->sale_support / $staff->total) * 100, 2) : 0;
            $staff->nonBillablePercentage = ($staff->non_billable && $staff->total) ? round(($staff->non_billable / $staff->total) * 100, 2) : 0;
            $staff->leavePercentage = ($staff->totalLeave && $staff->total) ? round(($staff->totalLeave / $staff->total) * 100, 2) : 0;
            $staff->location = $office->name ?? 'None';

            $staff->comment = MisComment::where('staff_id', $staff->id)->where('year', $year)
                    ->where('period_type', 'month')->where('period_no', $month)
                    ->pluck('comment')->first() ?? ' ';
            return [
                'id' => $staff['id'],
                'short_name' => $staff['short_name'],
                'name' => $staff['first_name'] . ' ' . $staff['last_name'],
                'major_project_involved' => $staff['project'] ?? 'None',
                'customer_bill' => $staff['customer_bill'] ?? 0,
                'sale_support' => $staff['sale_support'] ?? 0,
                'internal_project' => $staff['internal_project'] ?? 0,
                'non_billable' => $staff['non_billable'] ?? 0,
                'totalLeave' => $staff['totalLeave'] ?? 0,
                'total' => $staff['total'] ?? 0,
                'billablePercentage' => $staff['billablePercentage'] ?? 0,
                'saleSupportPercentage' => $staff['saleSupportPercentage'] ?? 0,
                'interProjectPercentage' => $staff['interProjectPercentage'] ?? 0,
                'nonBillablePercentage' => $staff['nonBillablePercentage'] ?? 0,
                'leavePercentage' => $staff['leavePercentage'] ?? 0,
                'comment' => $staff['comment'] ?? 'None',
                'location' => $staff['location'] ?? 'None',
            ];
        })->filter(function($staff){
            return ($staff['customer_bill'] !== 0 || $staff['sale_support'] !== 0 || $staff['internal_project'] !== 0 || $staff['non_billable'] !== 0) || $staff['totalLeave'] !== 0;
        });

        $report = compact(
            'staffs', 'staff', 'view', 'office', 'month', 'year', 'currentWeek', 'company',
            'isCurrentMonth', 'isLastMonth', 'isNextMonth', 'currentMonth', 'prevMonth', 'nextMonth', 'timestamp'
        );

        if ($office instanceof Office) {
            $office = $office->id;
        }

        if ($company instanceof Company) {
            $company = $company->id;
        }

        if (!$office) {
            logger('No Office to handle getMonthDetails');
            return;
        }

        $name = "$view.$office.$year.$month.$company";
        Storage::put("reports/$name.json", json_encode($report));
        cache()->put($name, $report);

        if (Storage::exists("reports/$name.txt")) {
            Storage::delete("reports/$name.txt");
        }

        ReportRequestCompleteEvent::dispatch($this->auth, $office, [
            'route' => route('mis.report.index', [
                'view' => $view,
                'year' => $year,
                'month' => $month
            ])
        ]);
    }

    private function publicHolidays($timezone, $startDate, $endDate, $staff, $dateRange = null)
    {
        $publicHolidays = [];
        $requiredHours = $this->requiredHoursForStaff($staff);
        $publicHolidaysLists = Event::where('timezone', $timezone)->where(function ($query) use ($startDate, $endDate) {
            $query->whereBetween('start', [$startDate, $endDate])
                ->orWhereBetween('end', [$startDate, $endDate])
                ->orWhere(function ($query) use ($startDate, $endDate) {
                    $query->Where('start', '<', $startDate)
                        ->Where('end', '>', $endDate);
                });
        })->where('event_type_id', 5)
            ->with(['eventType' => function ($q) {
                $q->select(['id', 'class', 'name']);
            }])
            ->get(['id', 'event_type_id', 'start', 'end', 'what']);

        $publicHolidayDays = [];
        if (count($publicHolidaysLists)) {
            foreach ($publicHolidaysLists as $holidaysList) {
                if ($dateRange) {
                    foreach ($dateRange as $date) {
                        $d = $date->toDateString();
                        $isHoliday = $holidaysList->start->diffInDaysFiltered(function ($holiday) use ($date) {
                            return $holiday->isWeekday() ? $holiday->isSameDay($date) : 0;
                        }, $holidaysList->end->endOfDay());
                        if ($isHoliday == 1) {
//                            $publicHoliday['class'] = $holidaysList->eventType->class;
//                            $publicHoliday['what'] = $holidaysList->what;
//                            $publicHoliday['type'] = $holidaysList->eventType->name;
//                            $publicHoliday['all_day'] = true;
//                            $publicHoliday['holiday_hours'] = $requiredHours;
//                            $publicHolidays[$d][] = $publicHoliday;
                        }
                    }
                } else {
                    $isHoliday = $holidaysList->start->diffInDaysFiltered(function ($holiday) use ($startDate) {
                        return $holiday->isWeekday() ? $holiday->toDateString() == $startDate : 0;
                    }, $holidaysList->end->endOfDay());
                    if ($isHoliday == 1) {
//                        $publicHoliday['class'] = $holidaysList->eventType->class;
//                        $publicHoliday['what'] = $holidaysList->what;
//                        $publicHoliday['type'] = $holidaysList->eventType->name;
//                        $publicHoliday['all_day'] = true;
//                        $publicHoliday['holiday_hours'] = $requiredHours;
//                        $publicHolidays[] = $publicHoliday;
                    }
                }

            }
            $publicHolidayDays = $publicHolidaysLists->pluck('start');
            $publicHolidayDays->transform(function ($item) {
                return $item->toDateString();
            });
        }

        $leaveLists = Leave::where('staff_id', $staff->id)->where('status', 'Approved')->where(function ($query) use ($startDate, $endDate) {
            $query->whereBetween('from_date', [$startDate, $endDate])
                ->orWhereBetween('to_date', [$startDate, $endDate])
                ->orWhere(function ($query) use ($startDate, $endDate) {
                    $query->Where('from_date', '<', $startDate)
                        ->Where('to_date', '>', $endDate);
                });
        })->get(['id', 'from_date', 'to_date', 'leave_type', 'number_of_days', 'half_day_on', 'reason']);

        $eventType = EventType::find(2);
        if (count($leaveLists)) {
            foreach ($leaveLists as $leaveList) {
                if ($dateRange) {
                    foreach ($dateRange as $date) {
                        $d = $date->toDateString();
                        if (count($publicHolidayDays)) {
                            if (in_array($d, $publicHolidayDays->all())) continue;
                        }
                        $isLeave = $leaveList->from_date->diffInDaysFiltered(function ($leave) use ($date, $leaveList) {
                            return $leave->isWeekday() ? $leave->isSameDay($date) : 0;
                        }, $leaveList->to_date->endOfDay());

                        if ($isLeave) {
                            if ($leaveList->leave_type == 'short' || $leaveList->number_of_days == 0.5 || ($leaveList->half_day_on == 'first_day' && $leaveList->from_date->copy()->toDateString() == $date->copy()->toDateString()) || ($leaveList->half_day_on == 'last_day' && $leaveList->to_date->copy()->toDateString() == $date->copy()->toDateString())) {
                                $diffInHours = $leaveList->leave_type == 'short' ? '1.5' : $requiredHours / 2;
                                $publicHoliday['class'] = $eventType->class;
                                $publicHoliday['what'] = $leaveList->reason;
                                $publicHoliday['type'] = ucfirst(str_replace("_", " ", $leaveList->leave_type)) . ' ' . $eventType->name;
                                $publicHoliday['all_day'] = false;
                                $publicHoliday['holiday_hours'] = $diffInHours;
                                $publicHolidays[$d][] = $publicHoliday;

                            } else {
                                $publicHoliday['class'] = $eventType->class;
                                $publicHoliday['what'] = $leaveList->reason;
                                $publicHoliday['type'] = ucfirst(str_replace("_", " ", $leaveList->leave_type)) . ' ' . $eventType->name;
                                $publicHoliday['all_day'] = true;
                                $publicHoliday['holiday_hours'] = $requiredHours;
                                $publicHolidays[$d][] = $publicHoliday;
                            }
                        }
                    }
                } else {
                    $isLeave = $leaveList->from_date->diffInDaysFiltered(function ($leave) use ($startDate) {
                        return $leave->isWeekday() ? $leave->toDateString() == $startDate : 0;
                    }, $leaveList->to_date->endOfDay());

                    if ($isLeave) {
                        if ($leaveList->leave_type == 'short' || $leaveList->number_of_days == 0.5 || ($leaveList->half_day_on == 'first_day' && $leaveList->from_date->copy()->toDateString() == $date->copy()->toDateString()) || ($leaveList->half_day_on == 'last_day' && $leaveList->to_date->copy()->toDateString() == $date->copy()->toDateString())) {
                            $diffInHours = $leaveList->leave_type == 'short' ? '1.5' : $requiredHours / 2;
                            $publicHoliday['class'] = $eventType->class;
                            $publicHoliday['what'] = $leaveList->reason;
                            $publicHoliday['type'] = ucfirst(str_replace("_", " ", $leaveList->leave_type)) . ' ' . $eventType->name;
                            $publicHoliday['all_day'] = false;
                            $publicHoliday['holiday_hours'] = $diffInHours;
                            $publicHolidays[] = $publicHoliday;
                        } else {
                            $publicHoliday['class'] = $eventType->class;
                            $publicHoliday['what'] = $leaveList->reason;
                            $publicHoliday['type'] = ucfirst(str_replace("_", " ", $leaveList->leave_type)) . ' ' . $eventType->name;
                            $publicHoliday['all_day'] = true;
                            $publicHoliday['holiday_hours'] = $requiredHours;
                            $publicHolidays[] = $publicHoliday;
                        }
                    }
                }
            }
        }

        $defaultOffice = $staff->defaultOffice->first();
        if ($defaultOffice) {
            $substitutedDays = SubstituteDay::where('office_id', $defaultOffice->id)->whereBetween('substitute_for_day', [$startDate, $endDate])
                ->get(['substitute_for_day', 'reason']);
            foreach ($substitutedDays as $substitutedDay) {
                $d = $substitutedDay->substitute_for_day->toDateString();
                $publicHoliday['class'] = 'black';
                $publicHoliday['what'] = $substitutedDay->reason;
                $publicHoliday['type'] = 'Substitute Day';
                $publicHoliday['all_day'] = true;
                $publicHoliday['holiday_hours'] = $requiredHours;
                if ($dateRange) {
                    $publicHolidays[$d][] = $publicHoliday;
                } else {
                    $publicHolidays[] = $publicHoliday;
                }
            }

            $substitutedDays = SubstituteDay::where('office_id', $defaultOffice->id)->whereBetween('substitute_day', [$startDate, $endDate])
                ->get(['substitute_day', 'reason']);
            foreach ($substitutedDays as $substitutedDay) {
                $d = $substitutedDay->substitute_day->toDateString();
                $publicHoliday['class'] = 'black';
                $publicHoliday['what'] = $substitutedDay->reason;
                $publicHoliday['type'] = 'Substitute Day';
                $publicHoliday['all_day'] = true;
                $publicHoliday['holiday_hours'] = 0;
                if ($dateRange) {
                    $publicHolidays[$d][] = $publicHoliday;
                } else {
                    $publicHolidays[] = $publicHoliday;
                }
            }
        }

        return $publicHolidays;
    }

    private function requiredHoursForStaff(Staff $staff)
    {
        $office = $staff->defaultOffice->first() ?: Office::first();
        $officeStartTime = carbon()->parse($office->open_time);
        $officerCloseTime = carbon()->parse($office->close_time);
        return $officerCloseTime->diffInHours($officeStartTime);
    }

    private function staffTimezone($staff)
    {
        if (!$staff) return config('app.timezone');
        $office = $staff->offices()->wherePivot('is_default', 'Yes')->first();
        $tz = $office->timezone ?? config('app.timezone');
        return $tz;
    }

    private function getWeekDetails()
    {
        $view = 'week';
        $staff = $this->auth->staff()->first();
        $year = array_get($this->attributes, 'year');
        $week = array_get($this->attributes, 'week');
        $timestamp = array_get($this->attributes, 'timestamp');
        $office = $this->office;

        if ($office !== 'all') {
            $office = Office::find($office);
        }

        $tz = $this->staffTimezone($staff);

        $isCurrentWeek = (carbon()->tz($tz)->year == $year && carbon()->tz($tz)->weekOfYear == $week) ? carbon()->now($tz) : false;
        $isLastWeek = (carbon()->now($tz)->subWeek()->year == $year && carbon()->now($tz)->subWeek()->weekOfYear == $week) ? carbon()->now($tz)->subWeek() : false;
        $isNextWeek = (carbon()->now($tz)->addWeek()->year == $year && carbon()->now($tz)->addWeek()->weekOfYear == $week) ? carbon()->now($tz)->addWeek() : false;
        $currentWeek = carbon()->tz($tz)->setISODate($year, $week);
        $prevWeek = carbon()->tz($tz)->setISODate($year, $week)->subWeek();
        $nextWeek = carbon()->tz($tz)->setISODate($year, $week)->addWeek();


        $from = $currentWeek->copy()->startOfWeek();
        $to = $currentWeek->copy()->endOfWeek();
        $interval = new DateInterval('P1D');
        $dateRange = new DatePeriod($from, $interval, $to);


        $staffBuilder = null;
        $companyId = array_get($this->attributes, 'company');

        $company = 'all';
        if ($companyId !== 'all') {
            $company = Company::find($companyId);
        }

        if ($office === 'all' && $company === 'all') {
            $staffBuilder = Staff::on();
        } elseif ($office === 'all') {
            $staffBuilder = Staff::whereHas('offices', function ($query) use ($companyId) {
                $query->whereHas('company', function ($query) use ($companyId) {
                    $query->where('id', $companyId);
                });
            });
        } else {
            $staffBuilder = $office->staff()->where('is_default', 'Yes');
        }
        $availableStaff = $staffBuilder->orderBy('short_name', 'asc')->where(function ($q) use ($from, $to) {
            $q->where('joined_at', '<=', carbon()->parse($from)->toDateString())
                ->orWhereBetween('joined_at', [carbon()->parse($from)->toDateString(), carbon()->parse($to)->toDateString()]);
        })->with([
            'offices',
            'misComment',
            'worklogs' => function ($q) use ($from, $to) {
                $q->where('logged_for', '>=', carbon()->parse($from))
                    ->where('logged_for', '<=', carbon()->parse($to))
                    ->with([
                        'issue' => function ($q) {
                            $q->with([
                                'task' => function ($q) {
                                    $q->select('id', 'issue_id', 'project_id')->with([
                                        'relatedProject' => function ($q) {
                                            $q->select('id', 'funding_source_id');
                                        },
                                    ]);
                                },
                                'project' => function ($q) {
                                    $q->select('id', 'funding_source_id');
                                },
                            ]);
                        }
                    ]);
            },
        ])->get();
        $notAvailableStaff = $staffBuilder->orderBy('short_name', 'asc')->whereNotIn('left_at', [''])
            ->where('left_at', '<', carbon()->parse($from)->toDateTimeString())->get();
        $diff = $availableStaff->diff($notAvailableStaff);
        $staffs = $diff;

        $staffs = $staffs->transform(function ($staff) use ($from, $to, $interval, $dateRange, $office, $year, $week,  $view) {
            $works = $staff->worklogs;

            $billableIssue = $works->whereNotIn('issue.project_id', [1])->where('issue.project.funding_source_id', 2)->sum('worked');
            $billableTask =  $works->whereIn('issue.project_id', [1])->where('issue.task.relatedProject.funding_source_id', 2)->sum('worked');
            $billable = $billableIssue + $billableTask;

            $total = $works->sum('worked');

            $internalIssue = $works->whereNotIn('issue.project_id', [1])->where('issue.project.funding_source_id', 1)->sum('worked');
            $internalTask =  $works->whereIn('issue.project_id', [1])->where('issue.task.relatedProject.funding_source_id', 1)->sum('worked');
            $internal = $internalIssue + $internalTask;

            $sale = $works->where('issue->issue_type_id', 19)->sum('worked');

            $nonBillableIssue = $works->whereNotIn('issue.project_id', [1])->whereNotIn('issue.project.funding_source_id', [1, 2])->sum('worked');
            $nonBillableTask = $works->whereIn('issue.project_id', [1])->where('issue.task.project_id', null)->sum('worked');
            $nonBillablePro = $works->whereIn('issue.project_id', [1])->whereNotIn('issue.task.relatedProject.funding_source_id', [1, 2])->whereNotIn('issue.task.project_id', [null])->sum('worked');
            $nonBillable = $nonBillableIssue + $nonBillableTask + $nonBillablePro;

            $workedProjects = $staff->worklogs()->whereBetween('worklogs.logged_for', [carbon()->parse($from), carbon()->parse($to)])
                ->join('issues', 'worklogs.issue_id', '=', 'issues.id')
                ->select('issues.project_id', DB::raw("SUM(worked) as total"))
                ->groupBy('project_id')
                ->orderBy('total', 'desc')->get()->first();
            if (isset($workedProjects->project_id) && $workedProjects->project_id == 1) {
                $workedProjects = $staff->worklogs()->whereBetween('worklogs.logged_for', [carbon()->parse($from), carbon()->parse($to)])
                    ->join('issues', 'worklogs.issue_id', '=', 'issues.id')
                    ->join('tasks', 'tasks.issue_id', '=', 'issues.id')
                    ->select('tasks.project_id', DB::raw("SUM(worked) as total"))
                    ->groupBy('project_id')
                    ->orderBy('total', 'desc')->get()->first();
                if (empty($workedProjects->project_id)) {
                    $workedProjects = Project::find(1);
                    $workedProjects->setAttribute('project_id', $workedProjects->id);
                }
            }

            $staff->project = $workedProjects ? Project::where('id', $workedProjects->project_id)->pluck('name')->first() : Null;
            if ($office == 'all') $office = $staff->offices()->wherePivot('is_default', 'Yes')->first();
            $timezone = $office ? $office->timezone : null;

            $allPublicHolidays = $timezone ? $this->publicHolidays($timezone, $from->toDateTimeString(), $to->toDateTimeString(), $staff, $dateRange) : [];
            $holidayLogs = [];
            $holidayTotal = [];
            $holidayTotalByType = [];
            foreach ($allPublicHolidays as $key => $holidays) {
                foreach ($holidays as $holiday) {
                    if (!isset($holidayLogs[$holiday['type']])) {
                        $holidayLogs[$holiday['type']] = [];
                        $holidayTotalByType[$holiday['type']] = 0;
                    }
                    foreach ($dateRange as $date) {
                        $d = $date->toDateString();
                        if (!isset($holidayTotal[$d])) {
                            $holidayTotal[$d] = 0;
                        }
                        if ($d == $key) {
                            $holidayLog = ['reason' => $holiday['what'], 'hours' => $holiday['holiday_hours']];
                            $holidayLogs[$holiday['type']][$d][] = $holidayLog;
                            $holidayTotal[$d] += $holiday['holiday_hours'] * 60;
                            $holidayTotalByType[$holiday['type']] += $holiday['holiday_hours'] * 60;
                        }
                    }
                    if (!$holidayTotalByType[$holiday['type']]) {
                        unset($holidayLogs[$holiday['type']]);
                    }
                }
            }

            $staff->project = $workedProjects ? Project::where('id', $workedProjects->project_id)->pluck('name')->first() : Null;
            $staff->customer_bill = timesheetHoursFormat($billable);
            $staff->internal_project = timesheetHoursFormat($internal);
            $staff->sale_support = timesheetHoursFormat($sale);
            $staff->non_billable = timesheetHoursFormat($nonBillable);
            $staff->totalLeave = count($holidayTotalByType) ? array_sum($holidayTotalByType) > 0 ? timesheetHoursFormat(array_sum($holidayTotalByType)) : 0 : 0;
            $fiTotal = timesheetHoursFormat($total) + $staff->totalLeave;
            $staff->total = $fiTotal;
            $staff->billablePercentage = ($staff->customer_bill && $fiTotal) ? round(($staff->customer_bill / $fiTotal) * 100, 2) : 0;
            $staff->interProjectPercentage = ($staff->internal_project && $staff->total) ? round(($staff->internal_project / $staff->total) * 100, 2) : 0;
            $staff->saleSupportPercentage = ($staff->sale_support && $staff->total) ? round(($staff->sale_support / $staff->total) * 100, 2) : 0;
            $staff->nonBillablePercentage = ($staff->non_billable && $staff->total) ? round(($staff->non_billable / $staff->total) * 100, 2) : 0;
            $staff->leavePercentage = ($staff->totalLeave && $staff->total) ? round(($staff->totalLeave / $staff->total) * 100, 2) : 0;
            $staff->location = $office->name ?? 'None';
            $staff->comment = MisComment::where('staff_id', $staff->id)->where('year', $year)
                    ->where('period_type', 'week')
                    ->where('period_no', $week)->pluck('comment')->first() ?? ' ';

            return [
                'id' => $staff['id'],
                'short_name' => $staff['short_name'],
                'name' => $staff['first_name'] . ' ' . $staff['last_name'],
                'major_project_involved' => $staff->project ?? 'None',
                'customer_bill' => $staff['customer_bill'] ?? 0,
                'sale_support' => $staff['sale_support'] ?? 0,
                'internal_project' => $staff['internal_project'] ?? 0,
                'non_billable' => $staff['non_billable'] ?? 0,
                'totalLeave' => $staff['totalLeave'] ?? 0,
                'total' => $staff['total'] ?? 0,
                'billablePercentage' => $staff['billablePercentage'] ?? 0,
                'saleSupportPercentage' => $staff['saleSupportPercentage'] ?? 0,
                'interProjectPercentage' => $staff['interProjectPercentage'] ?? 0,
                'nonBillablePercentage' => $staff['nonBillablePercentage'] ?? 0,
                'leavePercentage' => $staff['leavePercentage'] ?? 0,
                'comment' => $staff['comment'] ?? 'None',
                'location' => $staff['location'] ?? 'None',
            ];
        })->filter(function($staff){
            return ($staff['customer_bill'] !== 0 || $staff['sale_support'] !== 0 || $staff['internal_project'] !== 0 || $staff['non_billable'] !== 0) || $staff['totalLeave'] !== 0;
        });

        $report = compact('staffs', 'staff', 'view', 'office', 'week', 'company', 'year',
            'isCurrentWeek', 'isLastWeek', 'isNextWeek', 'currentWeek', 'prevWeek', 'nextWeek', 'timestamp');

        if ($office instanceof Office) {
            $office = $office->id;
        }

        if ($company instanceof Company) {
            $company = $company->id;
        }

        if (!$office) {
            logger('No Office to handle getWeekDetails');
            return;
        }

        $name = "$view.$office.$year.$week.$company";
        Storage::put("reports/$name.json", json_encode($report));
        cache()->put($name, $report);

        if (Storage::exists("reports/$name.txt")) {
            Storage::delete("reports/$name.txt");
        }

        ReportRequestCompleteEvent::dispatch($this->auth, $office, [
            'route' => route('mis.report.index', [
                'view' => $view,
                'year' => $year,
                'week' => $week
            ])
        ]);
    }

    private function getYearDetail()
    {
        $view = 'fy';
        $year = array_get($this->attributes, 'year');
        $timestamp = array_get($this->attributes, 'timestamp');
        $office = $this->office;
        $staff = $this->auth->staff()->first();
        $tz = $this->staffTimezone($staff);

        if ($office !== 'all') {
            $office = Office::find($office);
        }

        $company = 'all';
        $companyId = array_get($this->attributes, 'company');
        if ($companyId !== 'all') {
            $company = Company::find($companyId);
        }

        $month = null;
        if ($office === 'all' && $company === 'all') {
            $dateTime = Carbon::now($tz);
            $year = request()->get('year') ?? $dateTime->year;
            $month = $dateTime->month;
        } elseif ($office === 'all') {

            $configuration = $company ? $company->configurations->where('name', 'Financial Year')->first() : null;
            $configurationValue = $configuration ? json_decode($configuration->pivot->configuration_value) : null;
            $month = $configurationValue ? $configurationValue->fiscal_year_start : 1;

        } elseif ($office !== 'all') {
            $company = $office ? $office->company->load('configurations') : null;
            $configuration = $company ? $company->configurations->where('name', 'Financial Year')->first() : null;
            $configurationValue = $configuration ? json_decode($configuration->pivot->configuration_value) : null;
            $month = $configurationValue ? $configurationValue->fiscal_year_start : 1;
        }

        $from = Carbon::create($year, $month, 1)->startOfDay();
        $to = $from->copy()->addYear()->subDay()->endOfDay();

        $currentWeek = $from->copy()->startOfYear();
        $interval = new DateInterval('P1D');
        $dateRange = new DatePeriod($from, $interval, $to);

        $staffBuilder = null;
        if ($office === 'all' && $company === 'all') {
            $staffBuilder = Staff::on();
        } elseif ($office === 'all') {
            $staffBuilder = Staff::whereHas('offices', function ($query) use ($companyId) {
                $query->whereHas('company', function ($query) use ($companyId) {
                    $query->where('id', $companyId);
                });
            });
        } else {
            $staffBuilder = $office->staff()->where('is_default', 'Yes');
        }

        $availableStaff = $staffBuilder->orderBy('short_name', 'asc')->where(function ($q) use ($from, $to) {
            $q->where('joined_at', '<=', carbon()->parse($from)->toDateString())
                ->orWhereBetween('joined_at', [carbon()->parse($from)->toDateString(), carbon()->parse($to)->toDateString()]);
        })->with([
            'offices',
            'misComment',
            'worklogs' => function ($q) use ($from, $to) {
                $q->where('logged_for', '>=', carbon()->parse($from))
                    ->where('logged_for', '<=', carbon()->parse($to))
                    ->with([
                        'issue' => function ($q) {
                            $q->with([
                                'task' => function ($q) {
                                    $q->select('id', 'issue_id', 'project_id')->with([
                                        'relatedProject' => function ($q) {
                                            $q->select('id', 'funding_source_id');
                                        },
                                    ]);
                                },
                                'project' => function ($q) {
                                    $q->select('id', 'funding_source_id');
                                },
                            ]);
                        }
                    ]);
            },
        ])->get();
        $notAvailableStaff = $staffBuilder->orderBy('short_name', 'asc')->whereNotIn('left_at', [''])
            ->where('left_at', '<', carbon()->parse($from)->toDateTimeString())->get();
        $diff = $availableStaff->diff($notAvailableStaff);
        $staffs = $diff;

        $staffs->transform(function ($staff) use ($from, $to, $interval, $dateRange, $office, $year, $view) {
            $works = $staff->worklogs;

            $billableIssue = $works->whereNotIn('issue.project_id', [1])->where('issue.project.funding_source_id', 2)->sum('worked');
            $billableTask =  $works->whereIn('issue.project_id', [1])->where('issue.task.relatedProject.funding_source_id', 2)->sum('worked');
            $billable = $billableIssue + $billableTask;

            $total = $works->sum('worked');

            $internalIssue = $works->whereNotIn('issue.project_id', [1])->where('issue.project.funding_source_id', 1)->sum('worked');
            $internalTask =  $works->whereIn('issue.project_id', [1])->where('issue.task.relatedProject.funding_source_id', 1)->sum('worked');
            $internal = $internalIssue + $internalTask;

            $sale = $works->where('issue->issue_type_id', 19)->sum('worked');

            $nonBillableIssue = $works->whereNotIn('issue.project_id', [1])->whereNotIn('issue.project.funding_source_id', [1, 2])->sum('worked');
            $nonBillableTask = $works->whereIn('issue.project_id', [1])->where('issue.task.project_id', null)->sum('worked');
            $nonBillablePro = $works->whereIn('issue.project_id', [1])->whereNotIn('issue.task.relatedProject.funding_source_id', [1, 2])->whereNotIn('issue.task.project_id', [null])->sum('worked');
            $nonBillable = $nonBillableIssue + $nonBillableTask + $nonBillablePro;


            $workedProjects = $staff->worklogs()->whereBetween('worklogs.logged_for', [carbon()->parse($from), carbon()->parse($to)])
                ->join('issues', 'worklogs.issue_id', '=', 'issues.id')
                ->select('issues.project_id', DB::raw("SUM(worked) as total"))
                ->groupBy('project_id')
                ->orderBy('total', 'desc')->get()->first();
            if (isset($workedProjects->project_id) && $workedProjects->project_id == 1) {
                $workedProjects = $staff->worklogs()->whereBetween('worklogs.logged_for', [carbon()->parse($from), carbon()->parse($to)])
                    ->join('issues', 'worklogs.issue_id', '=', 'issues.id')
                    ->join('tasks', 'tasks.issue_id', '=', 'issues.id')
                    ->select('tasks.project_id', DB::raw("SUM(worked) as total"))
                    ->groupBy('project_id')
                    ->orderBy('total', 'desc')->get()->first();
                if (empty($workedProjects->project_id)) {
                    $workedProjects = Project::find(1);
                    $workedProjects->setAttribute('project_id', $workedProjects->id);
                }
            }

            $staff->project = $workedProjects ? Project::where('id', $workedProjects->project_id)->pluck('name')->first() : Null;
            if ($office == 'all') $office = $staff->offices()->wherePivot('is_default', 'Yes')->first();
            $timezone = $office ? $office->timezone : null;

            $allPublicHolidays = $timezone ? $this->publicHolidays($timezone, $from->toDateTimeString(), $to->toDateTimeString(), $staff, $dateRange) : [];
            $holidayLogs = [];
            $holidayTotal = [];
            $holidayTotalByType = [];
            foreach ($allPublicHolidays as $key => $holidays) {
                foreach ($holidays as $holiday) {
                    if (!isset($holidayLogs[$holiday['type']])) {
                        $holidayLogs[$holiday['type']] = [];
                        $holidayTotalByType[$holiday['type']] = 0;
                    }
                    foreach ($dateRange as $date) {
                        $d = $date->toDateString();
                        if (!isset($holidayTotal[$d])) {
                            $holidayTotal[$d] = 0;
                        }
                        if ($d == $key) {
                            $holidayLog = ['reason' => $holiday['what'], 'hours' => $holiday['holiday_hours']];
                            $holidayLogs[$holiday['type']][$d][] = $holidayLog;
                            $holidayTotal[$d] += $holiday['holiday_hours'] * 60;
                            $holidayTotalByType[$holiday['type']] += $holiday['holiday_hours'] * 60;
                        }
                    }
                    if (!$holidayTotalByType[$holiday['type']]) {
                        unset($holidayLogs[$holiday['type']]);
                    }
                }
            }

            $staff->customer_bill = changeHoursFormat($billable);
            $staff->internal_project = changeHoursFormat($internal);
            $staff->sale_support = changeHoursFormat($sale);
            $staff->non_billable = changeHoursFormat($nonBillable);
            $totalLeave = count($holidayTotalByType) ? array_sum($holidayTotalByType) > 0 ? array_sum($holidayTotalByType) : 0 : 0;
            $fiTotal = $total + $totalLeave;
            $staff->totalLeave = timesheetHoursFormat($totalLeave);
            $staff->total = changeHoursFormat($fiTotal);
            $grandTotal = str_replace(',', '', $staff->total);
            $staff->billablePercentage = ($staff->customer_bill && $grandTotal) ? number_format(((str_replace(',', '', $staff->customer_bill) / $grandTotal) * 100), 2) : 0;
            $staff->interProjectPercentage = ($staff->internal_project && $grandTotal) ? number_format(((str_replace(',', '', $staff->internal_project) / $grandTotal) * 100), 2) : 0;
            $staff->saleSupportPercentage = ($staff->sale_support && $grandTotal) ? number_format(((str_replace(',', '', $staff->sale_support) / $grandTotal) * 100), 2) : 0;
            $staff->nonBillablePercentage = ($staff->non_billable && $grandTotal) ? number_format(((str_replace(',', '', $staff->non_billable) / $grandTotal) * 100), 2) : 0;
            $staff->leavePercentage = ($staff->totalLeave && $grandTotal) ? number_format(((str_replace(',', '', $staff->totalLeave) / $grandTotal) * 100), 2) : 0;
            $staff->location = $office->name ?? 'None';
            $staff->comment = MisComment::where('staff_id', $staff->id)->where('year', $year)->where('period_type', 'fy')->pluck('comment')->first() ?? ' ';


            return [
                'id' => $staff['id'],
                'short_name' => $staff['short_name'],
                'name' => $staff['first_name'] . ' ' . $staff['last_name'],
                'major_project_involved' => $staff['project'] ?? 'None',
                'customer_bill' => $staff['customer_bill'] ?? 0,
                'sale_support' => $staff['sale_support'] ?? 0,
                'internal_project' => $staff['internal_project'] ?? 0,
                'non_billable' => $staff['non_billable'] ?? 0,
                'totalLeave' => $staff['totalLeave'] ?? 0,
                'total' => $staff['total'] ?? 0,
                'billablePercentage' => $staff['billablePercentage'] ?? 0,
                'saleSupportPercentage' => $staff['saleSupportPercentage'] ?? 0,
                'interProjectPercentage' => $staff['interProjectPercentage'] ?? 0,
                'nonBillablePercentage' => $staff['nonBillablePercentage'] ?? 0,
                'leavePercentage' => $staff['leavePercentage'] ?? 0,
                'comment' => $staff['comment'] ?? 'None',
                'location' => $staff['location'] ?? 'None',
            ];
        });
        $staffs = $staffs->filter(function($staff){
            return $staff['total'] != 0;
        });
        $report = compact('staffs', 'staff', 'view', 'office', 'year', 'to', 'from', 'currentWeek', 'timestamp', 'company');

        if ($office instanceof Office) {
            $office = $office->id;
        }

        if ($company instanceof Company) {
            $company = $company->id;
        }

        if (!$office) {
            logger('No Office to handle getYearDetail');
            return;
        }

        $name = "$view.$office.$year.$company";
        Storage::put("reports/$name.json", json_encode($report));
        cache()->put($name, $report);

        if (Storage::exists("reports/$name.txt")) {
            Storage::delete("reports/$name.txt");
        }

        ReportRequestCompleteEvent::dispatch($this->auth, $office, [
            'route' => route('mis.report.index', [
                'view' => $view,
                'year' => $year,
            ])
        ]);
    }

    private function getQuarterDetail()
    {
        $view = 'quarter';
        $year = array_get($this->attributes, 'year');
        $quarter = array_get($this->attributes, 'quarter');
        $timestamp = array_get($this->attributes, 'timestamp');
        $office = $this->office;

        if ($office !== 'all') {
            $office = Office::find($office);
        }

        $company = 'all';
        $companyId = array_get($this->attributes, 'company');
        if ($companyId !== 'all') {
            $company = Company::find($companyId);
        }

        $month = $quarter * 3;
        $from = Carbon::create($year, $month, 1)->firstOfQuarter();
        $to = $from->copy()->lastOfQuarter()->endOfDay();
        $currentWeek = $from->copy()->startOfMonth();
        $interval = new DateInterval('P1D');
        $dateRange = new DatePeriod($from, $interval, $to);

        $staffBuilder = null;

        if ($office === 'all' && $company === 'all') {
            $staffBuilder = Staff::on();
        } elseif ($office === 'all') {
            $staffBuilder = Staff::whereHas('offices', function ($query) use ($companyId) {
                $query->whereHas('company', function ($query) use ($companyId) {
                    $query->where('id', $companyId);
                });
            });
        } else {
            $staffBuilder = $office->staff()->where('is_default', 'Yes');
        }
        $availableStaff = $staffBuilder->orderBy('short_name', 'asc')->where(function ($q) use ($from, $to) {
            $q->where('joined_at', '<=', carbon()->parse($from)->toDateString())
                ->orWhereBetween('joined_at', [carbon()->parse($from)->toDateString(), carbon()->parse($to)->toDateString()]);
        })->with([
            'offices',
            'misComment',
            'worklogs' => function ($q) use ($from, $to) {
                $q->where('logged_for', '>=', carbon()->parse($from))
                    ->where('logged_for', '<=', carbon()->parse($to))
                    ->with([
                        'issue' => function ($q) {
                            $q->with([
                                'task' => function ($q) {
                                    $q->select('id', 'issue_id', 'project_id')->with([
                                        'relatedProject' => function ($q) {
                                            $q->select('id', 'funding_source_id');
                                        },
                                    ]);
                                },
                                'project' => function ($q) {
                                    $q->select('id', 'funding_source_id');
                                },
                            ]);
                        }
                    ]);
            },
        ])->get();
        $notAvailableStaff = $staffBuilder->orderBy('short_name', 'asc')->whereNotIn('left_at', [''])
            ->where('left_at', '<', carbon()->parse($from)->toDateTimeString())->get();
        $diff = $availableStaff->diff($notAvailableStaff);
        $staffs = $diff;

        $staffs = $staffs->transform(function ($staff) use ($from, $to, $interval, $dateRange, $office, $year, $quarter,  $view) {
            $works = $staff->worklogs;

            $billableIssue = $works->whereNotIn('issue.project_id', [1])->where('issue.project.funding_source_id', 2)->sum('worked');
            $billableTask =  $works->whereIn('issue.project_id', [1])->where('issue.task.relatedProject.funding_source_id', 2)->sum('worked');
            $billable = $billableIssue + $billableTask;

            $total = $works->sum('worked');

            $internalIssue = $works->whereNotIn('issue.project_id', [1])->where('issue.project.funding_source_id', 1)->sum('worked');
            $internalTask =  $works->whereIn('issue.project_id', [1])->where('issue.task.relatedProject.funding_source_id', 1)->sum('worked');
            $internal = $internalIssue + $internalTask;

            $sale = $works->where('issue->issue_type_id', 19)->sum('worked');

            $nonBillableIssue = $works->whereNotIn('issue.project_id', [1])->whereNotIn('issue.project.funding_source_id', [1, 2])->sum('worked');
            $nonBillableTask = $works->whereIn('issue.project_id', [1])->where('issue.task.project_id', null)->sum('worked');
            $nonBillablePro = $works->whereIn('issue.project_id', [1])->whereNotIn('issue.task.relatedProject.funding_source_id', [1, 2])->whereNotIn('issue.task.project_id', [null])->sum('worked');
            $nonBillable = $nonBillableIssue + $nonBillableTask + $nonBillablePro;

            $workedProjects = $staff->worklogs()->whereBetween('worklogs.logged_for', [carbon()->parse($from), carbon()->parse($to)])
                ->join('issues', 'worklogs.issue_id', '=', 'issues.id')
                ->select('issues.project_id', DB::raw("SUM(worked) as total"))
                ->groupBy('project_id')
                ->orderBy('total', 'desc')->get()->first();
            if (isset($workedProjects->project_id) && $workedProjects->project_id == 1) {
                $workedProjects = $staff->worklogs()->whereBetween('worklogs.logged_for', [carbon()->parse($from), carbon()->parse($to)])
                    ->join('issues', 'worklogs.issue_id', '=', 'issues.id')
                    ->join('tasks', 'tasks.issue_id', '=', 'issues.id')
                    ->select('tasks.project_id', DB::raw("SUM(worked) as total"))
                    ->groupBy('project_id')
                    ->orderBy('total', 'desc')->get()->first();
                if (empty($workedProjects->project_id)) {
                    $workedProjects = Project::find(1);
                    $workedProjects->setAttribute('project_id', $workedProjects->id);
                }
            }

            $staff->project = $workedProjects ? Project::where('id', $workedProjects->project_id)->pluck('name')->first() : Null;
            if ($office == 'all') $office = $staff->offices()->wherePivot('is_default', 'Yes')->first();
            $timezone = $office ? $office->timezone : null;

            $allPublicHolidays = $timezone ? $this->publicHolidays($timezone, $from->toDateTimeString(), $to->toDateTimeString(), $staff, $dateRange) : [];
            $holidayLogs = [];
            $holidayTotal = [];
            $holidayTotalByType = [];
            foreach ($allPublicHolidays as $key => $holidays) {
                foreach ($holidays as $holiday) {
                    if (!isset($holidayLogs[$holiday['type']])) {
                        $holidayLogs[$holiday['type']] = [];
                        $holidayTotalByType[$holiday['type']] = 0;
                    }
                    foreach ($dateRange as $date) {
                        $d = $date->toDateString();
                        if (!isset($holidayTotal[$d])) {
                            $holidayTotal[$d] = 0;
                        }
                        if ($d == $key) {
                            $holidayLog = ['reason' => $holiday['what'], 'hours' => $holiday['holiday_hours']];
                            $holidayLogs[$holiday['type']][$d][] = $holidayLog;
                            $holidayTotal[$d] += $holiday['holiday_hours'] * 60;
                            $holidayTotalByType[$holiday['type']] += $holiday['holiday_hours'] * 60;
                        }
                    }
                    if (!$holidayTotalByType[$holiday['type']]) {
                        unset($holidayLogs[$holiday['type']]);
                    }
                }
            }
            $staff->project = $workedProjects ? Project::where('id', $workedProjects->project_id)->pluck('name')->first() : Null;


            $staff->customer_bill = timesheetHoursFormat($billable);
            $staff->internal_project = timesheetHoursFormat($internal);
            $staff->sale_support = timesheetHoursFormat($sale);
            $staff->non_billable = timesheetHoursFormat($nonBillable);
            $staff->totalLeave = count($holidayTotalByType) ? array_sum($holidayTotalByType) > 0 ? timesheetHoursFormat(array_sum($holidayTotalByType)) : 0 : 0;
            $fiTotal = timesheetHoursFormat($total) + $staff->totalLeave;
            $staff->total = round($fiTotal, 2);
            $staff->billablePercentage = ($staff->customer_bill && $fiTotal) ? round(($staff->customer_bill / $fiTotal) * 100, 2) : 0;
            $staff->interProjectPercentage = ($staff->internal_project && $fiTotal) ? round(($staff->internal_project / $fiTotal) * 100, 2) : 0;
            $staff->saleSupportPercentage = ($staff->sale_support && $fiTotal) ? round(($staff->sale_support / $fiTotal) * 100, 2) : 0;
            $staff->nonBillablePercentage = ($staff->non_billable && $fiTotal) ? round(($staff->non_billable / $fiTotal) * 100, 2) : 0;
            $staff->leavePercentage = ($staff->totalLeave && $fiTotal) ? round(($staff->totalLeave / $fiTotal) * 100, 2) : 0;
            $staff->location = $office->name ?? 'None';
            $staff->comment = $staff->comment = MisComment::where('staff_id', $staff->id)->where('year', $year)->where('period_type', 'quarter')->where('period_no', $quarter)->pluck('comment')->first() ?? ' ';
            return [
                'id' => $staff['id'],
                'short_name' => $staff['short_name'],
                'name' => $staff['first_name'] . ' ' . $staff['last_name'],
                'major_project_involved' => $staff['project'] ?? 'None',
                'customer_bill' => $staff['customer_bill'] ?? 0,
                'sale_support' => $staff['sale_support'] ?? 0,
                'internal_project' => $staff['internal_project'] ?? 0,
                'non_billable' => $staff['non_billable'] ?? 0,
                'totalLeave' => $staff['totalLeave'] ?? 0,
                'total' => $staff['total'] ?? 0,
                'billablePercentage' => $staff['billablePercentage'] ?? 0,
                'saleSupportPercentage' => $staff['saleSupportPercentage'] ?? 0,
                'interProjectPercentage' => $staff['interProjectPercentage'] ?? 0,
                'nonBillablePercentage' => $staff['nonBillablePercentage'] ?? 0,
                'leavePercentage' => $staff['leavePercentage'] ?? 0,
                'comment' => $staff['comment'] ?? 'None',
                'location' => $staff['location'] ?? 'None',
            ];
        })->filter(function($staff){
            return ($staff['customer_bill'] !== 0 || $staff['sale_support'] !== 0 || $staff['internal_project'] !== 0 || $staff['non_billable'] !== 0) || $staff['totalLeave'] !== 0;
        });

        $report = compact('staffs', 'staff', 'view', 'office', 'quarter', 'year', 'to', 'from', 'currentWeek', 'timestamp', 'company');

        if ($office instanceof Office) {
            $office = $office->id;
        }

        if ($company instanceof Company) {
            $company = $company->id;
        }

        if (!$office) {
            logger('No Office to handle getQuarterDetail');
            return;
        }

        $name = "$view.$office.$year.$quarter.$company";
        Storage::put("reports/$name.json", json_encode($report));
        cache()->put($name, $report);

        if (Storage::exists("reports/$name.txt")) {
            Storage::delete("reports/$name.txt");
        }

        ReportRequestCompleteEvent::dispatch($this->auth, $office, [
            'route' => route('mis.report.index', [
                'view' => $view,
                'year' => $year,
                'quarter' => $quarter
            ])
        ]);
    }

    private function getCustomDetail(){
        $from = array_get($this->attributes, 'from');
        $view = array_get($this->attributes, 'view');
        $to = array_get($this->attributes, 'to');
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->endOfDay();
        $year = $fromDate->year;
        $timestamp = array_get($this->attributes, 'timestamp');
        $office = $this->office;
        $currentWeek = $toDate->copy()->startOfWeek();
        if ($office !== 'all') {
            $office = Office::find($office);
        }

        $company = 'all';
        $companyId = array_get($this->attributes, 'company');
        if ($companyId !== 'all') {
            $company = Company::find($companyId);
        }

        $interval = new DateInterval('P1D');
        $dateRange = new DatePeriod($fromDate, $interval, $toDate);
        $staffBuilder = null;

        if ($office === 'all' && $company === 'all') {
            $staffBuilder = Staff::on();
        } elseif ($office === 'all') {
            $staffBuilder = Staff::whereHas('offices', function ($query) use ($companyId) {
                $query->whereHas('company', function ($query) use ($companyId) {
                    $query->where('id', $companyId);
                });
            });
        } else {
            $staffBuilder = $office->staff()->where('is_default', 'Yes');
        }
        $availableStaff = $staffBuilder->orderBy('short_name', 'asc')->where(function ($q) use ($from, $to, $fromDate, $toDate) {
            $q->where('joined_at', '<=', carbon()->parse($from)->toDateString())
                ->orWhereBetween('joined_at', [carbon()->parse($from)->toDateString(), carbon()->parse($to)->toDateString()]);
        })->with([
            'offices',
            'misComment',
            'worklogs' => function ($q) use ($fromDate, $toDate) {
                $q->where('logged_for', '>=', $fromDate)
                    ->where('logged_for', '<=', $toDate)
                    ->with([
                        'issue' => function ($q) {
                            $q->with([
                                'task' => function ($q) {
                                    $q->select('id', 'issue_id', 'project_id')->with([
                                        'relatedProject' => function ($q) {
                                            $q->select('id', 'funding_source_id');
                                        },
                                    ]);
                                },
                                'project' => function ($q) {
                                    $q->select('id', 'funding_source_id');
                                },
                            ]);
                        }
                    ]);
            },
        ])->get();
        $notAvailableStaff = $staffBuilder->orderBy('short_name', 'asc')->whereNotIn('left_at', [''])
            ->where('left_at', '<', carbon()->parse($from)->toDateTimeString())->get();
        $diff = $availableStaff->diff($notAvailableStaff);
        $staffs = $diff;

        $staffs = $staffs->transform(function ($staff) use ($from, $to, $interval, $dateRange, $office, $view, $year) {
            $works = $staff->worklogs;

            $billableIssue = $works->whereNotIn('issue.project_id', [1])->where('issue.project.funding_source_id', 2)->sum('worked');
            $billableTask =  $works->whereIn('issue.project_id', [1])->where('issue.task.relatedProject.funding_source_id', 2)->sum('worked');
            $billable = $billableIssue + $billableTask;

            $total = $works->sum('worked');

            $internalIssue = $works->whereNotIn('issue.project_id', [1])->where('issue.project.funding_source_id', 1)->sum('worked');
            $internalTask =  $works->whereIn('issue.project_id', [1])->where('issue.task.relatedProject.funding_source_id', 1)->sum('worked');
            $internal = $internalIssue + $internalTask;

            $sale = $works->where('issue->issue_type_id', 19)->sum('worked');

            $nonBillableIssue = $works->whereNotIn('issue.project_id', [1])->whereNotIn('issue.project.funding_source_id', [1, 2])->sum('worked');
            $nonBillableTask = $works->whereIn('issue.project_id', [1])->where('issue.task.project_id', null)->sum('worked');
            $nonBillablePro = $works->whereIn('issue.project_id', [1])->whereNotIn('issue.task.relatedProject.funding_source_id', [1, 2])->whereNotIn('issue.task.project_id', [null])->sum('worked');
            $nonBillable = $nonBillableIssue + $nonBillableTask + $nonBillablePro;

            $workedProjects = $staff->worklogs()->whereBetween('worklogs.logged_for', [carbon()->parse($from), carbon()->parse($to)])
                ->join('issues', 'worklogs.issue_id', '=', 'issues.id')
                ->select('issues.project_id', DB::raw("SUM(worked) as total"))
                ->groupBy('project_id')
                ->orderBy('total', 'desc')->get()->first();
            if (isset($workedProjects->project_id) && $workedProjects->project_id == 1) {
                $workedProjects = $staff->worklogs()->whereBetween('worklogs.logged_for', [carbon()->parse($from), carbon()->parse($to)])
                    ->join('issues', 'worklogs.issue_id', '=', 'issues.id')
                    ->join('tasks', 'tasks.issue_id', '=', 'issues.id')
                    ->select('tasks.project_id', DB::raw("SUM(worked) as total"))
                    ->groupBy('project_id')
                    ->orderBy('total', 'desc')->get()->first();
                if (empty($workedProjects->project_id)) {
                    $workedProjects = Project::find(1);
                    $workedProjects->setAttribute('project_id', $workedProjects->id);
                }
            }

            $staff->project = $workedProjects ? Project::where('id', $workedProjects->project_id)->pluck('name')->first() : Null;
            if ($office == 'all') $office = $staff->offices()->wherePivot('is_default', 'Yes')->first();
            $timezone = $office ? $office->timezone : null;

            $allPublicHolidays = $timezone ? $this->publicHolidays($timezone, Carbon::parse($from)->toDateTimeString(), Carbon::parse($to)->toDateTimeString(), $staff, $dateRange) : [];
            $holidayLogs = [];
            $holidayTotal = [];
            $holidayTotalByType = [];
            foreach ($allPublicHolidays as $key => $holidays) {
                foreach ($holidays as $holiday) {
                    if (!isset($holidayLogs[$holiday['type']])) {
                        $holidayLogs[$holiday['type']] = [];
                        $holidayTotalByType[$holiday['type']] = 0;
                    }
                    foreach ($dateRange as $date) {
                        $d = $date->toDateString();
                        if (!isset($holidayTotal[$d])) {
                            $holidayTotal[$d] = 0;
                        }
                        if ($d == $key) {
                            $holidayLog = ['reason' => $holiday['what'], 'hours' => $holiday['holiday_hours']];
                            $holidayLogs[$holiday['type']][$d][] = $holidayLog;
                            $holidayTotal[$d] += $holiday['holiday_hours'] * 60;
                            $holidayTotalByType[$holiday['type']] += $holiday['holiday_hours'] * 60;
                        }
                    }
                    if (!$holidayTotalByType[$holiday['type']]) {
                        unset($holidayLogs[$holiday['type']]);
                    }
                }
            }
            $staff->project = $workedProjects ? Project::where('id', $workedProjects->project_id)->pluck('name')->first() : Null;


            $staff->customer_bill = changeHoursFormat($billable);
            $staff->internal_project =changeHoursFormat($internal);
            $staff->sale_support = changeHoursFormat($sale);
            $staff->non_billable = changeHoursFormat($nonBillable);
            $totalLeave = count($holidayTotalByType) ? array_sum($holidayTotalByType) > 0 ? array_sum($holidayTotalByType) : 0 : 0;
            $fiTotal = $total + $totalLeave;
            $staff->totalLeave = changeHoursFormat($totalLeave);
            $staff->total = changeHoursFormat($fiTotal);
            $grandTotal = str_replace(',', '', $staff->total);
            $staff->billablePercentage = ($staff->customer_bill && $grandTotal) ? number_format(((str_replace(',', '', $staff->customer_bill) / $grandTotal) * 100), 2) : 0;
            $staff->interProjectPercentage = ($staff->internal_project && $grandTotal) ? number_format(((str_replace(',', '', $staff->internal_project) / $grandTotal) * 100), 2) : 0;
            $staff->saleSupportPercentage = ($staff->sale_support && $grandTotal) ? number_format(((str_replace(',', '', $staff->sale_support) / $grandTotal) * 100), 2) : 0;
            $staff->nonBillablePercentage = ($staff->non_billable && $grandTotal) ? number_format(((str_replace(',', '', $staff->non_billable) / $grandTotal) * 100), 2) : 0;
            $staff->leavePercentage = ($staff->totalLeave && $grandTotal) ? number_format(((str_replace(',', '', $staff->totalLeave) / $grandTotal) * 100), 2) : 0;
            $staff->location = $office->name ?? 'None';
            $staff->comment = null;
            return [
                'id' => $staff['id'],
                'short_name' => $staff['short_name'],
                'name' => $staff['first_name'] . ' ' . $staff['last_name'],
                'major_project_involved' => $staff['project'] ?? 'None',
                'customer_bill' => $staff['customer_bill'] ?? 0,
                'sale_support' => $staff['sale_support'] ?? 0,
                'internal_project' => $staff['internal_project'] ?? 0,
                'non_billable' => $staff['non_billable'] ?? 0,
                'totalLeave' => $staff['totalLeave'] ?? 0,
                'total' => $staff['total'] ?? 0,
                'billablePercentage' => $staff['billablePercentage'] ?? 0,
                'saleSupportPercentage' => $staff['saleSupportPercentage'] ?? 0,
                'interProjectPercentage' => $staff['interProjectPercentage'] ?? 0,
                'nonBillablePercentage' => $staff['nonBillablePercentage'] ?? 0,
                'leavePercentage' => $staff['leavePercentage'] ?? 0,
                'comment' => $staff['comment'] ?? 'None',
                'location' => $staff['location'] ?? 'None',
            ];
        })->filter(function($staff){
            return $staff['total'] != 0;
        });

        $report = compact('staffs', 'timestamp', 'staff', 'view', 'office', 'fromDate', 'toDate',
            'isProcessing', 'companies', 'offices', 'company', 'year', 'currentWeek');

        if ($office instanceof Office) {
            $office = $office->id;
        }

        if ($company instanceof Company) {
            $company = $company->id;
        }

        if (!$office) {
            logger('No Office to handle getQuarterDetail');
            return;
        }

        $start = strtotime($fromDate);
        $end = strtotime($toDate);
        $name = "$view.$office.$start.$end.$company";
        Storage::put("reports/$name.json", json_encode($report));
        cache()->put($name, $report);

        if (Storage::exists("reports/$name.txt")) {
            Storage::delete("reports/$name.txt");
        }

        ReportRequestCompleteEvent::dispatch($this->auth, $office, [
            'route' => route('mis.report.index', [
                'view' => $view,
                'year' => $year
            ])
        ]);
    }
}
