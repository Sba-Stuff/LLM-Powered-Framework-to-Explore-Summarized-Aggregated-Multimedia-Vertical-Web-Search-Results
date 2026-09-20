<?php
/**
 * OpenLLM.php
 * ---------------------------------------------------------------------------
 * LLM integration layer for ExSMuV.
 *
 * Talks to a local LM Studio server running lfm2-1.2b and provides the two
 * research operations described in the paper:
 *
 *   llmExtractKeywords()            -> f_topics(x)
 *   llmSummarizeTitleDescription()  -> f_meta(S)
 *
 * Design constraints carried over from the thesis:
 *   - The model is held to an EXTRACTIVE role. Output is validated against the
 *     source metadata and rejected if it invents wording.
 *   - Every LLM answer is cached on disk, so repeated page loads and the
 *     expert evaluation stay reproducible.
 *   - Nothing here echoes to the browser. Callers build their own markup and
 *     all diagnostics go to logs/.
 * ---------------------------------------------------------------------------
 */

/* Local LLM inference on CPU can be slow, especially for the first request
   after a model load. All timeouts are therefore driven by one constant. */
if (!defined('EXSMUV_TIMEOUT')) define('EXSMUV_TIMEOUT', 7200); // 2 hours

set_time_limit(EXSMUV_TIMEOUT);
ini_set('memory_limit', '1G');
ini_set('max_execution_time', EXSMUV_TIMEOUT);
ini_set('default_socket_timeout', EXSMUV_TIMEOUT);

/* -------------------------------------------------------------------------
 * mbstring compatibility.
 * UniServer builds do not always enable php_mbstring. These shims keep the
 * file working on such installs. Enabling the real extension is still
 * preferable for correct handling of non-Latin scripts.
 * ---------------------------------------------------------------------- */
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null)            { return strlen((string) $s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null, $enc = null) {
        return $len === null ? substr((string) $s, $start) : substr((string) $s, $start, $len);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null)        { return strtolower((string) $s); }
}
if (!function_exists('mb_strrpos')) {
    function mb_strrpos($h, $n, $offset = 0, $enc = null) { return strrpos((string) $h, (string) $n, $offset); }
}

/* =========================================================================
 * 1. CONFIGURATION  (Table 3 of the paper)
 * ========================================================================= */

if (!defined('EXSMUV_LLM_URL'))          define('EXSMUV_LLM_URL',          'http://127.0.0.1:1234/v1/chat/completions');
if (!defined('EXSMUV_LLM_KEY'))          define('EXSMUV_LLM_KEY',          'SBA_STUFF');
if (!defined('EXSMUV_LLM_MODEL'))        define('EXSMUV_LLM_MODEL',        'liquid/lfm2-1.2b');
if (!defined('EXSMUV_LLM_TEMPERATURE'))  define('EXSMUV_LLM_TEMPERATURE',  0.15);
if (!defined('EXSMUV_LLM_MAX_TOKENS'))   define('EXSMUV_LLM_MAX_TOKENS',   2000);
if (!defined('EXSMUV_LLM_CONTEXT'))      define('EXSMUV_LLM_CONTEXT',      10000);

/* Context is 10,000 tokens. Reserve max_tokens for the answer, keep a safety
   margin, then convert the remainder to characters at ~3.5 chars per token. */
if (!defined('EXSMUV_MAX_PROMPT_CHARS')) {
    define('EXSMUV_MAX_PROMPT_CHARS', (int) ((EXSMUV_LLM_CONTEXT - EXSMUV_LLM_MAX_TOKENS - 600) * 3.5));
}
if (!defined('EXSMUV_MAX_SYSTEM_CHARS')) define('EXSMUV_MAX_SYSTEM_CHARS', 4000);

/* Retrieval and composition constants */
if (!defined('EXSMUV_TOPIC_LIMIT'))      define('EXSMUV_TOPIC_LIMIT',      5);     // k topics per query
if (!defined('EXSMUV_KEYWORD_LIMIT'))    define('EXSMUV_KEYWORD_LIMIT',    10);    // per Table 4
if (!defined('EXSMUV_SIM_THRESHOLD'))    define('EXSMUV_SIM_THRESHOLD',    0.65);  // theta
if (!defined('EXSMUV_EXTRACTIVE_MIN'))   define('EXSMUV_EXTRACTIVE_MIN',   0.85);  // token coverage required
if (!defined('EXSMUV_TOPIC_DEDUPE'))     define('EXSMUV_TOPIC_DEDUPE',     0.80);  // Jaccard for near-duplicates

