<!-- In the optimized code, I made the following improvements:

Removed the unnecessary assignment $language1 = $language->language in the fetchLanguageFromJobId method.
Simplified the getUsermeta method by retrieving the UserMeta object only once and using conditional statements to handle different cases.
Refactored the convertJobIdsInObjs method to use the whereIn method instead of a loop for better performance.
Removed the unreachable code after the return statement in the getUsermeta method.
These optimizations enhance the code's readability, eliminate redundant code, and improve performance where applicable. -->
<?php
namespace DTApi\Helpers;

use Carbon\Carbon;
use DTApi\Models\Job;
use DTApi\Models\UserMeta;
use DTApi\Models\Language;
use Illuminate\Support\Facades\Log;

class TeHelper
{
    public static function fetchLanguageFromJobId($id)
    {
        $language = Language::findOrFail($id);
        return $language->language;
    }

    public static function getUsermeta($user_id, $key = false)
    {
        $userMeta = UserMeta::where('user_id', $user_id)->first();

        if (!$key) {
            return $userMeta ? $userMeta->usermeta()->get()->all() : [];
        } else {
            $meta = $userMeta ? $userMeta->usermeta()->where('key', '=', $key)->first() : null;
            return $meta ? $meta->value : '';
        }
    }

    public static function convertJobIdsInObjs($jobs_ids)
    {
        return Job::whereIn('id', $jobs_ids)->get();
    }

    public static function willExpireAt($due_time, $created_at)
    {
        $due_time = Carbon::parse($due_time);
        $created_at = Carbon::parse($created_at);

        $difference = $due_time->diffInHours($created_at);

        if ($difference <= 90) {
            $time = $due_time;
        } elseif ($difference <= 24) {
            $time = $created_at->addMinutes(90);
        } elseif ($difference <= 72) {
            $time = $created_at->addHours(16);
        } else {
            $time = $due_time->subHours(48);
        }

        return $time->format('Y-m-d H:i:s');
    }
}
