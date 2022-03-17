<?php

namespace App\Http\Controllers\MIS;

use App\Company;
use App\Country;
use App\Events\MIS\ReportRequestEvent;
use App\Events\MISShared;
use App\Http\Controllers\Controller;
use App\Http\Requests\MISSharedRequest;
use App\Office;
use App\Project;
use App\Repositories\StaffRepository;
use App\Staff;
use App\User;
use Carbon\Carbon;
use DateInterval;
use DatePeriod;
use App\Event;
use App\Leave;
use App\EventType;
use App\SubstituteDay;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use phpDocumentor\Reflection\DocBlock\Tags\Formatter\AlignFormatter;
use Storage;

class ReportController extends Controller
{
    protected $staff;

    private $companies;
    private $offices;

    private $company;
    private $office;

    public function __construct(StaffRepository $staff)
    {
        $this->staff = $staff;
        parent::__construct();
    }

    public function index(Request $request)
    {
        $companies = Company::all();
        $companyId = request()->get('company');

        $offices = Office::all();
        if ($companyId && $companyId !== 'all') {
            $offices = $offices->filter(function ($office) use ($companyId) {
                return $office->company_id == $companyId;
            });
        }

        $this->companies = $companies;
        $this->offices = $offices;

        $company = 'all';
        $companyId = $request->get('company');

        if (!$companyId) {
            $company = session()->get('chosen_company');
        } elseif ($companyId !== 'all') {
            $company = Company::find($companyId);
        }

        if ($company instanceof Company) {
            $company = $company->id;
        }
        $this->company = $company;

        $office = 'all';
        $officeId = $request->get('office');

        if (!$officeId) {
            $office = session()->get('chosen_office');
        } elseif ($officeId !== 'all') {
            $office = Office::find($officeId);
        }

        if ($office instanceof Office) {
            $office = $office->id;
        }
        $this->office = $office;

        $auth = auth()->user();
        $view = $request->get('view');

        if ($view === 'month') {
            return $this->getMonthDetails($auth, $office);
        } else if ($view === 'quarter') {
            return $this->getQuarterDetail($auth, $office);
        } else if ($view === 'fy') {
            return $this->getYearDetail($auth, $office);
        } else if ($view === 'custom') {
            return $this->getCustomDetail($auth, $office);
        } else {
            return $this->getWeekDetails($auth, $office);
        }

    }

    private function getMonthDetails($auth, $office)
    {
        $view = 'month';
        $request = request();


        $staff = $auth->staff()->first();
        $tz = $this->staffTimezone($staff);
        $company = $this->company;

        $timestamp = Carbon::now()->timezone($tz)->format('F  jS,   Y,   g:i a');
        $year = null;
        $month = null;
        if (!$request->get('year') && !$request->get('month')) {
            $dateTime = Carbon::now($tz);
            $year = $dateTime->year;
            $month = $dateTime->month;
        } else {
            $year = \request()->get('year');
            $month = \request()->get('month');
        }
        $companies = $this->companies;
        $offices = $this->offices;


        $key = "$view.$office.$year.$month.$company";

        if ($request->get('generate')) {
            Storage::put("reports/$key.txt", "Generating");
            ReportRequestEvent::dispatch($auth, $office, [
                'view' => $view,
                'year' => $year,
                'month' => $month,
                'timestamp' => $timestamp,
                'company' => $company
            ]);

            return response()->json([
                'success' => true
            ]);
        }

        $report = cache($key);
        if (!$report && Storage::exists("reports/$key.json")) {
            $report = json_decode(Storage::get("reports/$key.json"), true);
        }

        $isProcessing = Storage::exists("reports/$key.txt");
        if (!$report) {
            $isCurrentMonth = (carbon()->tz($tz)->year == $year && carbon()->tz($tz)->month == $month)
                ? carbon()->now($tz)
                : false;
            $isLastMonth = (carbon()->now($tz)->subMonth()->year == $year && carbon()->now($tz)->subMonth()->month == $month)
                ? carbon()->now($tz)->subMonth()
                : false;
            $isNextMonth = (carbon()->now($tz)->addMonth()->year == $year && carbon()->now($tz)->addMonth()->month == $month)
                ? carbon()->now($tz)->addMonth()
                : false;

            $currentMonth = carbon()->tz($tz)->setDate($year, $month, 1);
            $prevMonth = carbon()->tz($tz)->setDate($year, $month, 1)->subMonth();
            $nextMonth = carbon()->tz($tz)->setDate($year, $month, 1)->addMonth();

            $from = $currentMonth->copy()->startOfMonth();
            $currentWeek = $from->copy()->startOfMonth();


            return view('mis.index', compact('view', 'office', 'month', 'year',
                'currentWeek', 'isCurrentMonth', 'isLastMonth', 'isNextMonth', 'currentMonth',
                'prevMonth', 'nextMonth', 'isProcessing', 'companies', 'offices', 'company'));
        }

        extract($report);
        if (request()->ajax()) {
            $staffs = $report['staffs'];
            if (is_array($staffs)) {
                $staffs = array_values($staffs);
            }
            return response()->json($staffs);
        }

        return view('mis.index', compact('staffs', 'staff', 'view', 'office', 'month', 'year',
            'currentWeek', 'isCurrentMonth', 'isLastMonth', 'isNextMonth', 'currentMonth', 'prevMonth',
            'nextMonth', 'isProcessing', 'timestamp', 'companies', 'offices', 'company'));
    }