/* How many ranked items from each vertical appear on one multimedia document */
if (!defined('EXSMUV_CARD_IMAGES'))      define('EXSMUV_CARD_IMAGES',      3);
if (!defined('EXSMUV_CARD_VIDEOS'))      define('EXSMUV_CARD_VIDEOS',      5);
if (!defined('EXSMUV_CARD_WEB'))         define('EXSMUV_CARD_WEB',         5);

/* Weight of the user's original query relative to the card title when ranking
   the results inside a card. 1 gives them equal weight, 2 counts the query
   twice, 0 ranks on the card title alone. */
if (!defined('EXSMUV_QUERY_WEIGHT'))     define('EXSMUV_QUERY_WEIGHT',     1);

/* Retry behaviour. Kept short because this runs inside an interactive request. */
if (!defined('EXSMUV_LLM_RETRIES'))      define('EXSMUV_LLM_RETRIES',      3);
if (!defined('EXSMUV_LLM_RETRY_DELAYS')) define('EXSMUV_LLM_RETRY_DELAYS', '2,5,10');

if (!defined('EXSMUV_LLM_CACHE'))        define('EXSMUV_LLM_CACHE',        true);
if (!defined('EXSMUV_LLM_DEBUG'))        define('EXSMUV_LLM_DEBUG',        false);


/* =========================================================================
 * 2. PROMPTS  (Tables 4 and 5 of the paper, verbatim)
 * ========================================================================= */

/** Table 4: Assistant Prompt for Keyword creation */
if (!defined('EXSMUV_PROMPT_KEYWORDS')) {
define('EXSMUV_PROMPT_KEYWORDS', <<<'PROMPT'
You are an intelligent AI built to extract the most important keywords from a set of metadata.
Metadata may include titles, descriptions, or other textual fields.
Strict rules:
1. Only extract words or phrases that literally exist in the provided metadata.
Do NOT invent words, paraphrase, or interpret meanings.
2. Focus on relevance, prominence, and frequency. Choose words/phrases that appear multiple times or indicate main topics.
3. Output no more than 10 keywords.
4. Clean text - remove any HTML, Unicode artifacts, or formatting noise.
Output ONLY this JSON structure, with no explanation, no extra text:
{"keywords": ["keyword1", "keyword2", "keyword3", ...]}
PROMPT
);
}

/** Table 5: Assistant Prompt for Title and Description generation */
if (!defined('EXSMUV_PROMPT_SUMMARY')) {
define('EXSMUV_PROMPT_SUMMARY', <<<'PROMPT'
You are an intelligent AI built to provide extractive summarization.
Your task is to create a single, summarized title and description based solely on multiple titles and descriptions provided by the user.
Strict rules:
Perform extractive summarization only. Use only words and phrases that already exist in the provided titles and descriptions. Do NOT add new words, interpretations, or synonyms.
Do not paraphrase. Do not infer meaning. Only combine or select existing wording.
Short and concise: keep the final title and description as short as possible while remaining meaningful.
Clean text: remove any HTML, Unicode artifacts, or formatting noise.
Output ONLY this JSON structure, with no explanation, no extra text:
{
  "title": "Your title here",
  "description": "Your summary here"
}
If any word in the output is not present in the input text, the answer is invalid. Only use exact words or phrases from the provided text.
PROMPT
);
}


/* =========================================================================
 * 3. LOGGING
 * ========================================================================= */

