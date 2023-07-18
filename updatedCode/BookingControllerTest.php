<?php

namespace Tests\Http\Controllers;

use DTApi\Http\Controllers\BookingController;
use DTApi\Repository\BookingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\Request;
use Tests\TestCase;

class BookingControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $bookingRepository;
    protected $bookingController;

    public function setUp(): void
    {
        parent::setUp();

        $this->bookingRepository = $this->createMock(BookingRepository::class);
        $this->bookingController = new BookingController($this->bookingRepository);
    }

    public function testIndex()
    {
        $request = Request::create('/booking', 'GET');
        $this->bookingRepository->expects($this->once())->method('getAll')->with($request);
        $response = $this->bookingController->index($request);
        $this->assertEquals(200, $response->status());
    }

    public function testShow()
    {
        $id = 1;
        $this->bookingRepository->expects($this->once())->method('find')->with($id);
        $response = $this->bookingController->show($id);
        $this->assertEquals(200, $response->status());
    }

    public function testStore()
    {
        $data = ['key' => 'value'];
        $request = Request::create('/booking', 'POST', $data);
        $this->bookingRepository->expects($this->once())->method('store')->with($request->__authenticatedUser, $data);
        $response = $this->bookingController->store($request);
        $this->assertEquals(200, $response->status());
    }

    public function testUpdate()
    {
        $id = 1;
        $data = ['key' => 'value'];
        $request = Request::create("/booking/{$id}", 'PUT', $data);
        $this->bookingRepository->expects($this->once())->method('updateJob')->with($id, $data, $request->__authenticatedUser);
        $response = $this->bookingController->update($id, $request);
        $this->assertEquals(200, $response->status());
    }

    public function testImmediateJobEmail()
    {
        $data = ['key' => 'value'];
        $request = Request::create('/booking/email', 'POST', $data);
        $this->bookingRepository->expects($this->once())->method('storeJobEmail')->with($data);
        $response = $this->bookingController->immediateJobEmail($request);
        $this->assertEquals(200, $response->status());
    }

    public function testGetHistory()
    {
        $request = Request::create('/booking/history', 'GET', ['user_id' => 1]);
        $this->bookingRepository->expects($this->once())->method('getUsersJobsHistory')->with(1, $request);
        $response = $this->bookingController->getHistory($request);
        $this->assertEquals(200, $response->status());
    }

    public function testAcceptJob()
    {
        $data = ['job_id' => 'value'];
        $request = Request::create('/booking/accept', 'POST', $data);
        $this->bookingRepository->expects($this->once())->method('acceptJob')->with($data, $request->__authenticatedUser);
        $response = $this->bookingController->acceptJob($request);
        $this->assertEquals(200, $response->status());
    }

    public function testAcceptJobWithId()
    {
        $request = Request::create('/booking/acceptWithId', 'POST', ['job_id' => 1]);
        $this->bookingRepository->expects($this->once())->method('acceptJobWithId')->with(1, $request->__authenticatedUser);
        $response = $this->bookingController->acceptJobWithId($request);
        $this->assertEquals(200, $response->status());
    }

    public function testCancelJob()
    {
        $data = ['job_id' => 'value'];
        $request = Request::create('/booking/cancel', 'POST', $data);
        $this->bookingRepository->expects($this->once())->method('cancelJobAjax')->with($data, $request->__authenticatedUser);
        $response = $this->bookingController->cancelJob($request);
        $this->assertEquals(200, $response->status());
    }

    public function testEndJob()
    {
        $data = ['job_id' => 'value'];
        $request = Request::create('/booking/end', 'POST', $data);
        $this->bookingRepository->expects($this->once())->method('endJob')->with($data);
        $response = $this->bookingController->endJob($request);
        $this->assertEquals(200, $response->status());
    }

    public function testCustomerNotCall()
    {
        $data = ['customer_id' => 'value'];
        $request = Request::create('/booking/customerNotCall', 'POST', $data);
        $this->bookingRepository->expects($this->once())->method('customerNotCall')->with($data);
        $response = $this->bookingController->customerNotCall($request);
        $this->assertEquals(200, $response->status());
    }

    public function testGetPotentialJobs()
    {
        $request = Request::create('/booking/potentialJobs', 'GET');
        $this->bookingRepository->expects($this->once())->method('getPotentialJobs')->with($request->__authenticatedUser);
        $response = $this->bookingController->getPotentialJobs($request);
        $this->assertEquals(200, $response->status());
    }

    public function testDistanceFeed()
    {
        $data = ['distance' => 'value', 'time' => 'value', 'jobid' => 1];
        $request = Request::create('/booking/distanceFeed', 'POST', $data);
        // This method should test the repository method which updates the distance
        // The actual method to be called might be different
        // This is just an example.
        $response = $this->bookingController->distanceFeed($request);
        $this->assertEquals(200, $response->status());
    }

    public function testReopen()
    {
        $data = ['job_id' => 'value'];
        $request = Request::create('/booking/reopen', 'POST', $data);
        $this->bookingRepository->expects($this->once())->method('reopen')->with($data);
        $response = $this->bookingController->reopen($request);
        $this->assertEquals(200, $response->status());
    }

    public function testResendNotifications()
    {
        $data = ['jobid' => 1];
        $request = Request::create('/booking/resendNotifications', 'POST', $data);
        $this->bookingRepository->expects($this->once())->method('find')->with(1);
        $response = $this->bookingController->resendNotifications($request);
        $this->assertEquals(200, $response->status());
    }
}
