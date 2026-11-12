<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    /**
     * The statuses of an event the athletes can still act on.
     *
     * A scheduled event is the normal case, an ongoing event is happening
     * right now, and a postponed event moved to a new date. The events that
     * are completed or cancelled are settled and belong to the history.
     */
    public const OPEN_STATUSES = ['Scheduled', 'Ongoing', 'Postponed'];

    protected $fillable = ['title', 'description', 'venue', 'starts_at', 'ends_at', 'status', 'sport_id', 'event_type', 'coach_id', 'team_name', 'max_participants', 'notes'];

    /**
     * The events that still matter for an athlete: the ones that are open and
     * the ones that have not ended yet.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES)
            ->where(function (Builder $nested): void {
                $nested->where('starts_at', '>=', now())
                    ->orWhere('ends_at', '>=', now());
            });
    }

    /**
     * The events of the sports an athlete belongs to. An event without a
     * sport is open to every athlete.
     *
     * @param  array<int, int>  $sportIds
     */
    public function scopeForAthleteSports(Builder $query, array $sportIds): Builder
    {
        return $query->where(function (Builder $nested) use ($sportIds): void {
            $nested->whereNull('sport_id');

            if ($sportIds !== []) {
                $nested->orWhereIn('sport_id', $sportIds);
            }
        });
    }

    public function sport()
    {
        return $this->belongsTo(Sport::class);
    }

    public function coach()
    {
        return $this->belongsTo(Coach::class);
    }

    public function attendanceRecords()
    {
        return $this->hasMany(Attendance::class);
    }

    public function attendanceSessions()
    {
        return $this->hasMany(AttendanceSession::class);
    }

    /**
     * Achievements earned in this event. The competition name is never copied
     * onto the achievement, so linking the event is the only thing needed.
     */
    public function achievements()
    {
        return $this->hasMany(Achievement::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];
}