function exsmuvLog($message, $channel = 'llm')
{
    if (!EXSMUV_LLM_DEBUG) return;
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    @file_put_contents(
        $dir . '/' . $channel . '_' . date('Ymd') . '.log',
        '[' . date('H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}


/* =========================================================================
 * 4. CACHE
 * Mirrors cacheOrRun() in verticalsfetcher.php so both layers behave alike.
 * ========================================================================= */

function llmCacheOrRun($prefix, $key, callable $callback)
{
    if (!EXSMUV_LLM_CACHE) return $callback();

    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $file = $dir . '/' . $prefix . '_' . md5($key) . '.json';

    if (file_exists($file)) {
        $cached = json_decode(file_get_contents($file), true);
        if (is_array($cached)) {
            exsmuvLog("cache hit  $prefix " . substr(md5($key), 0, 8));
            return $cached;
        }
    }

    $data = $callback();
    if (is_array($data) && !empty($data['cacheable'])) {
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        exsmuvLog("cache write $prefix " . substr(md5($key), 0, 8));
    }
    return $data;
}


/* =========================================================================
 * 5. TRANSPORT
 * ========================================================================= */

/**
 * Single call to LM Studio. Silent: returns an array, never echoes.
 */
function callLMStudio(
    $prompt,
    $systemMessage = 'You are a helpful assistant.',
    $temperature   = EXSMUV_LLM_TEMPERATURE,
    $maxTokens     = EXSMUV_LLM_MAX_TOKENS,
    $model         = EXSMUV_LLM_MODEL
) {
    $prompt        = mb_substr($prompt, 0, EXSMUV_MAX_PROMPT_CHARS);
    $systemMessage = mb_substr($systemMessage, 0, EXSMUV_MAX_SYSTEM_CHARS);

    $payload = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $systemMessage],
            ['role' => 'user',   'content' => $prompt],
        ],
        'max_tokens'  => $maxTokens,
        'temperature' => $temperature,
        'stream'      => false,
    ];

    $ch = curl_init(EXSMUV_LLM_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . EXSMUV_LLM_KEY,
            'Connection: keep-alive',
        ],
        CURLOPT_TIMEOUT        => EXSMUV_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TCP_KEEPALIVE  => 1,
        CURLOPT_NOSIGNAL       => 1,
    ]);

    $start    = microtime(true);
    $response = curl_exec($ch);
    $duration = round(microtime(true) - $start, 2);

    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        exsmuvLog("curl error: $err");
        return ['success' => false, 'error' => "cURL: $err", 'duration' => $duration];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        exsmuvLog("http $httpCode");
        return ['success' => false, 'error' => "HTTP $httpCode", 'duration' => $duration];
    }

    $api = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'error' => 'Invalid JSON envelope', 'duration' => $duration];
    }

    if (isset($api['error'])) {
        return ['success' => false, 'error' => $api['error']['message'] ?? 'API error', 'duration' => $duration];
    }

    $content = trim($api['choices'][0]['message']['content'] ?? '');
    if ($content === '') {
        return ['success' => false, 'error' => 'Empty content from model', 'duration' => $duration];
    }

    return [
        'success'     => true,
        'content'     => $content,
        'model'       => $model,
        'duration'    => $duration,
        'tokens_used' => $api['usage']['total_tokens'] ?? 0,
    ];
}

/**
 * Retry wrapper. Silent, unlike the previous version which printed HTML and
 * therefore could not be used inside a function that builds a markup string.
 */
function getLLMResponseSilent(
    $prompt,
    $systemMessage,
    $temperature = EXSMUV_LLM_TEMPERATURE,
    $maxTokens   = EXSMUV_LLM_MAX_TOKENS,
    $maxRetries  = EXSMUV_LLM_RETRIES
) {
    $delays = array_map('intval', explode(',', EXSMUV_LLM_RETRY_DELAYS));

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $result = callLMStudio($prompt, $systemMessage, $temperature, $maxTokens);
        if (!empty($result['success'])) {
            exsmuvLog("ok attempt $attempt in {$result['duration']}s");
            return $result;
        }
        exsmuvLog("attempt $attempt failed: " . ($result['error'] ?? '?'));
        if ($attempt < $maxRetries) {
            sleep($delays[$attempt - 1] ?? 5);
        }
    }
    return ['success' => false, 'error' => "All $maxRetries attempts failed"];
}

