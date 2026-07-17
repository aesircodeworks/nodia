<?php

namespace App\Http\Controllers;

use App\Enums\CheckResult;
use App\Http\Data\HealthChecksData;
use App\Http\Data\HealthDegradedProblemData;
use App\Http\Data\HealthReportData;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

class HealthController extends Controller
{
    private const TIMEOUT_SECONDS = 2;

    public function __invoke(): HealthReportData|JsonResponse
    {
        $checks = new HealthChecksData(
            $this->checkDatabase(),
            $this->checkRedis(),
            $this->checkStorage(),
        );

        $report = HealthReportData::make($checks, Date::now());

        if ($this->isHealthy($checks)) {
            return $report;
        }

        return HealthDegradedProblemData::make($report->checks, $report->checkedAt)
            ->toProblemResponse();
    }

    private function isHealthy(HealthChecksData $checks): bool
    {
        return $checks->database === CheckResult::Ok
            && $checks->redis === CheckResult::Ok
            && $checks->storage === CheckResult::Ok;
    }

    private function checkDatabase(): CheckResult
    {
        $name = 'health';

        try {
            $config = config('database.connections.'.config('database.default'));
            $config['name'] = $name;
            $config['options'] = ($config['options'] ?? []) + [\PDO::ATTR_TIMEOUT => self::TIMEOUT_SECONDS];

            DB::build($config)->select('select 1');

            return CheckResult::Ok;
        } catch (Throwable) {
            return CheckResult::Failed;
        } finally {
            DB::purge($name);
        }
    }

    private function checkRedis(): CheckResult
    {
        try {
            Redis::connection('health')->ping();

            return CheckResult::Ok;
        } catch (Throwable) {
            return CheckResult::Failed;
        } finally {
            Redis::purge('health');
        }
    }

    private function checkStorage(): CheckResult
    {
        try {
            $config = config('filesystems.disks.'.config('filesystems.default'));

            if (($config['driver'] ?? null) === 's3') {
                $config['http'] = [
                    'connect_timeout' => self::TIMEOUT_SECONDS,
                    'timeout' => self::TIMEOUT_SECONDS,
                ];
            }

            Storage::build($config)->fileExists('.health-probe');

            return CheckResult::Ok;
        } catch (Throwable) {
            return CheckResult::Failed;
        } finally {
            Storage::forgetDisk('ondemand');
        }
    }
}