    private function getQuarterDetail($auth, $office)
    {
        $view = 'quarter';
        $request = request();
        $staff = $auth->staff()->first();
        $company = $this->company;

        $year = request()->get('year');
        $quarter = request()->get('quarter');
        if ($quarter < 1) $quarter = 1;
        if ($quarter > 4) $quarter = 4;

        $tz = $this->staffTimezone($staff);
        $timestamp = Carbon::now()->timezone($tz)->format('F  jS,   Y,   g:i a');

        $key = "$view.$office.$year.$quarter.$company";
        if ($request->get('generate')) {

            Storage::put("reports/$key.txt", "Generating");
            ReportRequestEvent::dispatch($auth, $office, [
                'view' => $view,
                'year' => $year,
                'quarter' => $quarter,
                'timestamp' => $timestamp,
                'company' => $company,
            ]);

            return response()->json([
                'success' => true
            ]);
        }

        $report = cache($key);
        if (!$report && Storage::exists("reports/$key.json")) {
            $report = json_decode(Storage::get("reports/$key.json"), true);
        }

        $companies = $this->companies;
        $offices = $this->offices;

        $isProcessing = Storage::exists("reports/$key.txt");
        if (!$report) {
            $month = $quarter * 3;
            $from = Carbon::create($year, $month, 1)->firstOfQuarter();
            $to = $from->copy()->lastOfQuarter()->endOfDay();
            $currentWeek = $from->copy()->startOfQuarter();

            return view('mis.quarter', compact(
                'staffs', 'staff', 'view', 'quarter', 'office', 'year', 'to', 'from',
                'currentWeek', 'isProcessing', 'companies', 'offices', 'company'
            ));
        }

        extract($report);
        if (request()->ajax()) {
            $staffs = $report['staffs'];
            if (is_array($staffs)) {
                $staffs = array_values($staffs);
            }
            return response()->json($staffs);
        }

        return view('mis.quarter', compact(
            'staffs', 'staff', 'view', 'office', 'quarter', 'year', 'to', 'from',
            'currentWeek', 'isProcessing', 'timestamp', 'companies', 'offices', 'company'
        ));
    }

    private function getYearDetail($auth, $office)
    {
        $view = 'fy';
        $year = \request()->get('year');
        $request = request();
        $staff = $auth->staff()->first();
        $company = $this->company;
        $tz = $this->staffTimezone($staff);
        $timestamp = Carbon::now()->timezone($tz)->format('F  jS,   Y,   g:i a');

        $key = "$view.$office.$year.$company";
        if ($request->get('generate')) {
            Storage::put("reports/$key.txt", "Generating");
            ReportRequestEvent::dispatch($auth, $office, [
                'view' => $view,
                'year' => $year,
                'timestamp' => $timestamp,
                'company' => $company,
            ]);

            return response()->json([
                'success' => true
            ]);
        }

        $report = cache($key);
        if (!$report && Storage::exists("reports/$key.json")) {
            $report = json_decode(Storage::get("reports/$key.json"), true);
        }

        $companies = $this->companies;
        $offices = $this->offices;

        $isProcessing = Storage::exists("reports/$key.txt");
        if (!$report) {
            if ($office !== 'all') {
                $office = Office::find($office);
            }
            if ($company !== 'all') {
                $company = Company::find($company);
            }
            $from = null;
            $to = null;
            $month = null;
            if ($office === 'all' && $company === 'all') {
                $dateTime = Carbon::now($tz);
                request()->get('year') ?? $dateTime->year;
                $month = $dateTime->month;
                $from = Carbon::create($year, $month, 1)->startOfDay();
                $to = $from->copy()->addYear()->subDay()->endOfDay();
            } elseif ($office == 'all' && $company !== 'all') {
                $configuration = $company ? $company->configurations->where('name', 'Financial Year')->first() : null;
                $configurationValue = $configuration ? json_decode($configuration->pivot->configuration_value) : null;
                $month = $configurationValue ? $configurationValue->fiscal_year_start : 1;
                $from = Carbon::create($year, $month, 1)->startOfDay();
                $to = $from->copy()->addYear()->subDay()->endOfDay();

            } elseif ($office !== 'all') {
                $company = $office ? $office->company->load('configurations') : null;
                $configuration = $company ? $company->configurations->where('name', 'Financial Year')->first() : null;
                $configurationValue = $configuration ? json_decode($configuration->pivot->configuration_value) : null;
                $month = $configurationValue ? $configurationValue->fiscal_year_start : 1;
                $from = Carbon::create($year, $month, 1)->startOfDay();
                $to = $from->copy()->addYear()->subDay()->endOfDay();
            }

            $currentWeek = $from->copy()->startOfYear();
            return view('mis.year', compact(
                'staff', 'view', 'office', 'year', 'to', 'from', 'currentWeek',
                'isProcessing', 'companies', 'offices', 'company'
            ));
        }

        extract($report);
        if (request()->ajax()) {
            $staffs = $report['staffs'];
            if (is_array($staffs)) {
                $staffs = array_values($staffs);
            }
            return response()->json($staffs);
        }


        return view('mis.year', compact(
            'staffs', 'staff', 'view', 'office', 'year', 'to', 'from', 'currentWeek',
            'isProcessing', 'timestamp', 'companies', 'offices', 'company'
        ));
    }