/** Backwards-compatible alias for older call sites. */
function getLLMResponseWithRetry($prompt, $systemMessage = 'You are a helpful assistant.', $temperature = EXSMUV_LLM_TEMPERATURE, $maxRetries = EXSMUV_LLM_RETRIES)
{
    $r = getLLMResponseSilent($prompt, $systemMessage, $temperature, EXSMUV_LLM_MAX_TOKENS, $maxRetries);
    return !empty($r['success']) ? $r['content'] : 'Error: ' . ($r['error'] ?? 'unknown');
}


/* =========================================================================
 * 6. JSON HANDLING
 * Small models wrap JSON in prose or code fences. Recover it rather than fail.
 * ========================================================================= */

function llmDecodeJson($content)
{
    if (!is_string($content) || $content === '') return null;

    // Strip markdown fences.
    $content = preg_replace('/^\s*```(?:json)?\s*/i', '', trim($content));
    $content = preg_replace('/\s*```\s*$/', '', $content);

    $direct = json_decode($content, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($direct)) return $direct;

    // Fall back to the outermost brace pair.
    $first = strpos($content, '{');
    $last  = strrpos($content, '}');
    if ($first !== false && $last !== false && $last > $first) {
        $slice = substr($content, $first, $last - $first + 1);
        $slice = preg_replace('/,\s*([}\]])/', '$1', $slice);   // trailing commas
        $decoded = json_decode($slice, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;
    }
    return null;
}


/* =========================================================================
 * 7. TEXT UTILITIES AND EXTRACTIVE VALIDATION
 * ========================================================================= */

function llmCleanText($text)
{
    $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $text); // zero-width
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function llmTokens($text)
{
    $text = mb_strtolower(llmCleanText($text), 'UTF-8');
    $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return $parts ?: [];
}

/**
 * Fraction of output tokens that also occur in the source metadata.
 * This is what enforces the extractive constraint stated in the prompt.
 */
function llmExtractiveCoverage($output, $source)
{
    $out = llmTokens($output);
    if (empty($out)) return 0.0;
    $src = array_flip(llmTokens($source));

    $hits = 0;
    foreach ($out as $t) {
        if (isset($src[$t])) $hits++;
    }
    return $hits / count($out);
}

function llmIsExtractive($output, $source, $min = EXSMUV_EXTRACTIVE_MIN)
{
    return llmExtractiveCoverage($output, $source) >= $min;
}

/**
 * Assemble the metadata block sent to the model.
 * Titles are interleaved with their descriptions so the model sees them paired.
 */
function llmBuildMetadata(array $titles, array $descriptions = [], $maxChars = null)
{
    $maxChars = $maxChars ?? (EXSMUV_MAX_PROMPT_CHARS - 400);
    $lines = [];
    $n = max(count($titles), count($descriptions));

    for ($i = 0; $i < $n; $i++) {
        $t = llmCleanText($titles[$i]       ?? '');
        $d = llmCleanText($descriptions[$i] ?? '');
        if ($t === '' && $d === '') continue;
        $line = ($i + 1) . '. ';
        if ($t !== '') $line .= 'TITLE: ' . $t . ' ';
        if ($d !== '') $line .= 'DESCRIPTION: ' . $d;
        $lines[] = trim($line);
    }

    $block = implode("\n", $lines);
    if (mb_strlen($block) > $maxChars) {
        $block = mb_substr($block, 0, $maxChars);
        $cut = mb_strrpos($block, "\n");
        if ($cut !== false && $cut > 0) $block = mb_substr($block, 0, $cut);
    }
    return $block;
}

/** Frequency-based fallback, preserved from the original ExSMuV. */
if (!function_exists('exsmuvFallbackTitle')) {
function exsmuvFallbackTitle($text, $wordCount = 5)
{
    $stop = ['the','and','a','an','in','on','of','to','for','with','at','by','is','are',
             'as','it','that','this','we','you','they','i','he','she','me','him','her',
             'us','them','from','or','be','was','were','has','have','had','not','but'];
    $clean = strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', llmCleanText($text)));
    $words = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
    $counts = array_count_values($words);
    foreach ($stop as $s) unset($counts[$s]);
    foreach ($counts as $w => $c) { if (mb_strlen((string) $w) < 3) unset($counts[$w]); }
    arsort($counts);
    return ucwords(implode(' ', array_slice(array_keys($counts), 0, $wordCount)));
}
}

/** Fallback description: the longest source description, trimmed. */
if (!function_exists('exsmuvFallbackDescription')) {
function exsmuvFallbackDescription($descriptions, $maxLen = 240)
{
    $pool = is_array($descriptions) ? $descriptions : [$descriptions];
    $best = '';
    foreach ($pool as $d) {
        $d = llmCleanText($d);
        if (mb_strlen($d) > mb_strlen($best)) $best = $d;
    }
    if (mb_strlen($best) > $maxLen) {
        $best = mb_substr($best, 0, $maxLen);
        $cut = mb_strrpos($best, ' ');
        if ($cut !== false) $best = mb_substr($best, 0, $cut) . '...';
    }
    return $best;
}
}


/* =========================================================================
 * 8. RESEARCH OPERATIONS
 * ========================================================================= */

/**
 * f_topics(x): extract up to EXSMUV_KEYWORD_LIMIT keywords from aggregated
 * metadata. Keywords that do not literally occur in the source are dropped,
 * which enforces rule 1 of the prompt rather than merely requesting it.
 *
 * @return array{keywords: string[], source: string, cacheable: bool}
 */
function llmExtractKeywords(array $titles, array $descriptions = [], $query = '')
{
    $metadata = llmBuildMetadata($titles, $descriptions);
    if ($metadata === '') {
        return ['keywords' => [], 'source' => 'empty', 'cacheable' => false];
    }

    $cacheKey = 'kw|' . $query . '|' . $metadata;

    return llmCacheOrRun('llm_keywords', $cacheKey, function () use ($metadata, $query) {

        $user = ($query !== '' ? "Query: $query\n\n" : '') . "Metadata:\n" . $metadata;

        $res = getLLMResponseSilent($user, EXSMUV_PROMPT_KEYWORDS);

        $fallback = function () use ($metadata) {
            $words = preg_split('/\s+/', exsmuvFallbackTitle($metadata, EXSMUV_KEYWORD_LIMIT), -1, PREG_SPLIT_NO_EMPTY);
            return ['keywords' => array_slice($words ?: [], 0, EXSMUV_KEYWORD_LIMIT),
                    'source' => 'fallback', 'cacheable' => false];
        };

        if (empty($res['success'])) {
            exsmuvLog('keywords: LLM unavailable, using fallback');
            return $fallback();
        }

        $json = llmDecodeJson($res['content']);
        $raw  = (is_array($json) && isset($json['keywords']) && is_array($json['keywords']))
              ? $json['keywords'] : [];

        // Keep only keywords that literally appear in the metadata.
        $kept = [];
        foreach ($raw as $kw) {
            $kw = llmCleanText($kw);
            if ($kw === '') continue;
            if (!llmIsExtractive($kw, $metadata, 1.0)) {
                exsmuvLog("keywords: dropped non-extractive '$kw'");
                continue;
            }
            $lower = mb_strtolower($kw);
            if (!isset($kept[$lower])) $kept[$lower] = $kw;
        }
        $kept = array_slice(array_values($kept), 0, EXSMUV_KEYWORD_LIMIT);

        if (empty($kept)) return $fallback();

        return [
            'keywords'  => $kept,
            'source'    => 'llm',
            'duration'  => $res['duration'] ?? null,
            'cacheable' => true,
        ];
    });
}

/**
 * f_meta(S): produce ONE summarized title and description from ALL the titles
 * and descriptions of the results belonging to a topic.
 *
 * The result is validated for extractiveness. If the model paraphrases beyond
 * tolerance, the frequency-based method is used instead, so the interface never
 * displays wording that cannot be traced back to the retrieved sources.
 *
 * @return array{title:string, description:string, source:string,
 *               coverage_title:float, coverage_description:float, cacheable:bool}
 */
function llmSummarizeTitleDescription(array $titles, array $descriptions = [], $query = '')
{
    $metadata = llmBuildMetadata($titles, $descriptions);

    if ($metadata === '') {
        return [
            'title'                => $query !== '' ? ucwords($query) : 'Untitled',
            'description'          => '',
            'source'               => 'empty',
            'coverage_title'       => 0.0,
            'coverage_description' => 0.0,
            'cacheable'            => false,
        ];
    }

    $cacheKey = 'sum|' . $query . '|' . $metadata;

    return llmCacheOrRun('llm_summary', $cacheKey, function () use ($metadata, $descriptions, $query) {

        $buildFallback = function ($why) use ($metadata, $descriptions) {
            exsmuvLog("summary: fallback ($why)");
            return [
                'title'                => exsmuvFallbackTitle($metadata, 5),
                'description'          => exsmuvFallbackDescription($descriptions),
                'source'               => 'fallback',
                'reason'               => $why,
                'coverage_title'       => 1.0,
                'coverage_description' => 1.0,
                'cacheable'            => false,
            ];
        };

        $user = ($query !== '' ? "Query: $query\n\n" : '')
              . "Titles and descriptions:\n" . $metadata;

        $res = getLLMResponseSilent($user, EXSMUV_PROMPT_SUMMARY);
        if (empty($res['success'])) return $buildFallback('llm_unavailable');

        $json = llmDecodeJson($res['content']);
        if (!is_array($json)) return $buildFallback('unparseable_json');

        $title = llmCleanText($json['title']       ?? '');
        $desc  = llmCleanText($json['description'] ?? '');
        if ($title === '') return $buildFallback('empty_title');

        $covT = llmExtractiveCoverage($title, $metadata);
        $covD = $desc !== '' ? llmExtractiveCoverage($desc, $metadata) : 1.0;

        if ($covT < EXSMUV_EXTRACTIVE_MIN) {
            return $buildFallback('title_not_extractive_' . round($covT, 2));
        }
        if ($desc !== '' && $covD < EXSMUV_EXTRACTIVE_MIN) {
            // Title is sound, so keep it and replace only the description.
            $desc = exsmuvFallbackDescription($descriptions);
            $covD = 1.0;
            exsmuvLog('summary: description replaced, not extractive');
        }
        if ($desc === '') {
            $desc = exsmuvFallbackDescription($descriptions);
        }

        return [
            'title'                => $title,
            'description'          => $desc,
            'source'               => 'llm',
            'coverage_title'       => round($covT, 3),
            'coverage_description' => round($covD, 3),
            'duration'             => $res['duration'] ?? null,
            'cacheable'            => true,
        ];
    });
}


/* =========================================================================
 * 9. TOPIC NORMALISATION AND DEDUPLICATION
 * Topics arrive from four sources: related searches, query suggestions,
 * TopicMiner and the LLM. The same idea therefore turns up several times in
 * slightly different wording. Duplicates are removed BEFORE ranking, so the
 * ranked list and the bounded working set are not wasted on repeats.
 * ========================================================================= */

/**
 * Canonical form used for comparing two topics.
 *
 * Function words are removed from anywhere in the phrase, not only the edges,
 * so that reorderings such as "cast of harry potter" and "harry potter cast"
 * reduce to the same token set. This form is only ever used for comparison;
 * the original wording is what gets displayed.
 */
function exsmuvNormalizeTopic($topic)
{
    $t = mb_strtolower(llmCleanText($topic));
    $t = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t);   // drop punctuation
    $t = preg_replace('/\s+/u', ' ', $t);
    $t = trim($t);
    if ($t === '') return '';

    $noise = ['a','an','the','of','for','in','on','at','to','with','and','or','by',
              'from','about','is','are','was','were','be','best','top','list','vs',
              'what','which','how','why','when','where'];
    $noise = array_flip($noise);

    $words = preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $kept  = [];
    foreach ($words as $w) {
        if (!isset($noise[$w])) $kept[] = $w;
    }

    // If the phrase was made entirely of function words, keep it as it was.
    return empty($kept) ? $t : implode(' ', $kept);
}

