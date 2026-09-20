<?php
/**
 * searchresults.php
 * ---------------------------------------------------------------------------
 * Builds the multimedia documents shown on the ExSMuV result page.
 *
 * Pipeline for FetchTopics():
 *
 *   related searches + suggestions          engine signals
 *   + TopicMiner subtopics                  lexical mining
 *   + LLM keywords                          semantic mining
 *        |
 *        v
 *   dedupeTopics()                          exact and near-duplicate removal
 *        |
 *        v
 *   rankTitlesByCosineSimilarity()          rank against the query
 *        |
 *        v
 *   selectTopics()                          bounded working set, k topics
 *        |
 *        v
 *   per topic: retrieve web, images, videos
 *              re-rank each vertical against the topic
 *              keep top 5 web, top 3 images, top 5 videos
 *              LLM extractive summarization over the web metadata
 *              render one comprehensive multimedia document
 * ---------------------------------------------------------------------------
 */

include('debugging.php');
include_once('verticalsfetcher.php');
include_once('subtopicminer.php');
include_once('OpenLLM.php');

/* Local LLM inference is slow, so lift the script limit for this page too.
   EXSMUV_TIMEOUT is defined in OpenLLM.php. */
set_time_limit(EXSMUV_TIMEOUT);
ini_set('max_execution_time', EXSMUV_TIMEOUT);

/* How Summarize() behaves.
   'aggregate'  one document built from ALL results   (default, matches paper)
   'per_result' one document per result               (legacy layout)          */
if (!defined('EXSMUV_SUMMARIZE_MODE')) define('EXSMUV_SUMMARIZE_MODE', 'aggregate');

/* How many results are retrieved per vertical before re-ranking. */
if (!defined('EXSMUV_FETCH_WEB')) define('EXSMUV_FETCH_WEB', 10);

/* Kept for backward compatibility with any other file that calls it. */
if (!function_exists('generateTitleFromParagraph')) {
    function generateTitleFromParagraph($paragraph) {
        return exsmuvFallbackTitle($paragraph, 5);
    }
}

/* ---------------------------------------------------------------------------
 * Rendering
 * ------------------------------------------------------------------------- */

/**
 * Renders one comprehensive multimedia document.
 *
 * Layout: ranked image strip and video posters on the left, then the
 * summarized title and description, then the ranked web references.
 *
 * $images, $videos and $web are expected to be ALREADY ranked and trimmed.
 */