    private function getWeekDetails($auth, $office)
    {
        $view = 'week';
        $request = request();

        $staff = $auth->staff()->first();
        $tz = $this->staffTimezone($staff);
        $timestamp = Carbon::now()->timezone($tz)->format('F  jS,   Y,   g:i a');
        $company = $this->company;
        $year = null;
        $week = null;
        if (!$request->get('year') && !$request->get('week')) {
            $dateTime = Carbon::now($tz);
            $week = $dateTime->weekOfYear;
            $year = $week == 1 ? $dateTime->endOfWeek()->year : $dateTime->startOfWeek()->year;
        } elseif ($request->get('year') && $request->get('week')) {
            $year = \request()->get('year');
            $week = \request()->get('week');
        }

        $key = "$view.$office.$year.$week.$company";
        if ($request->get('generate')) {
            Storage::put("reports/$key.txt", "Generating");
            ReportRequestEvent::dispatch($auth, $office, [
                'view' => $view,
                'year' => $year,
                'week' => $week,
                'timestamp' => $timestamp,
                'company' => $company
            ]);

            return response()->json([
                'success' => true
            ]);
        }
        $report = cache($key);
        if (!$report && Storage::exists("reports/$key.json")) {
            $report = json_decode(Storage::get("reports/$key.json"), true);
        }

        $companies = $this->companies;
        $offices = $this->offices;

        $isProcessing = Storage::exists("reports/$key.txt");
        if (!$report) {
            if ($office !== 'all') {
                $office = Office::find($office);
            }
            $tz = $this->staffTimezone($staff);
            $isCurrentWeek = (carbon()->tz($tz)->year == $year && carbon()->tz($tz)->weekOfYear == $week)
                ? carbon()->now($tz)
                : false;
            $isLastWeek = (carbon()->now($tz)->subWeek()->year == $year && carbon()->now($tz)->subWeek()->weekOfYear == $week)
                ? carbon()->now($tz)->subWeek()
                : false;
            $isNextWeek = (carbon()->now($tz)->addWeek()->year == $year && carbon()->now($tz)->addWeek()->weekOfYear == $week)
                ? carbon()->now($tz)->addWeek()
                : false;

            $currentWeek = carbon()->tz($tz)->setISODate($year, $week);
            $prevWeek = carbon()->tz($tz)->setISODate($year, $week)->subWeek();
            $nextWeek = carbon()->tz($tz)->setISODate($year, $week)->addWeek();


            return view('mis.week', compact('staff', 'view', 'office', 'week', 'year',
                'currentWeek', 'isCurrentWeek', 'isLastWeek', 'isNextWeek', 'prevWeek', 'nextWeek',
                'isProcessing', 'companies', 'offices', 'company'
            ));
        }

        extract($report);
        if (request()->ajax()) {
            $staffs = $report['staffs'];
            if (is_array($staffs)) {
                $staffs = array_values($staffs);
            }
            return response()->json($staffs);
        }

        return view('mis.week', compact(
            'staffs', 'staff', 'view', 'office', 'week', 'year',
            'currentWeek', 'isCurrentWeek', 'isLastWeek', 'isNextWeek', 'prevWeek', 'nextWeek',
            'isProcessing', 'timestamp', 'companies', 'offices', 'company'
        ));
    }

    private function getCustomDetail($auth, $office)
    {
        $view = 'custom';
        $request = request();

        $staff = $auth->staff()->first();
        $tz = $this->staffTimezone($staff);
        $timestamp = Carbon::now()->timezone($tz)->format('F  jS,   Y,   g:i a');
        $company = $this->company;
        $from = $request->get('from');
        $to = $request->get('to');
        $year = Carbon::parse($from)->year;

        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->endOfDay();

        $maxDate = Carbon::now();
        if($toDate > $maxDate) {
            alert()->warning('The to date must be a day before ' . $maxDate->toDateString())->autoclose(2000);
            return redirect()->back();
        }

        if ($toDate < $fromDate) {
            alert()->warning('The end date must be a day after ' . $from)->autoclose(2000);
            return redirect()->back();
        }

        if($fromDate->diffInYears($toDate)) {
            alert()->warning('Date range must be less than 1 year')->autoclose(2000);
            return redirect()->back();
        }

        $start = strtotime($fromDate);
        $end = strtotime($toDate);
        $key = "$view.$office.$start.$end.$company";

        if ($request->get('generate')) {
            Storage::put("reports/$key.txt", "Generating");

            ReportRequestEvent::dispatch($auth, $office, [
                'view' => $view,
                'from' => $from,
                'to' => $to,
                'timestamp' => $timestamp,
                'company' => $company
            ]);

            return response()->json([
                'success' => true
            ]);
        }
        $report = cache($key);
        if (!$report && Storage::exists("reports/$key.json")) {
            $report = json_decode(Storage::get("reports/$key.json"), true);
        }

        $companies = $this->companies;
        $offices = $this->offices;

        $isProcessing = Storage::exists("reports/$key.txt");
        if (!$report) {
            if ($office !== 'all') {
                $office = Office::find($office);
            }
            $fromDate = Carbon::parse($from)->startOfDay();
            $toDate = Carbon::parse($to)->endOfDay();
            $currentWeek = $toDate->copy()->startOfWeek();
            return view('mis.custom', compact('staff', 'view', 'office', 'fromDate', 'toDate',
                'isProcessing', 'companies', 'offices', 'company', 'year', 'currentWeek'
            ));
        }

        extract($report);
        if (request()->ajax()) {
            $staffs = $report['staffs'];
            if (is_array($staffs)) {
                $staffs = array_values($staffs);
            }
            return response()->json($staffs);
        }

        return view('mis.custom', compact(
            'staffs', 'timestamp', 'staff', 'view', 'office', 'fromDate', 'toDate',
            'isProcessing', 'companies', 'offices', 'company', 'year', 'currentWeek'
        ));
    }

