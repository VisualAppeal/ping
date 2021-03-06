<?php

class Check extends Eloquent
{
	protected $table = 'checks';

	public $softDelete = true;

	protected $fillable = array(
		'company_id',
		'user_id',
		'url',
		'port',
		'username',
		'password',
		'check_for',
		'interval',
		'notify_failed_checks',
		'notify_back_online',
		'latency_satisfied',
		'latency_tolerating',
		'paused',
	);

	public function getTitleAttribute()
	{
		$title = str_replace(array('http://', 'https://'), '', $this->url);
		$title = str_replace('www.', '', $title);
		$title = str_finish($title, '/');

		$title = substr($title, 0, strlen($title) - 1);

		return $title;
	}

	public function getStatusOkAttribute()
	{
		$results = $this->results()
			->orderBy('created_at', 'desc')
			->take(1)
			->get();

		if (count($results) == 0)
			return null;

		return (bool) $results->first()->success;
	}

	public function getIntervalFormattedAttribute()
	{
		return $this->interval . ' ' . Lang::choice('check.diff.minutes', $this->interval);
	}

	public function isPaused()
	{
		return (bool) ($this->paused);
	}

	public function scopeForUser(Illuminate\Database\Eloquent\Builder $query, $userId)
	{
		return $query->join('users_companies', 'users_companies.company_id', '=', 'checks.company_id')
			->where(function($query) use($userId) {
				$query->where('users_companies.user_id', '=', $userId)
					->orWhere('checks.user_id', '=', $userId);
			});
	}

	public function minutesToHuman($seconds)
	{
		$minutes = $seconds / 60;

		$d = floor($minutes / 1440);
		$h = floor($minutes / 60) % 24;
		$m = $minutes % 60;

		$return = '';
		if ($d > 0)
			$return .= $d . ' ' . Lang::choice('check.diff.days', $d) . ' ';

		if ($h > 0)
			$return .= $h . ' ' . Lang::choice('check.diff.hours', $h) . ' ';

		if ($m > 0)
			$return .= $m . ' ' . Lang::choice('check.diff.minutes', $m);

		return $return;
	}

	public function getLog($offset = 0, $limit = 20)
	{
		$offset = intval($offset);
		$limit = intval($limit);

		$controller = $this;

		$data = DB::select(DB::raw('SELECT MIN(`created_at`) AS `start`,
				MAX(`created_at`) AS `end`,
				MAX(UNIX_TIMESTAMP(`created_at`)) - MIN(UNIX_TIMESTAMP(`created_at`)) + 1 `duration`,
				`success`
			FROM (
				SELECT
					`t`.`id`,
					`t`.`created_at`,
					if (@last_success = success, @group, @group:=@group+1) group_number,
						@last_success := success as success
				FROM `' . DB::getTablePrefix() . 'checks_results` AS `t`
				CROSS JOIN (
					SELECT @last_status := null, @group:=0
				) as `init_vars`
				ORDER BY `t`.`created_at`
			) q
			GROUP BY `group_number`
			ORDER BY `start` DESC
			LIMIT ' . $offset . ', ' . $limit . ''
		));

		$data = array_map(function($date) use($controller) {
			return array(
				'start' => new \Carbon\Carbon($date->start),
				'end' => new \Carbon\Carbon($date->end),
				'duration' => $date->duration,
				'duration_locale' => $controller->minutesToHuman($date->duration),
				'success' => (bool) $date->success,
			);
		}, $data);

		return $data;
	}

	private function statusCount($success, $hours)
	{
		return CheckResult::where('check_id', '=', $this->id)
			->where('success', '=', $success)
			->where('created_at', '>=', DB::raw(sprintf('DATE_SUB(NOW(), INTERVAL %s HOUR)', $hours)))
			->count();
	}

	public function successCount($hours = 1)
	{
		return $this->statusCount(1, $hours);
	}

	public function errorCount($hours = 1)
	{
		return $this->statusCount(0, $hours);
	}

	public function theUser()
	{
		return $this->belongsTo('User', 'user_id');
	}

	public function theCompany()
	{
		return $this->belongsTo('Company', 'company_id');
	}

	public function results()
	{
		return $this->hasMany('CheckResult', 'check_id');
	}
}