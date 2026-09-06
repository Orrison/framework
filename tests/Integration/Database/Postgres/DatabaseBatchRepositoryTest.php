<?php

namespace Illuminate\Tests\Integration\Database\Postgres;

use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery as m;

class DatabaseBatchRepositoryTest extends PostgresTestCase
{
    protected function afterRefreshingDatabase()
    {
        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->text('failed_job_ids');
                $table->text('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }
    }

    protected function destroyDatabaseMigrations()
    {
        Schema::drop('job_batches');
    }

    public function testDecrementPendingJobsReturnsTheDecrementedCount()
    {
        $this->insertBatch('batch', pendingJobs: 3);

        $counts = $this->repository()->decrementPendingJobs('batch', 'job-1');

        $this->assertSame(2, $counts->pendingJobs);
        $this->assertSame(0, $counts->failedJobs);
        $this->assertSame(2, (int) DB::table('job_batches')->where('id', 'batch')->value('pending_jobs'));
    }

    public function testConcurrentDecrementsEachObserveADistinctCount()
    {
        $this->insertBatch('batch', pendingJobs: 3);

        $repository = $this->repository();

        $this->assertSame(2, $repository->decrementPendingJobs('batch', 'job-1')->pendingJobs);
        $this->assertSame(1, $repository->decrementPendingJobs('batch', 'job-2')->pendingJobs);
        $this->assertSame(0, $repository->decrementPendingJobs('batch', 'job-3')->pendingJobs);
    }

    public function testIncrementFailedJobsAppendsTheJobIdOnce()
    {
        $this->insertBatch('batch', pendingJobs: 2);

        $repository = $this->repository();

        $counts = $repository->incrementFailedJobs('batch', 'job-1');

        $this->assertSame(2, $counts->pendingJobs);
        $this->assertSame(1, $counts->failedJobs);

        $counts = $repository->incrementFailedJobs('batch', 'job-1');

        $this->assertSame(2, $counts->failedJobs);
        $this->assertSame(['job-1'], json_decode(DB::table('job_batches')->where('id', 'batch')->value('failed_job_ids'), true));
    }

    public function testDecrementPendingJobsRemovesAPreviouslyFailedJobId()
    {
        $this->insertBatch('batch', pendingJobs: 2);

        $repository = $this->repository();

        $repository->incrementFailedJobs('batch', 'job-1');

        $counts = $repository->decrementPendingJobs('batch', 'job-1');

        $this->assertSame(1, $counts->pendingJobs);
        $this->assertSame(1, $counts->failedJobs);
        $this->assertSame([], json_decode(DB::table('job_batches')->where('id', 'batch')->value('failed_job_ids'), true));
    }

    protected function repository()
    {
        return new DatabaseBatchRepository(
            new BatchFactory(m::mock(Factory::class)), DB::connection(), 'job_batches'
        );
    }

    protected function insertBatch(string $id, int $pendingJobs)
    {
        DB::table('job_batches')->insert([
            'id' => $id,
            'name' => $id,
            'total_jobs' => $pendingJobs,
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => null,
            'created_at' => time(),
            'cancelled_at' => null,
            'finished_at' => null,
        ]);
    }
}
