<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Helpers\SendSMSHelper;
use DTApi\Helpers\TeHelper;
use DTApi\Mailers\AppMailer;
use DTApi\Mailers\MailerInterface;
use DTApi\Models\Job;
use DTApi\Models\Language;
use DTApi\Models\Translator;
use DTApi\Models\User;
use DTApi\Models\UserLanguages;
use DTApi\Models\UserMeta;
use Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{
    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     * @param MailerInterface $mailer
     */
    public function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->model = $model;
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * Get the user's jobs based on the user type and status.
     *
     * @param int $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $user = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($user && $user->is('customer')) {
            $jobs = $user->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();
            $usertype = 'customer';
        } elseif ($user && $user->is('translator')) {
            $jobs = Job::getTranslatorJobs($user->id, 'new')->pluck('jobs')->all();
            $usertype = 'translator';
        }

        if ($jobs) {
            foreach ($jobs as $jobitem) {
                if ($jobitem->immediate == 'yes') {
                    $emergencyJobs[] = $jobitem;
                } else {
                    $normalJobs[] = $jobitem;
                }
            }

            $normalJobs = collect($normalJobs)->each(function ($item, $key) use ($user_id) {
                $item['usercheck'] = Job::checkParticularJob($user_id, $item);
            })->sortBy('due')->all();
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'cuser' => $user,
            'usertype' => $usertype
        ];
    }

    /**
     * Get the user's jobs history based on the user type and request parameters.
     *
     * @param int $user_id
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page');
        $pagenum = isset($page) ? $page : "1";
        $user = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($user && $user->is('customer')) {
            $jobs = $user->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);
            $usertype = 'customer';

            return [
                'emergencyJobs' => $emergencyJobs,
                'normalJobs' => [],
                'jobs' => $jobs,
                'cuser' => $user,
                'usertype' => $usertype,
                'numpages' => 0,
                'pagenum' => 0
            ];
        } elseif ($user && $user->is('translator')) {
            $jobs_ids = Job::getTranslatorJobsHistoric($user->id, 'historic', $pagenum);
            $totaljobs = $jobs_ids->total();
            $numpages = ceil($totaljobs / 15);

            $usertype = 'translator';
            $jobs = $jobs_ids;
            $normalJobs = $jobs_ids;

            return [
                'emergencyJobs' => $emergencyJobs,
                'normalJobs' => $normalJobs,
                'jobs' => $jobs,
                'cuser' => $user,
                'usertype' => $usertype,
                'numpages' => $numpages,
                'pagenum' => $pagenum
            ];
        }
    }

    /**
     * Store a new job for the customer.
     *
     * @param Request $request
     * @param User $user
     * @return Job
     */
    public function storeJob(Request $request, User $user)
    {
        $job = new Job();
        $job->user_id = $user->id;
        $job->job_number = $this->generateJobNumber();
        $job->immediate = $request->input('immediate') == 'true' ? 'yes' : 'no';
        $job->due = DateTimeHelper::formatDateTime($request->input('due'));
        $job->from_language_id = $request->input('from_language_id');
        $job->to_language_id = $request->input('to_language_id');
        $job->client_phone = $request->input('client_phone');
        $job->client_email = $request->input('client_email');
        $job->specialization = $request->input('specialization');
        $job->duration = $request->input('duration');
        $job->name = $request->input('name');
        $job->comments = $request->input('comments');
        $job->save();

        if ($job->immediate == 'yes') {
            $admin = User::where('email', env('ADMIN_EMAIL'))->first();
            if ($admin) {
                $this->mailer->sendNewJobNotificationToAdmin($job, $admin);
            }
        }

        return $job;
    }

    /**
     * Cancel the job and send notifications to relevant parties.
     *
     * @param Job $job
     * @param User $user
     */
    public function cancelJob(Job $job, User $user)
    {
        $job->status = 'cancelled';
        $job->save();

        if ($job->translator_id) {
            $translator = Translator::find($job->translator_id);
            $this->mailer->sendJobCancelledToTranslator($job, $translator);
        }

        if ($job->customer_id) {
            $customer = User::find($job->customer_id);
            $this->mailer->sendJobCancelledToCustomer($job, $customer);
        }

        if ($job->due && $job->due < date('Y-m-d H:i:s')) {
            $this->mailer->sendJobCancelledToOldTranslator($job);
        }

        Event::fire(new JobWasCanceled($job, $user));
    }

    /**
     * Generate a unique job number.
     *
     * @return string
     */
    protected function generateJobNumber()
    {
        $jobNumber = 'J' . time() . rand(10, 99);
        $existingJob = Job::where('job_number', $jobNumber)->first();

        // Ensure uniqueness
        while ($existingJob) {
            $jobNumber = 'J' . time() . rand(10, 99);
            $existingJob = Job::where('job_number', $jobNumber)->first();
        }

        return $jobNumber;
    }

    /**
     * Get the jobs assigned to a translator.
     *
     * @param Translator $translator
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getTranslatorJobs(Translator $translator)
    {
        $jobs = $translator->jobs()
            ->with('user.userMeta', 'language', 'feedback')
            ->whereIn('status', ['assigned', 'started'])
            ->orderBy('due', 'asc')
            ->get();

        return $jobs;
    }

    /**
     * Get the translator's job history based on the request parameters.
     *
     * @param Translator $translator
     * @param Request $request
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getTranslatorJobsHistory(Translator $translator, Request $request)
    {
        $page = $request->get('page');
        $pagenum = isset($page) ? $page : "1";
        $jobs = Job::getTranslatorJobsHistoric($translator->id, 'historic', $pagenum);

        return $jobs;
    }


        /**
     * Get potential translators for a job.
     *
     * @param Job $job
     * @return Collection
     */
    public function getPotentialTranslators(Job $job): Collection
    {
        $translatorType = $this->getTranslatorType($job->job_type);
        $jobLanguage = $job->from_language_id;
        $gender = $job->gender;
        $translatorLevel = $this->getTranslatorLevel($job->certified);

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->pluck('translator_id')->all();
        $translators = User::getPotentialUsers($translatorType, $jobLanguage, $gender, $translatorLevel, $blacklist);

        return $translators;
    }

    /**
     * Update a job with the provided data.
     *
     * @param int $id
     * @param array $data
     * @param User $cuser
     * @return string[]
     */
    public function updateJob($id, $data, $cuser): array
    {
        $job = Job::find($id);
        $currentTranslator = $this->getCurrentTranslator($job);

        $logData = [];
        $langChanged = false;

        $changeTranslator = $this->changeTranslator($currentTranslator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $logData[] = $changeTranslator['logData'];
        }

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $oldTime = $job->due;
            $job->due = $data['due'];
            $logData[] = $changeDue['logData'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $logData[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $oldLang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $logData[] = $changeStatus['logData'];
        }

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:', $logData);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();
            if ($changeDue['dateChanged']) {
                $this->sendChangedDateNotification($job, $oldTime);
            }
            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $currentTranslator, $changeTranslator['newTranslator']);
            }
            if ($langChanged) {
                $this->sendChangedLangNotification($job, $oldLang);
            }
        }

        return ['Updated'];
    }

    /**
     * Get the translator type based on the job type.
     *
     * @param string $jobType
     * @return string
     */
    private function getTranslatorType(string $jobType): string
    {
        $translatorType = '';

        switch ($jobType) {
            case 'paid':
                $translatorType = 'professional';
                break;
            case 'rws':
                $translatorType = 'rwstranslator';
                break;
            case 'unpaid':
                $translatorType = 'volunteer';
                break;
        }

        return $translatorType;
    }

    /**
     * Get the translator level based on the certified value.
     *
     * @param string|null $certified
     * @return string[]
     */
    private function getTranslatorLevel(?string $certified): array
    {
        $translatorLevel = [];

        if (!empty($certified)) {
            if (in_array($certified, ['yes', 'both'])) {
                $translatorLevel[] = 'Certified';
                $translatorLevel[] = 'Certified with specialisation in law';
                $translatorLevel[] = 'Certified with specialisation in health care';
            } elseif ($certified == 'law' || $certified == 'n_law') {
                $translatorLevel[] = 'Certified with specialisation in law';
            } elseif ($certified == 'health' || $certified == 'n_health') {
                $translatorLevel[] = 'Certified with specialisation in health care';
            } elseif ($certified == 'normal' || $certified == 'both') {
                $translatorLevel[] = 'Layman';
                $translatorLevel[] = 'Read Translation courses';
            } elseif ($certified == null) {
                $translatorLevel[] = 'Certified';
                $translatorLevel[] = 'Certified with specialisation in law';
                $translatorLevel[] = 'Certified with specialisation in health care';
                $translatorLevel[] = 'Layman';
                $translatorLevel[] = 'Read Translation courses';
            }
        }

        return $translatorLevel;
    }

    /**
     * Change the job status if it differs from the current status.
     *
     * @param Job $job
     * @param array $data
     * @param bool $changedTranslator
     * @return array
     */
    private function changeStatus(Job $job, array $data, bool $changedTranslator): array
    {
        $oldStatus = $job->status;
        $statusChanged = false;

        if ($oldStatus != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $logData = [
                    'old_status' => $oldStatus,
                    'new_status' => $data['status']
                ];
                return ['statusChanged' => $statusChanged, 'logData' => $logData];
            }
        }

        return ['statusChanged' => $statusChanged, 'logData' => []];
    }

    /**
     * Change the job status to timedout.
     *
     * @param Job $job
     * @param array $data
     * @param bool $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus(Job $job, array $data, bool $changedTranslator): bool
    {
        $oldStatus = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();

        if (in_array($data['status'], ['pending', 'assigned']) && date('Y-m-d H:i:s') <= $job->due) {
            if ($data['status'] == 'pending') {
                $job->created_at = date('Y-m-d H:i:s');
                $job->emailsent = 0;
                $job->emailsenttovirpal = 0;
                $job->save();
                $jobData = $this->jobToData($job);

                $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
                $this->mailer->send($user->email, $user->name, $subject, 'emails.job-change-status-to-customer', [
                    'user' => $user,
                    'job' => $jobData
                ]);

                $this->sendNotificationTranslator($job, $jobData, '*');

                return true;
            } elseif ($changedTranslator) {
                $job->save();
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $this->mailer->send($user->email, $user->name, $subject, 'emails.job-accepted', [
                    'user' => $user,
                    'job' => $job
                ]);

                return true;
            }
        }

        $job->status = $oldStatus;
        return false;
    }

    /**
     * Change the job status to completed.
     *
     * @param Job $job
     * @param array $data
     * @return bool
     */
    private function changeCompletedStatus(Job $job, array $data): bool
    {
        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }

        return false;
    }

    /**
     * Change the job status to started.
     *
     * @param Job $job
     * @param array $data
     * @return bool
     */
    private function changeStartedStatus(Job $job, array $data): bool
    {
        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'completed'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];

            if ($data['status'] == 'completed') {
                $user = $job->user()->first();
                if ($data['sesion_time'] == '') {
                    return false;
                }
                $interval = $data['sesion_time'];
                $diff = explode(':', $interval);
                $job->end_at = date('Y-m-d H:i:s');
                $job->session_time = $interval;
                $sessionTime = $diff[0] . ' tim ' . $diff[1] . ' min';

                $dataEmail = [
                    'user' => $user,
                    'job' => $job,
                    'session_time' => $sessionTime,
                    'for_text' => 'faktura'
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($user->email, $user->name, $subject, 'emails.session-ended', $dataEmail);

                $translator = Job::getJobsAssignedTranslatorDetail($job);
                $this->mailer->send($translator->email, $translator->name, $subject, 'emails.session-ended', $dataEmail);
            }

            $job->save();
            return true;
        }

        return false;
    }

    /**
     * Change the job status to pending.
     *
     * @param Job $job
     * @param array $data
     * @param bool $changedTranslator
     * @return bool
     */
    private function changePendingStatus(Job $job, array $data, bool $changedTranslator): bool
    {
        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'assigned'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
            $user = $job->user()->first();

            if ($data['status'] == 'assigned' && $changedTranslator) {
                $job->save();
                $jobData = $this->jobToData($job);

                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $this->mailer->send($user->email, $user->name, $subject, 'emails.job-accepted', [
                    'user' => $user,
                    'job' => $jobData
                ]);

                $translator = Job::getJobsAssignedTranslatorDetail($job);
                $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', [
                    'user' => $translator,
                    'job' => $jobData
                ]);

                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
                $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);

                return true;
            } else {
                $subject = 'Avbokning av bokningsnr: #' . $job->id;
                $this->mailer->send($user->email, $user->name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', [
                    'user' => $user,
                    'job' => $job
                ]);

                $job->save();
                return true;
            }
        }

        return false;
    }

    /**
     * Send session start reminder notification.
     *
     * @param User $user
     * @param Job $job
     * @param string $language
     * @param string $due
     * @param string $duration
     */
    public function sendSessionStartRemindNotification(User $user, Job $job, string $language, string $due, string $duration)
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());

        $data = [
            'notification_type' => 'session_start_remind',
        ];

        $dueExplode = explode(' ', $due);
        $msgText = '';

        if ($job->customer_physical_type == 'yes') {
            $msgText = 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $dueExplode[1] . ' på ' . $dueExplode[0] . ' som varar i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!';
        } else {
            $msgText = 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $dueExplode[1] . ' på ' . $dueExplode[0] . ' som varar i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!';
        }

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $usersArray = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers($usersArray, $job->id, $data, ['en' => $msgText], $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * Change the job status to withdrawafter24.
     *
     * @param Job $job
     * @param array $data
     * @return bool
     */
    private function changeWithdrawafter24Status(Job $job, array $data): bool
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }

        return false;
    }

    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];

            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $this->sendCancellationEmails($job);
            }

            $job->save();
            return true;
        }

        return false;
    }

    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (
            !is_null($current_translator)
            || (isset($data['translator']) && $data['translator'] != 0)
            || $data['translator_email'] != ''
        ) {
            $log_data = [];

            if (
                !is_null($current_translator)
                && (
                    (isset($data['translator']) && $current_translator->user_id != $data['translator'])
                    || $data['translator_email'] != ''
                )
                && (isset($data['translator']) && $data['translator'] != 0)
            ) {
                if ($data['translator_email'] != '') {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }

                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);

                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();

                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];

                $translatorChanged = true;
            } elseif (
                is_null($current_translator)
                && isset($data['translator'])
                && ($data['translator'] != 0 || $data['translator_email'] != '')
            ) {
                if ($data['translator_email'] != '') {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }

                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);

                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];

                $translatorChanged = true;
            }

            if ($translatorChanged) {
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
            }
        }

        return ['translatorChanged' => $translatorChanged];
    }

    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;

        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];

            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }

    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag #' . $job->id;
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    public function sendExpiredNotification($job, $user)
    {
        $data = [
            'notification_type' => 'job_expired',
        ];

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            'en' => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = [
            'job_id'                  => $job->id,
            'from_language_id'        => $job->from_language_id,
            'immediate'               => $job->immediate,
            'duration'                => $job->duration,
            'status'                  => $job->status,
            'gender'                  => $job->gender,
            'certified'               => $job->certified,
            'due'                     => $job->due,
            'job_type'                => $job->job_type,
            'customer_phone_type'     => $job->customer_phone_type,
            'customer_physical_type'  => $job->customer_physical_type,
            'customer_town'           => $user_meta->city,
            'customer_type'           => $user_meta->customer_type,
        ];

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;
        $data['job_for'] = [];

        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }

        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        $this->sendNotificationTranslator($job, $data, '*');
    }

    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = [
            'notification_type' => 'session_start_remind',
        ];

        if ($job->customer_physical_type == 'yes') {
            $msg_text = [
                'en' => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            ];
        } else {
            $msg_text = [
                'en' => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            ];
        }

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    private function getUserTagsStringFromArray($users)
    {
        $user_tags = '[';
        $first = true;

        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }

        $user_tags .= ']';
        return $user_tags;
    }

    public function acceptJob($data, $user)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();

                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];

                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
            }

            $jobs = $this->getPotentialJobs($cuser);
            $response = [
                'list'   => json_encode(['jobs' => $jobs, 'job' => $job], true),
                'status' => 'success'
            ];
        } else {
            $response = [
                'status'  => 'fail',
                'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'
            ];
        }

        return $response;
    }

    public function acceptJobWithId($job_id, $cuser)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);
        $response = [];

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();

                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];

                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $data = [
                    'notification_type' => 'job_accepted'
                ];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    'en' => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                ];

                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = [$user];
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                }

                $response = [
                    'status' => 'success',
                    'list'   => ['job' => $job],
                    'message' => 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due
                ];
            } else {
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response = [
                    'status'  => 'fail',
                    'message' => 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning'
                ];
            }
        } else {
            $response = [
                'status'  => 'fail',
                'message' => 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning'
            ];
        }

        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = [];
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();

            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } else {
                $job->status = 'withdrawafter24';
                $response['jobstatus'] = 'success';
            }

            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';

            if ($translator) {
                $data = [
                    'notification_type' => 'job_cancelled'
                ];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    'en' => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                ];

                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = [$translator];
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->get()->first();

                if ($customer) {
                    $data = [
                        'notification_type' => 'job_cancelled'
                    ];
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = [
                        'en' => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    ];

                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = [$customer];
                        $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));
                    }
                }

                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();
                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);
                $this->sendNotificationTranslator($job, $data, $translator->id);
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }

        return $response;
    }

    public function endJob($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);

        if ($job_detail->status != 'started') {
            return ['status' => 'success'];
        }

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->format('%h:%i:%s');

        $job = $job_detail;
        $job->end_at = $completeddate;
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';

        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'faktura'
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'lön'
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();

        return ['status' => 'success'];
    }

    public function customerNotCall($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->format('%h:%i:%s');

        $job = $job_detail;
        $job->end_at = $completeddate;
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();

        return ['status' => 'success'];
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        $allJobs = Job::query();

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $allJobs->when(isset($requestdata['feedback']) && $requestdata['feedback'] != 'false', function ($query) {
                return $query->where('ignore_feedback', 0)->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', 3);
                });
            });

            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                $allJobs->when(is_array($requestdata['id']), function ($query) use ($requestdata) {
                    return $query->whereIn('id', $requestdata['id']);
                }, function ($query) use ($requestdata) {
                    return $query->where('id', $requestdata['id']);
                });
                $requestdata = array_only($requestdata, ['id']);
            }

            $allJobs->when(isset($requestdata['lang']) && $requestdata['lang'] != '', function ($query) use ($requestdata) {
                return $query->whereIn('from_language_id', $requestdata['lang']);
            });

            $allJobs->when(isset($requestdata['status']) && $requestdata['status'] != '', function ($query) use ($requestdata) {
                return $query->whereIn('status', $requestdata['status']);
            });

            $allJobs->when(isset($requestdata['expired_at']) && $requestdata['expired_at'] != '', function ($query) use ($requestdata) {
                return $query->where('expired_at', '>=', $requestdata['expired_at']);
            });

            $allJobs->when(isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '', function ($query) use ($requestdata) {
                return $query->where('will_expire_at', '>=', $requestdata['will_expire_at']);
            });

            $allJobs->when(isset($requestdata['customer_email']) && count($requestdata['customer_email']) && $requestdata['customer_email'] != '', function ($query) use ($requestdata) {
                $users = User::whereIn('email', $requestdata['customer_email'])->get();
                if ($users) {
                    $userIds = $users->pluck('id')->all();
                    return $query->whereIn('user_id', $userIds);
                }
                return $query;
            });

            $allJobs->when(isset($requestdata['translator_email']) && count($requestdata['translator_email']), function ($query) use ($requestdata) {
                $users = User::whereIn('email', $requestdata['translator_email'])->get();
                if ($users) {
                    $allJobIDs = TranslatorJobRel::whereNull('cancel_at')->whereIn('user_id', $users->pluck('id')->all())->pluck('job_id');
                    return $query->whereIn('id', $allJobIDs);
                }
                return $query;
            });

            $allJobs->when(isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == 'created', function ($query) use ($requestdata) {
                $query->when(isset($requestdata['from']) && $requestdata['from'] != '', function ($q) use ($requestdata) {
                    return $q->where('created_at', '>=', $requestdata['from']);
                })->when(isset($requestdata['to']) && $requestdata['to'] != '', function ($q) use ($requestdata) {
                    $to = $requestdata['to'] . ' 23:59:00';
                    return $q->where('created_at', '<=', $to);
                })->orderBy('created_at', 'desc');
            });

            $allJobs->when(isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == 'due', function ($query) use ($requestdata) {
                $query->when(isset($requestdata['from']) && $requestdata['from'] != '', function ($q) use ($requestdata) {
                    return $q->where('due', '>=', $requestdata['from']);
                })->when(isset($requestdata['to']) && $requestdata['to'] != '', function ($q) use ($requestdata) {
                    $to = $requestdata['to'] . ' 23:59:00';
                    return $q->where('due', '<=', $to);
                })->orderBy('due', 'desc');
            });

            $allJobs->when(isset($requestdata['job_type']) && $requestdata['job_type'] != '', function ($query) use ($requestdata) {
                return $query->whereIn('job_type', $requestdata['job_type']);
            });

            $allJobs->when(isset($requestdata['physical']), function ($query) use ($requestdata) {
                return $query->where('customer_physical_type', $requestdata['physical'])->where('ignore_physical', 0);
            });

            $allJobs->when(isset($requestdata['phone']), function ($query) use ($requestdata) {
                return $query->where('customer_phone_type', $requestdata['phone'])->when(isset($requestdata['physical']), function ($q) use ($requestdata) {
                    return $q->where('ignore_physical_phone', 0);
                });
            });

            $allJobs->when(isset($requestdata['flagged']), function ($query) use ($requestdata) {
                return $query->where('flagged', $requestdata['flagged'])->where('ignore_flagged', 0);
            });

            $allJobs->when(isset($requestdata['distance']) && $requestdata['distance'] == 'empty', function ($query) {
                return $query->doesntHave('distance');
            });

            $allJobs->when(isset($requestdata['salary']) && $requestdata['salary'] == 'yes', function ($query) {
                return $query->doesntHave('user.salaries');
            });

            $allJobs->when(isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '', function ($query) use ($requestdata) {
                return $query->whereHas('user.userMeta', function ($q) use ($requestdata) {
                    $q->where('consumer_type', $requestdata['consumer_type']);
                });
            });

            $allJobs->when(isset($requestdata['booking_type']), function ($query) use ($requestdata) {
                return $query->where(function ($q) use ($requestdata) {
                    if ($requestdata['booking_type'] == 'physical') {
                        $q->where('customer_physical_type', 'yes');
                    } elseif ($requestdata['booking_type'] == 'phone') {
                        $q->where('customer_phone_type', 'yes');
                    }
                });
            });

            $allJobs->orderBy('created_at', 'desc')
                ->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

            if ($limit == 'all') {
                $allJobs = $allJobs->get();
            } else {
                $allJobs = $allJobs->paginate(15);
            }
        } else {
            $allJobs->when(isset($requestdata['id']) && $requestdata['id'] != '', function ($query) use ($requestdata) {
                return $query->where('id', $requestdata['id']);
            });

            if ($consumer_type == 'RWS') {
                $allJobs->where('job_type', 'rws');
            } else {
                $allJobs->where('job_type', 'unpaid');
            }

            $allJobs->when(isset($requestdata['feedback']) && $requestdata['feedback'] != 'false', function ($query) {
                return $query->where('ignore_feedback', 0)->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', 3);
                });
            });

            $allJobs->when(isset($requestdata['lang']) && $requestdata['lang'] != '', function ($query) use ($requestdata) {
                return $query->whereIn('from_language_id', $requestdata['lang']);
            });

            $allJobs->when(isset($requestdata['status']) && $requestdata['status'] != '', function ($query) use ($requestdata) {
                return $query->whereIn('status', $requestdata['status']);
            });

            $allJobs->when(isset($requestdata['job_type']) && $requestdata['job_type'] != '', function ($query) use ($requestdata) {
                return $query->whereIn('job_type', $requestdata['job_type']);
            });

            $allJobs->when(isset($requestdata['customer_email']) && $requestdata['customer_email'] != '', function ($query) use ($requestdata) {
                $user = User::where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    return $query->where('user_id', $user->id);
                }
                return $query;
            });

            $allJobs->when(isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == 'created', function ($query) use ($requestdata) {
                $query->when(isset($requestdata['from']) && $requestdata['from'] != '', function ($q) use ($requestdata) {
                    return $q->where('created_at', '>=', $requestdata['from']);
                })->when(isset($requestdata['to']) && $requestdata['to'] != '', function ($q) use ($requestdata) {
                    $to = $requestdata['to'] . ' 23:59:00';
                    return $q->where('created_at', '<=', $to);
                })->orderBy('created_at', 'desc');
            });

            $allJobs->when(isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == 'due', function ($query) use ($requestdata) {
                $query->when(isset($requestdata['from']) && $requestdata['from'] != '', function ($q) use ($requestdata) {
                    return $q->where('due', '>=', $requestdata['from']);
                })->when(isset($requestdata['to']) && $requestdata['to'] != '', function ($q) use ($requestdata) {
                    $to = $requestdata['to'] . ' 23:59:00';
                    return $q->where('due', '<=', $to);
                })->orderBy('due', 'desc');
            });

            $allJobs->orderBy('created_at', 'desc')
                ->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

            if ($limit == 'all') {
                $allJobs = $allJobs->get();
            } else {
                $allJobs = $allJobs->paginate(15);
            }
        }

        return $allJobs;
    }

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration && $diff[$i] >= $job->duration * 2) {
                    $sesJobs[$i] = $job;
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId[] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = User::where('user_type', '1')->pluck('email')->all();
        $all_translators = User::where('user_type', '2')->pluck('email')->all();

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && $cuser->is('superadmin')) {
            $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

            return ['throttles' => $throttles];
        }

        $allJobs = Job::whereIn('id', $jobId)->when(isset($requestdata['lang']) && $requestdata['lang'] != '', function ($query) use ($requestdata) {
            return $query->whereIn('from_language_id', $requestdata['lang'])->where('ignore', 0);
        })->when(isset($requestdata['status']) && $requestdata['status'] != '', function ($query) use ($requestdata) {
            return $query->whereIn('status', $requestdata['status'])->where('ignore', 0);
        })->when(isset($requestdata['customer_email']) && $requestdata['customer_email'] != '', function ($query) use ($requestdata) {
            $user = User::where('email', $requestdata['customer_email'])->first();
            if ($user) {
                return $query->where('user_id', $user->id)->where('ignore', 0);
            }
            return $query;
        })->when(isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == 'created', function ($query) use ($requestdata) {
            $query->when(isset($requestdata['from']) && $requestdata['from'] != '', function ($q) use ($requestdata) {
                return $q->where('created_at', '>=', $requestdata['from']);
            })->when(isset($requestdata['to']) && $requestdata['to'] != '', function ($q) use ($requestdata) {
                $to = $requestdata['to'] . ' 23:59:00';
                return $q->where('created_at', '<=', $to);
            })->orderBy('created_at', 'desc');
        })->when(isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == 'due', function ($query) use ($requestdata) {
            $query->when(isset($requestdata['from']) && $requestdata['from'] != '', function ($q) use ($requestdata) {
                return $q->where('due', '>=', $requestdata['from']);
            })->when(isset($requestdata['to']) && $requestdata['to'] != '', function ($q) use ($requestdata) {
                $to = $requestdata['to'] . ' 23:59:00';
                return $q->where('due', '<=', $to);
            })->orderBy('due', 'desc');
        })->whereIn('id', $jobId)->orderBy('created_at', 'desc')->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance')->paginate(15);

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }
    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = User::where('user_type', '1')->pluck('email')->all();
        $all_translators = User::where('user_type', '2')->pluck('email')->all();

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = Job::query()
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0)
                ->whereIn('jobs.status', ['pending', 'waiting'])
                ->where('jobs.due', '>=', Carbon::now());

            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang']);
            }

            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status']);
            }

            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = User::where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', $user->id);
                }
            }

            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = User::where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = TranslatorJobRel::where('user_id', $user->id)->pluck('job_id')->all();
                    $allJobs->whereIn('jobs.id', $allJobIDs);
                }
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type']);
            }

            $allJobs->select('jobs.*', 'languages.language')
                ->orderBy('jobs.created_at', 'desc')
                ->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function ignoreExpiring($id)
    {
        Job::where('id', $id)->update(['ignore' => 1]);

        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        Job::where('id', $id)->update(['ignore_expired' => 1]);

        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        Throttles::where('id', $id)->update(['ignore' => 1]);

        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);

        $data = [
            'created_at' => date('Y-m-d H:i:s'),
            'will_expire_at' => TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s')),
            'updated_at' => date('Y-m-d H:i:s'),
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => Carbon::now(),
        ];

        $datareopen = [
            'status' => 'pending',
            'created_at' => Carbon::now(),
            'will_expire_at' => TeHelper::willExpireAt($job->due, Carbon::now()),
        ];

        if ($job->status != 'timedout') {
            Job::where('id', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $jobData = $job->toArray();
            $jobData['status'] = 'pending';
            $jobData['created_at'] = Carbon::now();
            $jobData['updated_at'] = Carbon::now();
            $jobData['will_expire_at'] = TeHelper::willExpireAt($jobData['due'], date('Y-m-d H:i:s'));
            $jobData['updated_at'] = date('Y-m-d H:i:s');
            $jobData['cust_16_hour_email'] = 0;
            $jobData['cust_48_hour_email'] = 0;
            $jobData['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
            $affectedRows = Job::create($jobData);
            $new_jobid = $affectedRows['id'];
        }

        Translator::where('job_id', $jobid)->whereNull('cancel_at')->update(['cancel_at' => $data['cancel_at']]);
        Translator::create($data);

        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time
     * @param  string $format
     * @return string
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } elseif ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = $time % 60;

        return sprintf($format, $hours, $minutes);
    }


}//class here