/** Token-set Jaccard similarity, used for near-duplicate detection. */
function exsmuvJaccard($a, $b)
{
    $ta = array_unique(llmTokens($a));
    $tb = array_unique(llmTokens($b));
    if (empty($ta) || empty($tb)) return 0.0;

    $inter = count(array_intersect($ta, $tb));
    $union = count(array_unique(array_merge($ta, $tb)));
    return $union > 0 ? $inter / $union : 0.0;
}

/**
 * Remove exact and near-duplicate topics.
 *
 * Three tests are applied, in increasing cost:
 *   1. identical normalised form
 *   2. one normalised form fully contains the other
 *   3. token-set Jaccard similarity at or above $jaccard
 *
 * The first occurrence wins, so the merge order in FetchTopics() determines
 * which wording survives. Topics equal to the query itself are dropped, since
 * the query already has its own document.
 *
 * @return string[] surviving topics in their original wording
 */
function dedupeTopics(array $topics, $query = '', $jaccard = 0.80)
{
    $queryNorm = exsmuvNormalizeTopic($query);
    $kept      = [];   // original wording
    $keptNorm  = [];   // normalised form, parallel to $kept

    foreach ($topics as $raw) {
        $topic = llmCleanText($raw);
        if ($topic === '') continue;

        $norm = exsmuvNormalizeTopic($topic);
        if ($norm === '') continue;

        // Skip single characters and the query itself.
        if (mb_strlen($norm) < 3) continue;
        if ($queryNorm !== '' && $norm === $queryNorm) continue;

        $duplicate = false;
        foreach ($keptNorm as $i => $existing) {
            if ($norm === $existing) { $duplicate = true; break; }

            if (strpos($existing, $norm) !== false || strpos($norm, $existing) !== false) {
                $duplicate = true;
                // Prefer the more specific wording of the two.
                if (mb_strlen($norm) > mb_strlen($existing)) {
                    $kept[$i]     = $topic;
                    $keptNorm[$i] = $norm;
                }
                break;
            }

            if (exsmuvJaccard($norm, $existing) >= $jaccard) { $duplicate = true; break; }
        }

        if (!$duplicate) {
            $kept[]     = $topic;
            $keptNorm[] = $norm;
        }
    }

    return array_values($kept);
}