    public function getPieChart(Staff $staff)
    {

        $tz = $this->staffTimezone($staff);

        $view = request()->get('view');
        $year = request()->get('year');
        $periodNo = request()->get('no') or null;
        if ($view == 'week') {
            $isCurrentWeek = (carbon()->tz($tz)->year == $year && carbon()->tz($tz)->weekOfYear == $periodNo)
                ? carbon()->now($tz)
                : false;
            $isLastWeek = (carbon()->now($tz)->subWeek()->year == $year && carbon()->now($tz)->subWeek()->weekOfYear == $periodNo)
                ? carbon()->now($tz)->subWeek()
                : false;
            $isNextWeek = (carbon()->now($tz)->addWeek()->year == $year && carbon()->now($tz)->addWeek()->weekOfYear == $periodNo)
                ? carbon()->now($tz)->addWeek()
                : false;


            $currentWeek = carbon()->tz($tz)->setISODate($year, $periodNo);
            $prevWeek = carbon()->tz($tz)->setISODate($year, $periodNo)->subWeek();
            $nextWeek = carbon()->tz($tz)->setISODate($year, $periodNo)->addWeek();
            return view('mis.pie-chart', compact(
                'staff', 'view', 'year', 'periodNo', 'currentWeek',
                'isCurrentWeek', 'isLastWeek', 'isNextWeek', 'currentMonth', 'prevWeek', 'nextWeek'
            ));
        } elseif ($view == 'quarter') {
            if ($periodNo < 1) $periodNo = 1;
            if ($periodNo > 4) $periodNo = 4;

            $month = $periodNo * 3;
            $from = Carbon::create($year, $month, 1)->firstOfQuarter();
            $to = $from->copy()->lastOfQuarter()->endOfDay();
            $currentWeek = $to->copy()->startOfWeek();
            return view('mis.pie-chart', compact('staff', 'view', 'periodNo', 'year', 'to', 'from', 'currentWeek'));

        } elseif ($view == 'fy') {
            $staffOffice = $staff->defaultOffice()->first();
            if (!$staffOffice) return null;
            $company = $staffOffice ? $staffOffice->company->load('configurations') : null;
            $configuration = $company ? $company->configurations->where('name', 'Financial Year')->first() : null;
            $configurationValue = $configuration ? json_decode($configuration->pivot->configuration_value) : null;
            $month = $configurationValue ? $configurationValue->fiscal_year_start : 1;

            $from = Carbon::create($year, $month, 1)->startOfDay();
            $to = $from->copy()->addYear()->subDay()->endOfDay();
            $currentWeek = $to->copy()->startOfWeek();

            return view('mis.pie-chart', compact('staff', 'view', 'year', 'to', 'from', 'currentWeek', 'periodNo'));
        } elseif ($view == 'month') {
            $isCurrentMonth = (carbon()->tz($tz)->year == $year && carbon()->tz($tz)->month == $periodNo)
                ? carbon()->now($tz) : false;
            $isLastMonth = (carbon()->now($tz)->subMonth()->year == $year && carbon()->now($tz)->subMonth()->month == $periodNo)
                ? carbon()->now($tz)->subMonth() : false;
            $isNextMonth = (carbon()->now($tz)->addMonth()->year == $year && carbon()->now($tz)->addMonth()->month == $periodNo)
                ? carbon()->now($tz)->addMonth() : false;
            $currentMonth = carbon()->tz($tz)->setDate($year, $periodNo, 1);
            $prevMonth = carbon()->tz($tz)->setDate($year, $periodNo, 1)->subMonth();
            $nextMonth = carbon()->tz($tz)->setDate($year, $periodNo, 1)->addMonth();

            $from = $currentMonth->copy()->startOfMonth();
            $monthFirstWeek = $from->weekOfYear;
            $currentWeek = carbon()->setISODate($year, $monthFirstWeek);
            return view('mis.pie-chart', compact(
                'staff', 'view', 'year', 'periodNo', 'currentWeek',
                'isCurrentMonth', 'isLastMonth', 'isNextMonth', 'currentMonth', 'prevMonth', 'nextMonth'
            ));
        } elseif ($view == 'custom') {
            $from = request()->get('from');
            $to = request()->get('to');
            $year = Carbon::parse($from)->year;
            return view('mis.pie-chart', compact(
                'staff', 'view', 'year', 'from', 'to'
            ));
        }

    }

    public function drawPieChart(Staff $staff)
    {
        if (!$staff) {
            $staffID = request()->route('staff');
            $staff = Staff::find($staffID);
        }

        $tz = $this->staffTimezone($staff);
        $view = request()->get('view');
        $year = request()->get('year');
        $periodNo = request()->get('no');
        $from = null;
        $to = null;
        if ($view == 'week') {
            $currentWeek = carbon()->tz($tz)->setISODate($year, $periodNo);
            $from = $currentWeek->copy()->startOfWeek();
            $to = $currentWeek->copy()->endOfWeek();
        }
        if ($view == 'month') {
            $currentMonth = carbon()->tz($tz)->setDate($year, $periodNo, 1);
            $from = $currentMonth->copy()->startOfMonth();
            $to = $currentMonth->copy()->endOfMonth();
        }
        if ($view == 'quarter') {
            if ($periodNo < 1) $periodNo = 1;
            if ($periodNo > 4) $periodNo = 4;

            $month = $periodNo * 3;
            $from = Carbon::create($year, $month, 1)->firstOfQuarter();
            $to = $from->copy()->lastOfQuarter()->endOfDay();
        }
        if ($view == 'fy') {
            $staffOffice = $staff->defaultOffice()->first();
            if (!$staffOffice) return null;
            $company = $staffOffice ? $staffOffice->company->load('configurations') : null;
            $configuration = $company ? $company->configurations->where('name', 'Financial Year')->first() : null;
            $configurationValue = $configuration ? json_decode($configuration->pivot->configuration_value) : null;
            $month = $configurationValue ? $configurationValue->fiscal_year_start : 1;

            $from = Carbon::create($year, $month, 1)->startOfDay();
            $to = $from->copy()->addYear()->subDay()->endOfDay();
        }

        if($view == 'custom') {
            $fromDate = request()->get('from');
            $toDate = request()->get('to');
            $from = carbon()->parse($fromDate)->startOfDay();
            $to = carbon()->parse($toDate)->endOfDay();

        }

        $works = $staff->worklogs()->where(function ($q) use ($from, $to) {
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

        })->get();
        $billableIssue = $works->whereNotIn('issue.project_id', [1])->where('issue.project.funding_source_id', 2)->sum('worked');
        $billableTask = $works->whereIn('issue.project_id', [1])->where('issue.task.relatedProject.funding_source_id', 2)->sum('worked');
        $billable = $billableIssue + $billableTask;

        $total = $works->sum('worked');

        $internalIssue = $works->whereNotIn('issue.project_id', [1])->where('issue.project.funding_source_id', 1)->sum('worked');
        $internalTask = $works->whereIn('issue.project_id', [1])->where('issue.task.relatedProject.funding_source_id', 1)->sum('worked');
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
        $project = $workedProjects ? Project::where('id', $workedProjects->project_id)->pluck('name')->first() : Null;

        $staff->worklogs = $works->count();
        $defaultOffice = $staff->defaultOffice->first();
        $timezone = $defaultOffice ? $defaultOffice->timezone : null;
        $interval = new DateInterval('P1D');
        $dateRange = new DatePeriod(carbon()->parse($from), $interval, carbon()->parse($to));
        $allPublicHolidays = $timezone ? $this->publicHolidays($timezone, carbon()->parse($from)->toDateTimeString(), carbon()->parse($to)->toDateTimeString(), $staff, $dateRange) : [];
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
        $location = $staff->defaultOffice()->first()->name ?? 'none';
        $customer_bill = changeHoursFormat($billable);
        $internal_project = changeHoursFormat($internal);
        $sale_support = changeHoursFormat($sale);
        $non_billable = changeHoursFormat($nonBillable);
        $totalLeave = count($holidayTotalByType) ? array_sum($holidayTotalByType) > 0 ? array_sum($holidayTotalByType) : 0 : 0;
        $fiTotal = $total + $totalLeave;
        $totalleave = timesheetHoursFormat($totalLeave);
        $total = changeHoursFormat($fiTotal);
        $grandTotal = str_replace(',', '', $total);

        $billablePercentage = ($customer_bill && $grandTotal) ? number_format(((str_replace(',', '', $customer_bill) / $grandTotal) * 100), 2) : 0;
        $interProjectPercentage = ($internal_project && $grandTotal) ? number_format(((str_replace(',', '', $internal_project) / $grandTotal) * 100), 2) : 0;
        $saleSupportPercentage = ($sale_support && $grandTotal) ? number_format(((str_replace(',', '', $sale_support) / $grandTotal) * 100), 2) : 0;
        $nonBillablePercentage = ($non_billable && $grandTotal) ? number_format(((str_replace(',', '', $non_billable) / $grandTotal) * 100), 2) : 0;
        $leavePercentage = ($totalleave && $grandTotal) ? number_format(((str_replace(',', '', $totalleave) / $grandTotal) * 100), 2) : 0;


        return [
            'location' => $location,
            'name' => $staff->first_name . ' ' . $staff->last_name,
            'short_name' => $staff->short_name,
            'customer_bill' => $customer_bill,
            'internal_project' => $internal_project,
            'sale_support' => $sale_support,
            'non_billable' => $non_billable,
            'totalleave' => $totalleave,
            'total' => $total,
            'billablePercentage' => $billablePercentage,
            'interProjectPercentage' => $interProjectPercentage,
            'saleSupportPercentage' => $saleSupportPercentage,
            'nonBillablePercentage' => $nonBillablePercentage,
            'leavePercentage' => $leavePercentage,
            'major_project_involved' => $project ?? 'None'
        ];
    }

