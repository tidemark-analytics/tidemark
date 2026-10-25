<?php

namespace App\Console\Commands;

use App\Alerts\AlertDispatcher;
use App\Alerts\RuleEvaluator;
use App\Models\AlertRule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RunAlerts extends Command
{
    protected $signature = 'alerts:run';

    protected $description = 'Check the alert rules and send the ones that fire';

    public function handle(RuleEvaluator $evaluator, AlertDispatcher $dispatcher): int
    {
        $fired = 0;
        $allowed = [];
        foreach (AlertRule::with('site')->where('enabled', true)->cursor() as $rule) {
            $site = $rule->site;
            if (! $site) {
                $rule->delete();

                continue;
            }
            if (! ($allowed[$site->id] ??= $site->alertsAllowed())) { // a plan without alerts pauses its rules; they are kept
                continue;
            }
            $now = CarbonImmutable::now($site->timezone);
            if (RuleEvaluator::inQuietHours($rule, $now)) {
                continue;
            }
            try {
                $event = $evaluator->evaluate($rule, $site, $now);
                $cool = RuleEvaluator::COOLDOWN[$rule->type] ?? 0;
                if ($event && $cool && $rule->last_fired_at && $rule->last_fired_at->gt($now->subMinutes($cool))) {
                    $event = null;
                }
                if ($event) {
                    $dispatcher->send($rule, $site, $event);
                    $rule->last_fired_at = $now->utc();
                    $fired++;
                }
                if ($rule->isDirty()) {
                    $rule->save();
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("Sent {$fired} alert(s).");

        return self::SUCCESS;
    }
}