/* =========================================================================
 * 10. RESULT RANKING
 *
 * Cosine similarity is used at two distinct points in the pipeline:
 *
 *   1. BETWEEN cards. Candidate topics are ranked against the user's original
 *      query, which decides which topics become multimedia documents.
 *      See rankTitlesByCosineSimilarity() in verticalsfetcher.php.
 *
 *   2. WITHIN a card. The web, image and video results retrieved for a topic
 *      are ranked against the user's original query COMBINED with that card's
 *      title, which decides which items are placed on the card.
 *
 * The second case uses both signals on purpose. Ranking on the card title
 * alone lets a card drift away from what the user actually asked for, because
 * a mined topic is only a fragment of the information need. Ranking on the
 * query alone would make every card show near-identical results. Combining
 * them keeps each card on its own topic while staying anchored to the query.
 * ========================================================================= */

/**
 * Builds the ranking context for the items inside one card.
 *
 * @param string $query       the user's original query
 * @param string $topicTitle  the card's topic
 * @param int    $queryWeight how many times the query is counted
 * @return string text to rank against
 */
function exsmuvRankingContext($query, $topicTitle, $queryWeight = EXSMUV_QUERY_WEIGHT)
{
    $q = llmCleanText($query);
    $t = llmCleanText($topicTitle);

    if ($q === '') return $t;
    if ($t === '') return $q;

    // The query-level card has the query as its own topic. Do not double it.
    if (exsmuvNormalizeTopic($q) === exsmuvNormalizeTopic($t)) return $t;

    $reps = max(0, (int) $queryWeight);
    if ($reps === 0) return $t;

    return trim(str_repeat($q . ' ', $reps) . $t);
}

