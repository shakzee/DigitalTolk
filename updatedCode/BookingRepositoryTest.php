<?php

namespace Tests\Unit;

use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Repository\BookingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class BookingRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected $bookingRepository;

    public function setUp(): void
    {
        parent::setUp();
        $this->bookingRepository = app(BookingRepository::class);
    }

    public function testGetUsersJobs()
    {
        $userId = 1; // Replace with your test user ID

        $result = $this->bookingRepository->getUsersJobs($userId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('emergencyJobs', $result);
        $this->assertArrayHasKey('normalJobs', $result);
        $this->assertArrayHasKey('cuser', $result);
        $this->assertArrayHasKey('usertype', $result);
        // ...additional assertions...
    }

    public function testGetUsersJobsHistory()
    {
        $userId = 1; // Replace with your test user ID
        $request = new Request();

        $result = $this->bookingRepository->getUsersJobsHistory($userId, $request);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('emergencyJobs', $result);
        $this->assertArrayHasKey('normalJobs', $result);
        // ...additional assertions...
    }

    public function testStoreJob()
    {
        $user = User::factory()->create(); // Make sure you have a User factory
        $request = new Request([
            // ...Your request data...
        ]);

        $job = $this->bookingRepository->storeJob($request, $user);

        $this->assertInstanceOf(Job::class, $job);
        // ...additional assertions...
    }

    public function testCancelJob()
    {
        $job = Job::factory()->create(); // Make sure you have a Job factory
        $user = User::factory()->create(); // Make sure you have a User factory

        $this->bookingRepository->cancelJob($job, $user);

        $this->assertDatabaseHas('jobs', [
            'id' => $job->id,
            'status' => 'cancelled'
        ]);
    }

    public function testGetTranslatorJobs()
    {
        $translatorId = 1; // Replace with your test translator ID

        $result = $this->bookingRepository->getTranslatorJobs($translatorId);

        $this->assertIsArray($result);
        // ...additional assertions based on the structure of your results...
    }

    public function testGetTranslatorJobsHistory()
    {
        $translatorId = 1; // Replace with your test translator ID
        $request = new Request();

        $result = $this->bookingRepository->getTranslatorJobsHistory($translatorId, $request);

        $this->assertIsArray($result);
        // ...additional assertions based on the structure of your results...
    }

    public function testGetPotentialTranslators()
    {
        $request = new Request([
            // ...Your request data...
        ]);

        $result = $this->bookingRepository->getPotentialTranslators($request);

        $this->assertIsArray($result);
        // ...additional assertions based on the structure of your results...
    }

    public function testUpdateJob()
    {
        $job = Job::factory()->create(); // Make sure you have a Job factory
        $request = new Request([
            // ...Your request data...
        ]);

        $updatedJob = $this->bookingRepository->updateJob($request, $job->id);

        $this->assertInstanceOf(Job::class, $updatedJob);
        // ...additional assertions based on the structure of your results...
    }

    // Test getTranslatorType()
    public function testGetTranslatorType()
    {
        $this->assertEquals('professional', $this->yourClass->getTranslatorType('paid'));
        $this->assertEquals('rwstranslator', $this->yourClass->getTranslatorType('rws'));
        $this->assertEquals('volunteer', $this->yourClass->getTranslatorType('unpaid'));
        $this->assertEquals('', $this->yourClass->getTranslatorType('invalid')); // invalid job type
    }

    // Test getTranslatorLevel()
    public function testGetTranslatorLevel()
    {
        $this->assertEquals(['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care'], $this->yourClass->getTranslatorLevel('yes'));
        $this->assertEquals(['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care'], $this->yourClass->getTranslatorLevel('both'));
        $this->assertEquals(['Certified with specialisation in law'], $this->yourClass->getTranslatorLevel('law'));
        $this->assertEquals(['Certified with specialisation in health care'], $this->yourClass->getTranslatorLevel('health'));
        $this->assertEquals(['Layman', 'Read Translation courses'], $this->yourClass->getTranslatorLevel('normal'));
        $this->assertEquals(['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care', 'Layman', 'Read Translation courses'], $this->yourClass->getTranslatorLevel(null)); // no certificate
        $this->assertEquals([], $this->yourClass->getTranslatorLevel('invalid')); // invalid certification type
    }

    // Test changeStatus()
    public function testChangeStatus()
    {
        $job = new Job();
        $data = ['status' => 'new_status'];
        $this->assertEquals(['statusChanged' => false, 'logData' => []], $this->yourClass->changeStatus($job, $data, false));

        $job->status = 'completed';
        $data['status'] = 'completed';
        $this->assertEquals(['statusChanged' => false, 'logData' => []], $this->yourClass->changeStatus($job, $data, false));

        $job->status = 'completed';
        $data['status'] = 'timedout';
        $data['admin_comments'] = 'Some comments';
        $this->assertEquals(['statusChanged' => true, 'logData' => ['old_status' => 'completed', 'new_status' => 'timedout']], $this->yourClass->changeStatus($job, $data, false));
    }


    // TODO: Add tests for remaining methods.

}