function renderMultimediaDocument($title, $description, $images, $videos, $web, $detailTopic, $meta = [], $detailQuery = '')
{
    $x  = "<div class='snippet'>";

    /* --- media column ---------------------------------------------------- */
    $x .= "<div class='thumbnail-container'>";
    $x .= "<div style='display: flex; flex-wrap: wrap; gap: 5px; width: 100%; overflow: auto; border: 1px solid #ddd; border-radius: 8px; padding: 5px;'>";

    foreach ($images as $img) {
        if (empty($img['thumb_url'])) continue;
        $x .= "<img src=\"" . htmlspecialchars($img['thumb_url']) . "\""
            . " alt=\"" . htmlspecialchars($img['title'] ?? '') . "\""
            . " title=\"" . htmlspecialchars($img['title'] ?? '') . "\""
            . " style=\"height: 40px; width: 40px; object-fit: cover; border-radius: 5px;\">";
    }
    $x .= "</div>";

    foreach ($videos as $vid) {
        if (empty($vid['thumb_url'])) continue;
        $x .= "<video poster='" . htmlspecialchars($vid['thumb_url']) . "'"
            . " title='" . htmlspecialchars($vid['title'] ?? '') . "' controls>";
        $x .= "Your browser does not support the video tag.";
        $x .= "</video>";
    }
    $x .= "</div>";

    /* --- content column -------------------------------------------------- */
    $x .= "<div class='contenter'>";
    $x .= "<h3>" . htmlspecialchars($title) . "</h3>";
    $x .= "<p>" . htmlspecialchars($description) . "</p>";

    // Ranked web references belonging to this document.
    if (!empty($web)) {
        $x .= "<ul class='mmd-sources' style='margin:8px 0 0 0; padding-left:18px; font-size:0.88em;'>";
        foreach ($web as $w) {
            if (empty($w['title'])) continue;
            $href = !empty($w['link']) ? htmlspecialchars($w['link']) : '#';
            $x .= "<li style='margin-bottom:3px;'><a href='" . $href . "' target='_blank'"
                . " style='text-decoration:none;'>" . htmlspecialchars($w['title']) . "</a></li>";
        }
        $x .= "</ul>";
    }

    // Provenance marker. Visible only when debugging is switched on.
    if (EXSMUV_LLM_DEBUG && !empty($meta['source'])) {
        $x .= "<small style='color:#888;'>[" . htmlspecialchars($meta['source']);
        if (isset($meta['coverage_title'])) $x .= " cov=" . htmlspecialchars((string) $meta['coverage_title']);
        if (!empty($meta['reason']))        $x .= " " . htmlspecialchars($meta['reason']);
        $x .= " | w=" . count($web) . " i=" . count($images) . " v=" . count($videos);
        if (!empty($meta['context'])) $x .= " | ctx=\"" . htmlspecialchars($meta['context']) . "\"";
        $x .= "]</small>";
    }

    // The panel retrieves by topic and ranks by query + topic, exactly as the
    // card does, so the two views stay consistent.
    $escTopic = addslashes($detailTopic);
    $escQuery = addslashes($detailQuery);
    $x .= "<button onclick=\"loadMoreDetails('" . $escTopic . "', '" . $escQuery . "')\""
        . " style='margin-top: 10px; padding: 10px 20px; background-color: #007BFF; color: white; border: none; border-radius: 5px; cursor: pointer;'>More Details</button>";
    $x .= "</div>";
    $x .= "</div>";

    return $x;
}

/**
 * Retrieves all three verticals for a topic and re-ranks each of them.
 *
 * Retrieval uses the topic text, because that is what the search engine needs
 * in order to return results about the topic. Ranking uses the user's original
 * query COMBINED with the topic text, so that the items placed on a card are
 * relevant both to the card and to what the user originally asked for.
 *
 * @param string $topicText the card's topic
 * @param string $query     the user's original query
 * @return array{web: array, images: array, videos: array, context: string}|null
 */
function composeVerticals($topicText, $query = '')
{
    $web = BingWebResults($topicText, EXSMUV_FETCH_WEB, false);
    if (empty($web)) return null;

    $web = array_values(array_filter($web, function ($r) {
        return !(empty($r['title']) && empty($r['description']) && empty($r['link']));
    }));
    if (empty($web)) return null;

    $images = BingImageResults($topicText);
    $videos = scrapeBingVideoResults($topicText);

    // Second use of cosine similarity: query + card title.
    $context = exsmuvRankingContext($query, $topicText);

    return [
        'web'     => rankItemsByCosineSimilarity($context, $web, ['title', 'description'], EXSMUV_CARD_WEB),
        'images'  => rankItemsByCosineSimilarity($context, is_array($images) ? $images : [], ['title'], EXSMUV_CARD_IMAGES),
        'videos'  => rankItemsByCosineSimilarity($context, is_array($videos) ? $videos : [], ['title'], EXSMUV_CARD_VIDEOS),
        'context' => $context,
    ];
}

/* ---------------------------------------------------------------------------
 * Entry point 1: the query-level multimedia document
 * ------------------------------------------------------------------------- */

