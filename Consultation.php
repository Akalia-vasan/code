<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Consultation extends Model
{

    protected $fillable = ['sent_reminder'];
    /*
     * status
     * pending - 1
     * reject - (-1)
     * approved - 2
     * all meeting draft - 0 - first time we store with 0 (for booking step timer)
     *
     * canceled_at = host reject date time, Client added NO response for promo
     * is_no_show = if meeting went to NO SHOW - refer command (app/Console/Commands/LapsedMeeting.php)
     *
     * two_way_started_at = host meeting started time
     * two_way_ended_at = host meeting ended time
     * two_way_late_min = this is to identify LATE min and NO SHOW (minus value is late, plus value is late)
     *
     * is_transferred
     * 1  => payment transferred to connect account
     * 2  => payment ready to transfer to connect account (prepare job command)
     * -1 => payment refunded to client
     */

    public function user()
    {
        return $this->belongsTo('App\User', 'user_id');
    }

    public function coach()
    {
        return $this->belongsTo('App\User', 'coach_id');
    }

    public function meeting()
    {
        return $this->hasOne('App\JitsiMeet', 'id','meeting_id');
    }

    public function reminders()
    {
        return $this->hasMany('App\ConsultationPaymentReminder', 'consultation_id');
    }

    public function slot()
    {
        return $this->belongsTo('App\ConsultationBookingTimes', 'slot_id');
    }

    public function messages()
    {
        return $this->hasMany('App\ContactForm', 'consultation_id')
            ->orderBy('created_at', 'DESC');
    }

    public function latestMessages()
    {
        return $this->messages()->where('sender_id', '!=', Auth::id())->first();
    }

    public function localTime()
    {
        $hour =  date("H:i", strtotime($this->time));
        $date = new \DateTime($this->date. ' ' .$hour, new \DateTimeZone(get_expert_timezone($this->coach->id)));
        $date->setTimezone(new \DateTimeZone($this->user->timezone));

        return $date->format('d F, Y h:i A') . "\n";
    }

    public function meetingDateTime()
    {
        $hour =  date("H:i", strtotime($this->time));
        $date = new \DateTime($this->date. ' ' .$hour);

        return $date->format('Y-m-d H:i:s');
    }

    public function meetingSelectedTimezone()
    {
        $hour =  date("H:i", strtotime($this->time));
        $date = new \DateTime($this->date. ' ' .$hour, new \DateTimeZone(get_expert_timezone($this->coach->id)));
        $date->setTimezone(new \DateTimeZone($this->timezone));

        return $date->format('d F, Y h:i A') . "\n";
    }

    public function meetingDateWithSelectedTimezone()
    {
        $hour =  date("H:i", strtotime($this->time));
        $date = new \DateTime($this->date. ' ' .$hour, new \DateTimeZone(get_expert_timezone($this->coach->id)));
        $date->setTimezone(new \DateTimeZone($this->timezone));

        return $date->format('m/d/Y') . "\n";
    }

    public function meetingDateWithSelectedTimezoneDFYFormat()
    {
        $hour =  date("H:i", strtotime($this->time));
        $date = new \DateTime($this->date. ' ' .$hour, new \DateTimeZone(get_expert_timezone($this->coach->id)));
        $date->setTimezone(new \DateTimeZone($this->timezone));

        return $date->format('d F, Y') . "\n";
    }

    public function meetingDateWithSelectedTimezoneMDYFormat()
    {
        $hour =  date("H:i", strtotime($this->time));
        $date = new \DateTime($this->date. ' ' .$hour, new \DateTimeZone(get_expert_timezone($this->coach->id)));
        $date->setTimezone(new \DateTimeZone($this->timezone));

        return $date->format('m/d/Y') . "\n";
    }

    public function meetingTimeWithSelectedTimezoneObject()
    {
        $hour =  date("H:i", strtotime($this->time));
        $date = new \DateTime($this->date. ' ' .$hour, new \DateTimeZone(get_expert_timezone($this->coach->id)));
        $date->setTimezone(new \DateTimeZone($this->timezone));

        return $date->format('h:i A') . "\n";
    }

    public function isRebooked()
    {
        return $this->hasOne('App\Consultation', 'parent_id');
    }

    public function meetingApprovedDateWithSelectedTimezone()
    {
        $date = new \DateTime($this->approved_at, new \DateTimeZone(get_expert_timezone($this->coach->id)));
        $date->setTimezone(new \DateTimeZone($this->timezone));

        return $date->format('M, d Y') . "\n";
    }

    public function meetingStartDateWithSelectedTimezone()
    {
        $date = new \DateTime($this->two_way_started_at, new \DateTimeZone(get_expert_timezone($this->coach->id)));
        $date->setTimezone(new \DateTimeZone($this->timezone));

        return $date->format('M, d Y') . "\n";
    }

}