    public function drawTrentChart(Staff $staff)
    {

        $tz = $this->staffTimezone($staff);
        $view = request()->get('view');
        $year = request()->get('year');
        $periodNo = request()->get('no');
        $from = null;
        $to = null;
        $range = null;
        if ($view == 'week') {
            $currentWeek = carbon()->tz($tz)->setISODate($year, $periodNo);
            $from = $currentWeek->copy()->startOfWeek();
            $to = $currentWeek->copy()->endOfWeek();
            $interval = \DateInterval::createFromDateString('1 day');
            $range = new \DatePeriod($from, $interval, $to);

        } elseif ($view == 'month') {
            $currentMonth = carbon()->tz($tz)->setDate($year, $periodNo, 1);
            $from = $currentMonth->copy()->startOfMonth();
            $to = $currentMonth->copy()->endOfMonth();
            $interval = \DateInterval::createFromDateString('1 week');
            $range = new \DatePeriod($from, $interval, $to);
        } elseif ($view == 'quarter') {
            if ($periodNo < 1) $periodNo = 1;
            if ($periodNo > 4) $periodNo = 4;

            $month = $periodNo * 3;
            $currentMonth = carbon()->tz($tz)->setDate($year, $month, 1);
            $now = $currentMonth->copy()->startOfMonth();
            $from = $now->copy()->subMonth(2)->startOfMonth();
            $to = $currentMonth->copy()->endOfMonth();
            $interval = \DateInterval::createFromDateString('1 month');
            $range = new \DatePeriod($from, $interval, $to);
        } elseif ($view == 'fy') {
            $staffOffice = $staff->defaultOffice()->first();
            if (!$staffOffice) return null;
            $company = $staffOffice ? $staffOffice->company->load('configurations') : null;
            $configuration = $company ? $company->configurations->where('name', 'Financial Year')->first() : null;
            $configurationValue = $configuration ? json_decode($configuration->pivot->configuration_value) : null;
            $month = $configurationValue ? $configurationValue->fiscal_year_start : 1;

            $from = Carbon::create($year, $month, 1)->startOfDay();
            $to = $from->copy()->addYear()->subDay()->endOfDay();
            $interval = \DateInterval::createFromDateString('3 month');
            $range = new \DatePeriod($from, $interval, $to);
        }

        $chartMonthRange = [];
        $m = null;
        $array1 = [];
        $array2 = [];
        $array3 = [];
        $array4 = [];
        $array5 = [];
        foreach ($range as $period) {
            /* convert to date string*/
            $startDate = null;
            $endDate = null;
            if ($view == 'week') {
                $m = $period->copy()->format('D');
                array_push($chartMonthRange, $m);
                $staff->setAttribute('month', $m);
                $startDate = $period->copy()->startOfDay();
                $endDate = $period->copy()->endOfDay();

            } elseif ($view == 'month') {
                $m = $period->copy()->weekOfMonth;
                if ($m == 1) $m = "1st Week";
                if ($m == 2) $m = "2nd Week";
                if ($m == 3) $m = "3rd Week";
                if ($m == 4) $m = "4th Week";
                if ($m == 5) $m = "5th Week";
                array_push($chartMonthRange, $m);
                $staff->setAttribute('month', $m);
                if ($m == 1) {
                    $startDate = $period->copy()->firstOfMonth();
                } else {
                    $startDate = $period->copy()->startOfWeek();
                }
                $endDate = $period->copy()->endOfWeek();
//                if ($m == 5) {
//                    $endDate = $period->copy()->lastOfMonth();
//                } else {
//
//                }
            } elseif ($view == 'quarter') {
                $m = $period->copy()->format('F');
                array_push($chartMonthRange, $m);
                $staff->setAttribute('month', $m);
                $startDate = $period->copy()->startOfMonth();
                $endDate = $period->copy()->endOfMonth();
            } elseif ($view == 'fy') {
                $m = $period->copy()->format('F');
                array_push($chartMonthRange, $m);
                $staff->setAttribute('month', $m);
                $startDate = $period->copy()->startOfQuarter();
                $endDate = $period->copy()->endOfQuarter();
            }
//            $works = $staff->worklogs()->where(function ($q) use ($startDate, $endDate) {
//                $q->where('logged_for', '>=', carbon()->parse($startDate))->where('logged_for', '<=', carbon()->parse($endDate));
//            })->get();
            $works = $staff->worklogs()->where(function ($q) use ($startDate, $endDate) {
                $q->where('logged_for', '>=', carbon()->parse($startDate))
                    ->where('logged_for', '<=', carbon()->parse($endDate))
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

            })->get();
            $billableIssue = $works->whereNotIn('issue.project_id', [1])->where('issue.project.funding_source_id', 2)->sum('worked');
            $billableTask = $works->whereIn('issue.project_id', [1])->where('issue.task.relatedProject.funding_source_id', 2)->sum('worked');
            $billable = $billableIssue + $billableTask;

            $total = $works->sum('worked');

            $internalIssue = $works->whereNotIn('issue.project_id', [1])->where('issue.project.funding_source_id', 1)->sum('worked');
            $internalTask = $works->whereIn('issue.project_id', [1])->where('issue.task.relatedProject.funding_source_id', 1)->sum('worked');
            $internal = $internalIssue + $internalTask;

            $sale = $works->where('issue->issue_type_id', 19)->sum('worked');

            $nonBillableIssue = $works->whereNotIn('issue.project_id', [1])->whereNotIn('issue.project.funding_source_id', [1, 2])->sum('worked');
            $nonBillableTask = $works->whereIn('issue.project_id', [1])->where('issue.task.project_id', null)->sum('worked');
            $nonBillablePro = $works->whereIn('issue.project_id', [1])->whereNotIn('issue.task.relatedProject.funding_source_id', [1, 2])->whereNotIn('issue.task.project_id', [null])->sum('worked');
            $nonBillable = $nonBillableIssue + $nonBillableTask + $nonBillablePro;


            $staff->worklogs = $works->count();

            $defaultOffice = $staff->defaultOffice()->first();
            $timezone = $defaultOffice ? $defaultOffice->timezone : null;
            $interval = new DateInterval('P1D');
            $dateRange = new DatePeriod($startDate, $interval, $endDate);
            $allPublicHolidays = $timezone ? $this->publicHolidays($timezone, $startDate->toDateTimeString(), $endDate->toDateTimeString(), $staff, $dateRange) : [];
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


            $customer_bill = timesheetHoursFormat($billable);
            $internal_project = timesheetHoursFormat($internal);
            $sale_support = timesheetHoursFormat($sale);
            $non_billable = timesheetHoursFormat($nonBillable);


            $totalLeave = count($holidayTotalByType) ? array_sum($holidayTotalByType) > 0 ? array_sum($holidayTotalByType) : 0 : 0;
            $fiTotal = $total + $totalLeave;
            $totalleave = timesheetHoursFormat($totalLeave);
            $total = changeHoursFormat($fiTotal);
            $grandTotal = str_replace(',', '', $total);

            $billablePercentage = ($customer_bill && $grandTotal) ? number_format(((str_replace(',', '', $customer_bill) / $grandTotal) * 100), 2) : 0;
            $interProjectPercentage = ($internal_project && $grandTotal) ? number_format(((str_replace(',', '', $internal_project) / $grandTotal) * 100), 2) : 0;
            $saleSupportPercentage = ($sale_support && $grandTotal) ? number_format(((str_replace(',', '', $sale_support) / $grandTotal) * 100), 2) : 0;
            $nonBillablePercentage = ($non_billable && $grandTotal) ? number_format(((str_replace(',', '', $non_billable) / $grandTotal) * 100), 2) : 0;
            $leavePercentage = ($totalleave && $grandTotal) ? number_format(((str_replace(',', '', $totalleave) / $grandTotal) * 100), 2) : 0;

            array_push($array1, $billablePercentage);
            array_push($array2, $interProjectPercentage);
            array_push($array3, $saleSupportPercentage);
            array_push($array4, $nonBillablePercentage);
            array_push($array5, $leavePercentage);

        }

        return [
            'array1' => $array1,
            'array2' => $array2,
            'array3' => $array3,
            'array4' => $array4,
            'array5' => $array5,
            'chartMonthRange' => $chartMonthRange
        ];
    }

