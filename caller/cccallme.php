<div class="container py-4">
<?php
/**
 * caller/cccallme.php
 * ---------------------------------------------------------------------------
 * Details panel for a multimedia document.
 *
 * The heading and summary are now produced by the LLM (f_meta) from the
 * aggregated titles and descriptions of the topic's web results, instead of
 * simply echoing the first result. This keeps the panel consistent with the
 * card the user clicked on.
 * ---------------------------------------------------------------------------
 */

include('debugging.php');
include_once('../verticalsfetcher.php');
include_once('../OpenLLM.php');

/* The details panel also runs LLM summarization, so lift the script limit. */
set_time_limit(EXSMUV_TIMEOUT);
ini_set('max_execution_time', EXSMUV_TIMEOUT);

if (!isset($_GET['title'])) {
    echo "<p>Invalid request: title parameter missing.</p>";
    echo '</div>';
    return;
}

$title = urldecode($_GET['title']);
$title = str_replace('+', ' ', $title);
$title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

/* The card passes its topic in 'title' and the user's original query in 'q'.
   Retrieval uses the topic. Ranking uses query + topic, exactly as the card
   does, so the panel is ordered consistently with the card that opened it.
   'q' is optional, so older links still work. */
$origQuery = isset($_GET['q'])
           ? htmlspecialchars(str_replace('+', ' ', urldecode($_GET['q'])), ENT_QUOTES, 'UTF-8')
           : '';
$rankContext = exsmuvRankingContext($origQuery, $title);

$searchResults = BingWebResults($title, 10, false);
$rawImages     = BingImageResults($title);
$rawVideos     = scrapeBingVideoResults($title);

/* Re-rank each vertical against query + topic, so the panel shows the most
   relevant items rather than whatever the engine happened to return first.
   The panel is the expanded view, so it shows more items than the card does. */
$searchResults = rankItemsByCosineSimilarity($rankContext, is_array($searchResults) ? $searchResults : [], ['title', 'description']);
$images        = rankItemsByCosineSimilarity($rankContext, is_array($rawImages) ? $rawImages : [], ['title'], 5);
$videos        = rankItemsByCosineSimilarity($rankContext, is_array($rawVideos) ? $rawVideos : [], ['title'], 5);

if (empty($searchResults)) {
    echo "<p>No results found for this topic.</p>";
    echo '</div>';
    return;
}

/* --- summarized heading and description ---------------------------------- */

$panelTitles       = array_column($searchResults, 'title');
$panelDescriptions = array_column($searchResults, 'description');

$summary = llmSummarizeTitleDescription($panelTitles, $panelDescriptions, $title);

$headingText = $summary['title'] !== ''
             ? $summary['title']
             : ($searchResults[0]['title'] ?? $title);

$summaryText = $summary['description'] !== ''
             ? $summary['description']
             : ($searchResults[0]['description'] ?? '');

echo '<h2 class="text-center mb-3">' . htmlspecialchars($headingText) . '</h2>';
echo '<p class="text-center mb-4">' . htmlspecialchars($summaryText) . '</p>';

if (EXSMUV_LLM_DEBUG && !empty($summary['source'])) {
    echo '<p class="text-center"><small style="color:#888;">['
       . htmlspecialchars($summary['source'])
       . (isset($summary['coverage_title']) ? ' cov=' . htmlspecialchars((string) $summary['coverage_title']) : '')
       . ' | ctx="' . htmlspecialchars($rankContext) . '"'
       . ']</small></p>';
}

/* --- keywords for this topic --------------------------------------------- */

$kw = llmExtractKeywords($panelTitles, $panelDescriptions, $title);
if (!empty($kw['keywords'])) {
    echo '<div class="text-center mb-4">';
    foreach ($kw['keywords'] as $k) {
        echo '<span class="badge bg-secondary" style="display:inline-block; margin:2px; padding:4px 8px; background:#eee; border-radius:12px; font-size:0.85em;">'
           . htmlspecialchars($k) . '</span>';
    }
    echo '</div>';
}

/* --- media columns -------------------------------------------------------- */

echo '<div class="fixed-columns">';

// Images
echo '<div>';
echo '<h5 class="section-heading">Images</h5>';
foreach ($images as $img) {
    if (empty($img['thumb_url'])) continue;
    echo '<div class="media-block">';
    echo "<img src='" . htmlspecialchars($img['thumb_url']) . "' class='media-img' alt='" . htmlspecialchars($img['title'] ?? '') . "'>";
    echo '<div class="media-title"><a href="' . htmlspecialchars($img['page_url'] ?? '#') . '" target="_blank">' . htmlspecialchars($img['title'] ?? '') . '</a></div>';
    echo '</div>';
}
echo '</div>';

// Videos
echo '<div>';
echo '<h5 class="section-heading">Videos</h5>';
foreach ($videos as $vid) {
    if (empty($vid['thumb_url'])) continue;
    echo '<div class="media-block">';
    echo '<div class="video-thumbnail">';
    echo "<a href='" . htmlspecialchars($vid['video_url'] ?? '#') . "' target='_blank'>";
    echo "<img src='" . htmlspecialchars($vid['thumb_url']) . "' class='media-video' alt='" . htmlspecialchars($vid['title'] ?? '') . "'>";
    echo '</a>';
    echo '</div>';
    echo '<div class="media-title"><a href="' . htmlspecialchars($vid['video_url'] ?? '#') . '" target="_blank">' . htmlspecialchars($vid['title'] ?? '') . '</a></div>';
    echo '</div>';
}
echo '</div>';

echo '</div>'; // close fixed-columns

/* --- references ----------------------------------------------------------- */

echo '<div class="mt-5 reference-links text-center">';
echo '<h5>References</h5>';
$refCount = min(3, count($searchResults));
for ($i = 0; $i < $refCount; $i++) {
    $ref = $searchResults[$i];
    echo '[' . ($i + 1) . '] <a href="' . htmlspecialchars($ref['link'] ?? '#') . '" class="text-decoration-none" target="_blank">'
       . htmlspecialchars($ref['title'] ?? '') . '</a><br>';
}
echo '</div>';
?>
</div>