/**
 * Rank full result records by TF-IDF cosine similarity against a query.
 * Unlike rankTitlesByCosineSimilarity() in verticalsfetcher.php, this keeps
 * the whole record, so URLs and thumbnails survive the ranking.
 *
 * @param string $query
 * @param array  $items  list of associative arrays
 * @param array  $fields which fields make up the text of an item
 * @param int|null $limit  keep at most this many
 * @return array the same records, re-ordered, each with an added '_score'
 */
function rankItemsByCosineSimilarity($query, array $items, array $fields = ['title', 'description'], $limit = null)
{
    if (empty($items)) return [];

    $docs = [$query];
    foreach ($items as $item) {
        $text = '';
        foreach ($fields as $f) {
            if (!empty($item[$f])) $text .= ' ' . $item[$f];
        }
        $docs[] = trim($text);
    }

    // Tokenise
    $tokenized = array_map('llmTokens', $docs);

    // Vocabulary
    $vocab = [];
    foreach ($tokenized as $tokens) {
        foreach ($tokens as $tok) $vocab[$tok] = true;
    }
    $vocabIndex = array_flip(array_keys($vocab));
    $numTerms   = count($vocabIndex);
    $numDocs    = count($tokenized);

    if ($numTerms === 0) {
        $out = $items;
        foreach ($out as $i => $_) $out[$i]['_score'] = 0.0;
        return $limit ? array_slice($out, 0, $limit) : $out;
    }

    // Term frequency
    $tf = [];
    foreach ($tokenized as $tokens) {
        $vec = array_fill(0, $numTerms, 0);
        foreach ($tokens as $tok) {
            if (isset($vocabIndex[$tok])) $vec[$vocabIndex[$tok]]++;
        }
        $tf[] = $vec;
    }

    // Document frequency and inverse document frequency
    $df = array_fill(0, $numTerms, 0);
    foreach ($tf as $vec) {
        foreach ($vec as $i => $c) if ($c > 0) $df[$i]++;
    }
    $idf = [];
    foreach ($df as $d) $idf[] = log(($numDocs + 1) / ($d + 1)) + 1;

    // TF-IDF
    $tfidf = [];
    foreach ($tf as $vec) {
        $row = [];
        foreach ($vec as $i => $c) $row[] = $c * $idf[$i];
        $tfidf[] = $row;
    }

    // Cosine against the query vector
    $q = $tfidf[0];
    $qNorm = 0.0;
    foreach ($q as $v) $qNorm += $v * $v;
    $qNorm = sqrt($qNorm);

    $ranked = [];
    for ($i = 1; $i < $numDocs; $i++) {
        $d = $tfidf[$i];
        $dot = 0.0; $dNorm = 0.0;
        for ($j = 0; $j < $numTerms; $j++) {
            $dot   += $q[$j] * $d[$j];
            $dNorm += $d[$j] * $d[$j];
        }
        $dNorm = sqrt($dNorm);
        $denom = $qNorm * $dNorm;

        $record = $items[$i - 1];
        $record['_score'] = $denom > 0 ? round($dot / $denom, 4) : 0.0;
        $ranked[] = $record;
    }

    usort($ranked, fn($a, $b) => $b['_score'] <=> $a['_score']);

    return $limit !== null ? array_slice($ranked, 0, $limit) : $ranked;
}