    public function exportPdf()
    {
        $office = 'all';
        $officeId = request()->get('office');

        if (!$officeId) {
            $office = session()->get('chosen_office');
        } elseif ($officeId !== 'all') {
            $office = Office::find($officeId);
        }

        $company = 'all';
        $companyId = request()->get('company');

        if (!$companyId) {
            $company = session()->get('chosen_company');
        } elseif ($companyId !== 'all') {
            $company = Company::find($companyId);
        }

        if ($office instanceof Office) {
            $office = $office->id;
        }

        if ($company instanceof Company) {
            $company = $company->id;
        }
        $this->company = $company;
        $this->office = $office;
        $view = request()->get('view');
        $auth = auth()->user();
        $collection = null;


        if (!$office) {
            return redirect()->route('dashboard.index');
        }

        if ($view == 'month') {
            $collection = $this->getMonthDetails($auth, $office);
        } else if ($view === 'quarter') {
            $collection = $this->getQuarterDetail($auth, $office);
        } else if ($view == 'fy') {
            $collection = $this->getYearDetail($auth, $office);
        } else if ($view == 'week') {
            $collection = $this->getWeekDetails($auth, $office);
        } else if ($view == 'custom') {
            $collection = $this->getCustomDetail($auth, $office);
        }

        if ($office !== 'all') {
            $office = Office::find($office);
        }

        if ($company !== 'all') {
            $company = Company::find($company);
        }

        $this->office = $office;
        $staffs = $collection->getData()['staffs'];
        $periodNo = null;
        if ($view == 'month') {
            $periodNo = $collection->getData()['month'];
        } else if ($view === 'quarter') {
            $periodNo = $collection->getData()['quarter'];
        } else if ($view == 'fy') {
            $periodNo = null;
        } else if ($view == 'week') {
            $periodNo = $collection->getData()['week'];
        } else if ($view == 'custom') {
            $from = request()->get('from');
            $to = request()->get('to');
            $periodNo = $from . '-'. $to;
        }
        $html = view('mis.export.pdf', compact('staffs', 'office'))->render();
        $pdf = new Dompdf();
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        $pdf->stream('MIS-REPORT' . '-' . strtoupper($office['name'] ?? 'All') . '-' . strtoupper($view) . '-' . $periodNo . '-' . $collection->getData()['year'] . '-' . strtoupper($company['name'] ?? 'All'));
    }

