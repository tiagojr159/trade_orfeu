<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;
use Throwable;

final class News
{
    private const SOURCE = 'NEWSAPI';
    private const QUERY = '(B3 OR Ibovespa OR "bolsa brasileira" OR "mercado brasileiro" OR PETR4 OR VALE3 OR BBAS3)';
    private const POSITIVE = ['alta','avança','avanco','cresce','lucro','recorde','compra','otimismo','valoriza','ganho','dividendo','supera'];
    private const NEGATIVE = ['queda','cai','recuo','perda','prejuízo','prejuizo','risco','crise','desvaloriza','venda','baixa','investigação','investigacao'];

    public static function installed(Store $store): bool
    {
        return $store->db->query("SHOW TABLES LIKE 'orf_news'")->fetchColumn() === 'orf_news';
    }

    public static function sentiment(string $text): array
    {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($text,'UTF-8') : strtolower($text);
        $positive = 0;
        $negative = 0;
        foreach (self::POSITIVE as $word) {
            if (preg_match('/\b' . preg_quote($word,'/') . '\b/u',$normalized)) {
                $positive++;
            }
        }
        foreach (self::NEGATIVE as $word) {
            if (preg_match('/\b' . preg_quote($word,'/') . '\b/u',$normalized)) {
                $negative++;
            }
        }
        $total = $positive + $negative;
        $score = $total ? ($positive - $negative) / $total : 0.0;
        return ['score'=>$score,'label'=>$score > .15 ? 'POSITIVO' : ($score < -.15 ? 'NEGATIVO' : 'NEUTRO')];
    }

    public static function collect(Store $store, Config $config, string $at, bool $dry = false): array
    {
        $key = trim($config->get('NEWS_API_KEY'));
        if ($key === '') {
            return ['status'=>'BLOCKED_NEWS_API_KEY','stored'=>0];
        }
        if (!self::installed($store)) {
            return ['status'=>'BLOCKED_NEWS_MIGRATION','stored'=>0];
        }
        $db = $store->db;
        return lock($db,'newsapi',static function () use ($db,$key,$at,$dry,$config): array {
            $health = query($db,'SELECT * FROM orf_source_health WHERE source=?',[self::SOURCE])->fetch();
            if ($health && $health['next_attempt'] > $at) {
                return ['status'=>'SKIPPED_NOT_DUE','stored'=>0,'next_attempt'=>$health['next_attempt']];
            }
            $url = 'https://newsapi.org/v2/everything?' . http_build_query([
                'q'=>self::QUERY,'language'=>'pt','sortBy'=>'publishedAt','pageSize'=>100,
            ]);
            try {
                $raw = https($url,['X-Api-Key: ' . $key],$config->get('BRAPI_CA_FILE'));
                $body = json_decode($raw,true,64,JSON_THROW_ON_ERROR);
                if (($body['status'] ?? '') !== 'ok' || !is_array($body['articles'] ?? null)) {
                    throw new RuntimeException('NEWSAPI_INVALID_RESPONSE');
                }
                $articles = $body['articles'];
                $stored = 0;
                foreach ($articles as $article) {
                    $title = trim((string)($article['title'] ?? ''));
                    $link = trim((string)($article['url'] ?? ''));
                    if ($title === '' || strlen($title) > 500 || !filter_var($link,FILTER_VALIDATE_URL) ||
                        parse_url($link,PHP_URL_SCHEME) !== 'https') {
                        continue;
                    }
                    $published = null;
                    if (isset($article['publishedAt']) && is_string($article['publishedAt'])) {
                        try { $published = timestamp($article['publishedAt']); } catch (Throwable $ignored) { $published = null; }
                    }
                    $description = trim((string)($article['description'] ?? ''));
                    $description = $description === '' ? null : substr($description,0,1200);
                    $text = $title . ' ' . ($description ?? '');
                    $sentiment = self::sentiment($text);
                    $source = trim((string)($article['source']['name'] ?? 'NewsAPI'));
                    $symbol = self::symbol($text);
                    $hash = hash('sha256',strtolower($link));
                    if (!$dry) {
                        $insert = query($db,'INSERT INTO orf_news(content_hash,source,symbol,title,description,url,
                            published_at,available_at,ingested_at,sentiment,sentiment_label)
                            VALUES(?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE content_hash=content_hash',
                            [$hash,substr($source,0,120),$symbol,$title,$description,$link,$published,$at,$at,
                                $sentiment['score'],$sentiment['label']]);
                        $stored += $insert->rowCount() > 0 ? 1 : 0;
                    }
                }
                if (!$dry) {
                    $interval = max(900,(int)$config->get('NEWS_POLL_SECONDS','1800'));
                    $next = utc('@' . (strtotime($at . ' UTC') + $interval));
                    $today = Calendar::brazilDate($at);
                    $requests = $health && $health['request_day'] === $today ? (int)$health['requests'] + 1 : 1;
                    query($db,'INSERT INTO orf_source_health(source,status,last_attempt,last_success,next_attempt,
                        request_day,requests,detail) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE
                        status=VALUES(status),last_attempt=VALUES(last_attempt),last_success=VALUES(last_success),
                        next_attempt=VALUES(next_attempt),request_day=VALUES(request_day),requests=VALUES(requests),detail=VALUES(detail)',
                        [self::SOURCE,'AVAILABLE',$at,$at,$next,$today,$requests,'ARTICLES_' . count($articles)]);
                }
                return ['status'=>$dry ? 'DRY_RUN' : 'AVAILABLE','received'=>count($articles),'stored'=>$stored];
            } catch (HttpFailure $error) {
                self::failure($db,$health,$at,'NEWSAPI_HTTP_' . $error->status,600);
                return ['status'=>'BLOCKED_SOURCE','code'=>'NEWSAPI_HTTP_' . $error->status,'stored'=>0];
            } catch (Throwable $error) {
                self::failure($db,$health,$at,publicError($error),1800);
                return ['status'=>'ERROR','code'=>publicError($error),'stored'=>0];
            }
        });
    }

    private static function failure($db, ?array $health, string $at, string $code, int $delay): void
    {
        $today = Calendar::brazilDate($at);
        $requests = $health && $health['request_day'] === $today ? (int)$health['requests'] + 1 : 1;
        query($db,'INSERT INTO orf_source_health(source,status,last_attempt,last_success,next_attempt,
            request_day,requests,detail) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE
            status=VALUES(status),last_attempt=VALUES(last_attempt),next_attempt=VALUES(next_attempt),
            request_day=VALUES(request_day),requests=VALUES(requests),detail=VALUES(detail)',
            [self::SOURCE,'ERROR',$at,$health['last_success'] ?? null,utc('@' . (strtotime($at . ' UTC')+$delay)),
                $today,$requests,substr($code,0,80)]);
    }

    private static function symbol(string $text): ?string
    {
        foreach (['BBAS3','VALE3','PETR4','MGLU3','HAPV3','BRKM5','PCAR3','SIMH3','MOVI3','LJQQ3'] as $symbol) {
            if (preg_match('/\b' . $symbol . '\b/i',$text)) return $symbol;
        }
        return null;
    }
}
