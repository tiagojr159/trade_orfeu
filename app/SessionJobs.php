<?php
declare(strict_types=1);

namespace Orfeu;

final class SessionJobs
{
    private $store;

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    public function installed(): bool
    {
        try {
            $this->store->db->query('SELECT status FROM orf_job_checkpoint LIMIT 0');
            return true;
        } catch (\PDOException $error) {
            if ((string) $error->getCode() === '42S02') {
                return false;
            }
            throw $error;
        }
    }

    public function lastTerminal(): ?string
    {
        $db = $this->store->db;
        $checkpoint = query($db,'SELECT MAX(session_date) FROM orf_job_checkpoint
            WHERE scope_key=? AND phase=? AND status IN (?,?)',
            ['TOURNAMENT','EOD','DONE','GAP'])->fetchColumn();
        $legacy = query($db,'SELECT MAX(detail) FROM orf_job_run WHERE job=? AND status=?',
            ['EOD','SUCCESS'])->fetchColumn();
        return max((string) $checkpoint,(string) $legacy) ?: null;
    }

    public function due(Calendar $calendar, array $latest, string $at): array
    {
        $last = $this->lastTerminal();
        if ($last !== null) {
            $next = $calendar->next($last);
            if (!$next && $last < $latest['session_date']) {
                throw new \RuntimeException('BLOCKED_CALENDAR_RANGE');
            }
            return $next ?? [];
        }
        $earliest = query($this->store->db,'SELECT MIN(eligible_at) FROM orf_order WHERE status=? AND eligible_at<=?',
            ['ACCEPTED',$at])->fetchColumn();
        $date = $earliest ? Calendar::brazilDate($earliest) : $latest['session_date'];
        return $date < $latest['session_date'] ? ($calendar->get($date) ?? $latest) : $latest;
    }

    public function start(string $date, string $at): void
    {
        query($this->store->db,'INSERT INTO orf_job_checkpoint
            (scope_key,session_date,phase,status,attempts,updated_at,next_attempt_at,detail) VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE status=VALUES(status),attempts=attempts+1,updated_at=VALUES(updated_at),
                next_attempt_at=NULL,detail=VALUES(detail)',['TOURNAMENT',$date,'EOD','RUNNING',1,$at,null,'STARTED']);
    }

    public function finish(string $date, string $at, string $status, string $detail, ?string $retry = null): void
    {
        query($this->store->db,'UPDATE orf_job_checkpoint SET status=?,updated_at=?,next_attempt_at=?,detail=?,failures=0
            WHERE scope_key=? AND session_date=? AND phase=?',
            [$status,$at,$retry,$detail,'TOURNAMENT',$date,'EOD']);
    }

    public function checkpoint(string $date): array
    {
        return query($this->store->db,'SELECT status,failures,next_attempt_at FROM orf_job_checkpoint WHERE scope_key=? AND
            session_date=? AND phase=?',['TOURNAMENT',$date,'EOD'])->fetch() ?: [];
    }

    public function failed(string $date, string $at, string $code): void
    {
        $failures = (int) ($this->checkpoint($date)['failures'] ?? 0) + 1;
        $retry = $failures < 5 ? utc($at . ' +' . (2 ** $failures) . ' minutes') : null;
        query($this->store->db,'UPDATE orf_job_checkpoint SET status=?,updated_at=?,next_attempt_at=?,detail=?,failures=?
            WHERE scope_key=? AND session_date=? AND phase=?',
            [$retry ? 'RETRY' : 'DEAD',$at,$retry,$code,$failures,'TOURNAMENT',$date,'EOD']);
    }

    public function retryDead(string $date, string $at): bool
    {
        return query($this->store->db,'UPDATE orf_job_checkpoint SET status=?,updated_at=?,next_attempt_at=NULL,failures=0,detail=?
            WHERE scope_key=? AND session_date=? AND phase=? AND status=?',
            ['RETRY',$at,'OPERATOR_RETRY','TOURNAMENT',$date,'EOD','DEAD'])->rowCount() === 1;
    }

    public function latest(): array
    {
        return query($this->store->db,'SELECT session_date,phase,status,attempts,failures,updated_at,next_attempt_at,detail
            FROM orf_job_checkpoint WHERE scope_key=? ORDER BY session_date DESC,phase LIMIT 20',
            ['TOURNAMENT'])->fetchAll();
    }
}