    public function exportExcel(\Illuminate\Http\Request $request)
    {
        $office = 'all';
        $officeId = request()->get('office');

        if (!$officeId) {
            $office = session()->get('chosen_office');
        } elseif ($officeId !== 'all') {
            $office = Office::find($officeId);
        }

        $company = 'all';
        $companyId = request()->get('company');

        if (!$companyId) {
            $company = session()->get('chosen_company');
        } elseif ($companyId !== 'all') {
            $company = Company::find($companyId);
        }
        if ($office instanceof Office) {
            $office = $office->id;
        }

        if ($company instanceof Company) {
            $company = $company->id;
        }

        $this->company = $company;
        $this->office = $office;


        $view = request()->get('view');
        $auth = auth()->user();


        if (!$office) {
            return redirect()->route('dashboard.index');
        }

        $collection = null;
        if ($view == 'month') {
            $collection = $this->getMonthDetails($auth, $office);
        } else if ($view === 'quarter') {
            $collection = $this->getQuarterDetail($auth, $office);
        } else if ($view == 'fy') {
            $collection = $this->getYearDetail($auth, $office);
        } else if ($view == 'week') {
            $collection = $this->getWeekDetails($auth, $office);
        } else if ($view == 'custom') {
            $collection = $this->getCustomDetail($auth, $office);
        }
        $staffs = collect($collection->getData()['staffs']);

        if ($office !== 'all') {
            $office = Office::find($office);
        }

        if ($company !== 'all') {
            $company = Company::find($company);
        }

        $periodNo = null;
        if ($view == 'month') {
            $periodNo = $collection->getData()['month'];
        } else if ($view === 'quarter') {
            $periodNo = $collection->getData()['quarter'];
        } else if ($view == 'fy') {
            $periodNo = null;
        } else if ($view == 'week') {
            $periodNo = $collection->getData()['week'];
        } else if ($view == 'custom') {
            $from = request()->get('from');
            $to = request()->get('to');
            $periodNo = $from . '-'. $to;
        }


        $staffs->transform(function ($staff) {

            return [
                'Location' => $staff['location'],
                'Name' => $staff['name'],
                'Major Project Involved' => $staff['major_project_involved'] ?? 'None',
                'Customer Billable (H)' => $staff['customer_bill'] ? (float)str_replace(',', '', $staff['customer_bill']) : 0.0,
                'Sales Support (H)' => $staff['sale_support'] ? (float)str_replace(',', '', $staff['sale_support']) : 0.0,
                'Internal Project (H)' => $staff['internal_project'] ? (float)str_replace(',', '', $staff['internal_project']) : 0.0,
                'Non-Billable (H)' => $staff['non_billable'] ? (float)str_replace(',', '', $staff['non_billable']) : 0.0,
                'Leave (H)' => $staff['totalLeave'] ? (float)str_replace(',', '', $staff['totalLeave']) : 0.0,
                'Total (H)' => $staff['total'] ? (float)str_replace(',', '', $staff['total']) : 0.0,
                'Customer Billable (%)' => $staff['billablePercentage'] ? (float)($staff['billablePercentage'] / 100) : 0.0,
                'Sales Support (%)' => $staff['saleSupportPercentage'] ? (float)($staff['saleSupportPercentage'] / 100) : 0.0,
                'Internal Project (%)' => $staff['interProjectPercentage'] ? (float)($staff['interProjectPercentage'] / 100) : 0.0,
                'Non-Billable (%)' => $staff['nonBillablePercentage'] ? (float)($staff['nonBillablePercentage'] / 100) : 0.0,
                'Leave (%)' => $staff['leavePercentage'] ? (float)($staff['leavePercentage'] / 100) : 0.0,
            ];
        });

        $name = 'MIS-REPORT' . '-' . strtoupper($office['name'] ?? 'All') . '-' . strtoupper($view) . '-' . $periodNo . '-' . $collection->getData()['year'] . '-' . strtoupper($company['name'] ?? 'All');
        $type = $request->get('type') == 'csv' ? 'csv' : 'xlsx';
        Excel::create($name, function ($excel) use ($name, $staffs) {
            $excel->sheet('Resource utilisation', function ($sheet) use ($staffs) {
                $sheet->getStyle('A1:N1')->applyFromArray(array(
                    'font' => [
//                        'name' => 'Verdana',
//                        'size' => 12,
                        'bold' => true
                    ]
                ));

                $sheet->setStyle([
                    'borders' => [
                        'allborders' => [
                            'color' => [
                                'rgb' => 'f1f1f1'
                            ]
                        ]
                    ]
                ]);

                $sheet->setAutoFilter();
                $dataCount = $staffs->count() + 1;

                $sheet->cells('A1:C1', function ($cells) {
                    $cells->setBackground('#dcedef');
                });
                $sheet->cells('A2:C' . $dataCount, function ($cells) {
                    $cells->setBackground('#d1e9ed');
                });

                $sheet->cells('D1:I1', function ($cells) {
                    $cells->setBackground('#e8efe8');
                });
                $sheet->cells('D2:I' . $dataCount, function ($cells) {
                    $cells->setBackground('#d8e5d8');
                });

                $sheet->cells('J1:N1', function ($cells) {
                    $cells->setBackground('#f4f3ed');
                });
                $sheet->cells('J2:N' . $dataCount, function ($cells) {
                    $cells->setBackground('#edebdf');
                });

                $sheet->setBorder('A1:N' . $dataCount, 'thin');

                $sheet->getStyle('D2:N200')->applyFromArray(array(
                    'alignment' => [
                        'horizontal' => 'right',
                    ],
                ));
                $sheet->setColumnFormat(array(
                    'D2:D' . $dataCount => '0.00',
                    'E2:E' . $dataCount => '0.00',
                    'F2:F' . $dataCount => '0.00',
                    'G2:G' . $dataCount => '0.00',
                    'H2:H' . $dataCount => '0.00',
                    'I2:I' . $dataCount => '0.00',
                    'J2:J' . $dataCount => '0.00%',
                    'K2:K' . $dataCount => '0.00%',
                    'L2:L' . $dataCount => '0.00%',
                    'M2:M' . $dataCount => '0.00%',
                    'N2:N' . $dataCount => '0.00%',
                ));
                $sheet->fromArray(
                    $staffs->toArray(),
                    null,
                    'A1',
                    true
                );

            });
        })->download($type);
    }