function Summarize($query)
{
    // The card's topic is the query itself, so the ranking context is the query.
    $bundle = composeVerticals($query, $query);
    if ($bundle === null) { echo ''; return; }

    if (EXSMUV_SUMMARIZE_MODE === 'per_result') {
        $x = '';
        foreach ($bundle['web'] as $result) {
            $summary = llmSummarizeTitleDescription(
                [$result['title'] ?? ''],
                [$result['description'] ?? ''],
                $query
            );
            $probe = $summary['title'] !== '' ? $summary['title'] : ($result['title'] ?? $query);
            $sub   = composeVerticals($probe, $query);

            $x .= renderMultimediaDocument(
                $summary['title'],
                $summary['description'],
                $sub['images'] ?? [],
                $sub['videos'] ?? [],
                [$result],
                $result['title'] ?? $query,
                $summary,
                $query
            );
        }
        echo $x;
        return;
    }

    /* Default: ONE document summarizing every retrieved result. This is the
       behaviour described in the paper, where f_meta receives the aggregated
       metadata of the whole result set rather than a single result. */
    $allWeb       = BingWebResults($query, EXSMUV_FETCH_WEB, false);
    $titles       = array_column($allWeb, 'title');
    $descriptions = array_column($allWeb, 'description');

    $summary = llmSummarizeTitleDescription($titles, $descriptions, $query);
    $summary['context'] = $bundle['context'];

    echo renderMultimediaDocument(
        $summary['title'] !== '' ? $summary['title'] : ucwords($query),
        $summary['description'],
        $bundle['images'],
        $bundle['videos'],
        $bundle['web'],
        $query,
        $summary,
        $query
    );
}

/* ---------------------------------------------------------------------------
 * Entry point 2: one multimedia document per mined topic
 * ------------------------------------------------------------------------- */

function FetchTopics($query)
{
    /* --- 1. gather candidate topics from all four sources ---------------- */
    $relatedSearch = BingRelatedSearches($query, false);
    $relatedTopics = array_column($relatedSearch, 'text');
    $suggestions   = getBingSuggestions($query, false);

    $seedResults      = BingWebResults($query, EXSMUV_FETCH_WEB, false);
    $seedTitles       = array_column($seedResults, 'title');
    $seedDescriptions = array_column($seedResults, 'description');

    // Lexical miner (TopicMiner).
    $subtopicsRaw = extractSubtopics($query, $seedTitles, $seedDescriptions);
    $subtopics    = array_column($subtopicsRaw, 'subtopic');

    // Semantic miner (LLM keyword extraction, f_topics).
    $llmKeywords = llmExtractKeywords($seedTitles, $seedDescriptions, $query);
    $llmTopics   = $llmKeywords['keywords'] ?? [];

    $allTopics = array_merge($relatedTopics, $suggestions, $subtopics, $llmTopics);

    /* --- 2. remove exact and near duplicates, BEFORE ranking ------------- */
    $uniqueTopics = dedupeTopics($allTopics, $query, EXSMUV_TOPIC_DEDUPE);
    if (empty($uniqueTopics)) { echo ''; return; }

    /* --- 3. rank the surviving topics against the query ------------------ */
    $rankedTopics = rankTitlesByCosineSimilarity($query, $uniqueTopics);

    /* --- 4. bounded working set: at most k documents --------------------- */
    $selected = selectTopics($rankedTopics);

    /* --- 5. compose one comprehensive multimedia document per topic ------ */
    $x = '';
    foreach ($selected as $topic) {
        $topicText = $topic['title'];
        if ($topicText === '') continue;

        set_time_limit(EXSMUV_TIMEOUT);

        // Retrieval by topic, ranking by query + topic.
        $bundle = composeVerticals($topicText, $query);
        if ($bundle === null) continue;

        // f_meta over the aggregated metadata of THIS topic's ranked results.
        $topicTitles       = array_column($bundle['web'], 'title');
        $topicDescriptions = array_column($bundle['web'], 'description');
        $summary = llmSummarizeTitleDescription($topicTitles, $topicDescriptions, $topicText);

        $summary['context'] = $bundle['context'];

        $x .= renderMultimediaDocument(
            $summary['title'] !== '' ? $summary['title'] : $topicText,
            $summary['description'] !== '' ? $summary['description'] : ($bundle['web'][0]['description'] ?? ''),
            $bundle['images'],
            $bundle['videos'],
            $bundle['web'],
            $topicText,
            $summary,
            $query
        );
    }
    echo $x;
}
?>
<?php
if (isset($_GET["query"])) {
    $q = htmlspecialchars($_GET["query"]);
    Summarize($q);
    FetchTopics($q);
}
?>
<script src="js/resultsnippet.js"></script>