/* =========================================================================
 * 11. TOPIC SELECTION
 * Implements the bounded working set: at most EXSMUV_TOPIC_LIMIT topics are
 * ever composed into multimedia documents, however many candidates the miner
 * produced. This is what keeps the interface size independent of result count.
 * ========================================================================= */

/**
 * @param array $rankedTopics rows of ['title' => string, 'score' => float]
 *                            as returned by rankTitlesByCosineSimilarity()
 */
function selectTopics(array $rankedTopics, $limit = EXSMUV_TOPIC_LIMIT, $theta = EXSMUV_SIM_THRESHOLD)
{
    $clean = [];
    $seen  = [];
    foreach ($rankedTopics as $row) {
        $t = llmCleanText($row['title'] ?? '');
        if ($t === '') continue;
        $k = mb_strtolower($t);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $clean[] = ['title' => $t, 'score' => (float) ($row['score'] ?? 0)];
    }

    usort($clean, fn($a, $b) => $b['score'] <=> $a['score']);

    // Prefer topics above theta. If too few clear the bar, fall back to top-k,
    // so a strict threshold can never empty the interface.
    $above  = array_values(array_filter($clean, fn($r) => $r['score'] >= $theta));
    $chosen = (count($above) >= 2) ? $above : $clean;

    return array_slice($chosen, 0, $limit);
}