    public function viewIndividualShare()
    {
        return view('mis.share-individual');
    }

    public function shareIndividual(MISSharedRequest $request)
    {
        $view = $request->get('view');
        $year = $request->get('year');
        $companyReq = $request->get('company');
        $officeReq = $request->get('office');
        $period = $request->get('week') ?? $request->get('month') ?? $request->get('quarter');
        $text = null;
        if($view == 'month') {
            $text = 'month';
        }elseif($view == 'week'){
            $text = 'week';
        }elseif($view == 'quarter'){
            $text = 'quarter';
        }else{
            $text = null;
        }

        $url = null;
        if ($view !== 'custom'){
            $url = route('mis.report.index', ['office' => $officeReq, 'view' => $view, 'year' => $year, $text => $text ? $period : null, 'company' => $companyReq]);
        } elseif ($view == 'custom'){
            $url = route('mis.report.index', ['office' => $officeReq, 'view' => $view, 'from' => $request->get('from'), 'to' => $request->get('to'), 'company' => $companyReq]);
        }

        $userID = $request->input('user_id');
        $users = explode(',', $userID);
        $owner = collect();
        $office = session()->get('chosen_office');
        $auth = auth()->user();

        if (!is_string($office) && isset($office->id)) {
            $office = $office->id;
        }

        if (!$office) {
            return redirect()->route('dashboard.index');
        }

        foreach ($users as $user) {
            $data = User::find($user);
            $owner->push($data);
        }

        $user = auth()->user();
        event(new MISShared($office, $user, $owner, $url));

        alert()->success('Success', 'Shared Successfully');

        if ($view !== 'custom'){
            return redirect()->route('mis.report.index', ['office' => $officeReq, 'view' => $view, 'year' => $year, $text => $text ? $period : null, 'company' => $companyReq]);
        } elseif ($view == 'custom'){
            return redirect()->route('mis.report.index', ['office' => $officeReq, 'view' => $view, 'from' => $request->get('from'), 'to' => $request->get('to'), 'company' => $companyReq]);
        }

    }

    private function staffTimezone($staff)
    {
        if (!$staff) return config('app.timezone');
        $office = $staff->offices()->wherePivot('is_default', 'Yes')->first();
        $tz = $office->timezone ?? config('app.timezone');
        return $tz;
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
                            if ($leaveList->leave_type == 'short'
                                || $leaveList->number_of_days == 0.5
                                || ($leaveList->half_day_on == 'first_day' && $leaveList->from_date->copy()->toDateString() == $date->copy()->toDateString())
                                || ($leaveList->half_day_on == 'last_day' && $leaveList->to_date->copy()->toDateString() == $date->copy()->toDateString())) {
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
                        if ($leaveList->leave_type == 'short'
                            || $leaveList->number_of_days == 0.5
                            || ($leaveList->half_day_on == 'first_day' && $leaveList->from_date->copy()->toDateString() == $date->copy()->toDateString())
                            || ($leaveList->half_day_on == 'last_day' && $leaveList->to_date->copy()->toDateString() == $date->copy()->toDateString())) {
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

    public function OfficeSearch($q = null, Company $company)
    {
        $officeIds = Office::where('company_id', $company->id)->get()->pluck('id');
        if ($q == null) {
            $office = Office::whereIn('id', $officeIds)->orderBy('name')->get(['id', 'name'])->toArray();
        } else {
            $office = Office::whereIn('id', $officeIds)->where(function ($qw) use ($q) {
                $qw->where('name', 'LIKE', '%' . $q . '%')->orWhere('name', 'LIKE', '%' . $q . '%')->orderBy('name');
            })->get(['id', 'name'])->toArray();
        }
        $office = array_map(function ($obj) {
            return ["name" => $obj['name'], "value" => $obj['id']];
        }, $office);

        if (!$q) {
            $office = array_prepend($office, [
                "name" => "All",
                "value" => 'all'
            ]);
        }
        return response()->json(["success" => true, "results" => $office]);
    }

    public function companySearch($q = null)
    {
        $companyIds = Company::all()->pluck('id');
        if ($q == null) {
            $company = Company::whereIn('id', $companyIds)->orderBy('name')->get(['id', 'name'])->toArray();
        } else {
            $company = Company::whereIn('id', $companyIds)->where(function ($qw) use ($q) {
                $qw->where('name', 'LIKE', '%' . $q . '%')->orWhere('name', 'LIKE', '%' . $q . '%')->orderBy('name');
            })->get(['id', 'name'])->toArray();
        }
        $company = array_map(function ($obj) {
            return ["name" => $obj['name'], "value" => $obj['id']];
        }, $company);

        if (!$q) {
            $company = array_prepend($company, [
                "name" => "All",
                "value" => 'all'
            ]);
        }
        return response()->json(["success" => true, "results" => $company]);
    }

    public function staffSearch($q = null)
    {
        $staffIds = Staff::all()->pluck('id');

        if ($q == null) {
            $staffs = Staff::whereIn('id', $staffIds)->where('is_active', 'Yes')->orderBy('short_name')->get(['id', 'short_name'])->toArray();
        } else {
            $staffs = Staff::whereIn('id', $staffIds)->where('is_active', 'Yes')->where(function ($qw) use ($q) {
                $qw->where('short_name', 'LIKE', '%' . $q . '%')->orWhere('first_name', 'LIKE', '%' . $q . '%')->orderBy('short_name');
            })->get(['id', 'short_name'])->toArray();
        }
        $staffs = array_map(function ($obj) {
            return ["name" => $obj['short_name'], "value" => $obj['id']];
        }, $staffs);

        return response()->json(["success" => true, "results" => $staffs]);
    }
}