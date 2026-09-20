<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include_once("verticalsfetcher.php");

function tokenizeQuery($q) {
    $q = strtolower($q);
    $tokens = preg_split('/[^a-z0-9]+/i', $q);
    $clean = [];
    foreach ($tokens as $t) {
        $t = trim($t);
        if ($t === '') continue;
        if (strlen($t) >= 3) $clean[] = $t;
    }
    $clean = array_values(array_unique($clean));
    return $clean;
}

function isRelevantItem($item, $tokens) {
    if (empty($tokens)) return false;
    $haystack_fields = [];
    foreach (['title','description','link','page_url','pageUrl','pageurl','image_url','thumb_url','video_url'] as $f) {
        if (!empty($item[$f])) $haystack_fields[] = strtolower($item[$f]);
    }

    if (empty($haystack_fields)) return false;

    $hay = implode(' ', $haystack_fields);

    foreach ($tokens as $token) {
        if ($token === '') continue;
        if (strpos($hay, $token) !== false) return true;
    }
    return false;
}

function precisionAtK($items, $tokens, $k) {
    $relevant = 0;
    for ($i = 0; $i < $k; $i++) {
        if (isset($items[$i]) && !empty($items[$i])) {
            if (isRelevantItem($items[$i], $tokens)) $relevant++;
        }
    }
    if ($k === 0) return 0.0;
    return $relevant / $k;
}
function pct($v) {
    return number_format($v * 100, 1) . '%';
}

function renderDocumentTable($index, $docTitle, $webItems, $imgItems, $vidItems, $pweb, $pimg, $pvid) {
    $html = "";
    $html .= "<div style=\"max-width:1000px; margin:18px auto; border-radius:10px; box-shadow:0 6px 18px rgba(0,0,0,0.08); overflow:hidden; font-family:Inter,Segoe UI,Roboto,Arial,sans-serif;\">";
    $html .= "<div style=\"background:linear-gradient(90deg,#0f172a,#0b84ff); color:white; padding:14px 18px; display:flex; justify-content:space-between; align-items:center;\">";
    $html .= "<div style=\"font-size:16px; font-weight:700;\">Summarized Multimedia Document #".($index+1)."</div>";
    $html .= "<div style=\"font-size:14px; opacity:0.95;\">Sub-Query: <span style=\"font-weight:600;\">".htmlspecialchars($docTitle)."</span></div>";
    $html .= "</div>";
    $html .= "<div style=\"padding:14px 18px; background:#fff;\">";
    $html .= "<div style=\"display:flex; gap:16px; flex-wrap:wrap;\">";
    $html .= "<div style=\"flex:1 1 260px; min-width:260px;\">";
    $html .= "<table cellpadding=\"8\" cellspacing=\"0\" style=\"width:100%; border-collapse:collapse;\">";
    $html .= "<tr style=\"background:#f5f7fb;\"><td style=\"font-weight:700;\">Metric</td><td style=\"font-weight:700; text-align:right;\">Value</td></tr>";
    $html .= "<tr><td>Web p@3</td><td style=\"text-align:right;\">".pct($pweb)." <small style='color:#666; display:block;'>(" . number_format($pweb*3,0) . "/3 relevant)</small></td></tr>";
    $html .= "<tr><td>Images p@5</td><td style=\"text-align:right;\">".pct($pimg)." <small style='color:#666; display:block;'>(" . number_format($pimg*5,0) . "/5 relevant)</small></td></tr>";
    $html .= "<tr><td>Videos p@5</td><td style=\"text-align:right;\">".pct($pvid)." <small style='color:#666; display:block;'>(" . number_format($pvid*5,0) . "/5 relevant)</small></td></tr>";
    $html .= "</table>";
     $html .= "</div>";
    $html .= "<div style=\"flex:2 1 420px; min-width:320px;\">";
    $html .= "<div style=\"margin-bottom:10px;\"><div style=\"font-weight:700; margin-bottom:6px;\">Top Web Results (top 3)</div>";
    $html .= "<div style=\"display:flex; flex-direction:column; gap:8px;\">";
    for ($i=0; $i<3; $i++) {
        if (!isset($webItems[$i])) {
            $html .= "<div style=\"padding:8px; border-radius:8px; border:1px dashed #eee; color:#999;\">(no result)</div>";
            continue;
        }
        $item = $webItems[$i];
        $relevant = isRelevantItem($item, tokenizeQuery($docTitle));
        $markStyle = $relevant ? "color: #0b703e; font-weight:700;" : "color:#9b1d1d;";
        $title = htmlspecialchars($item['title'] ?? ($item['link'] ?? '(no title)'));
        $desc = htmlspecialchars($item['description'] ?? '');
        $link = htmlspecialchars($item['link'] ?? '');
        $html .= "<div style=\"padding:8px; border-radius:8px; border:1px solid #f0f2f6;\">";
        $html .= "<div style=\"display:flex; justify-content:space-between; align-items:center; gap:8px;\">";
        $html .= "<div style=\"font-size:14px;\">$title";
        if ($link) $html .= " <small style=\"color:#0b84ff;\"><a href=\"$link\" style=\"color:inherit; text-decoration:none;\" target=\"_blank\">↗</a></small>";
        $html .= "</div>";
        $html .= "<div style=\"$markStyle\">".($relevant ? "Relevant" : "Not relevant")."</div>";
        $html .= "</div>";
        if ($desc) $html .= "<div style=\"margin-top:6px; color:#555; font-size:13px;\">$desc</div>";
        $html .= "</div>";
    }
    $html .= "</div></div>";

    $html .= "<div style=\"margin-bottom:10px;\"><div style=\"font-weight:700; margin-bottom:6px;\">Top Images (top 5)</div>";
    $html .= "<div style=\"display:flex; gap:8px; flex-wrap:wrap;\">";
    for ($i=0; $i<5; $i++) {
        if (!isset($imgItems[$i])) {
            $html .= "<div style=\"width:92px; height:68px; border-radius:8px; border:1px dashed #eee; display:flex; align-items:center; justify-content:center; color:#999;\">no</div>";
            continue;
        }
        $it = $imgItems[$i];
        $relevant = isRelevantItem($it, tokenizeQuery($docTitle));
        $thumb = htmlspecialchars($it['thumb_url'] ?? $it['image_url'] ?? '');
        $alt = htmlspecialchars($it['title'] ?? $it['description'] ?? '');
		$purl = htmlspecialchars($it['page_url'] ?? '');
        $badge = $relevant ? "<div style='position:absolute; top:6px; left:6px; font-size:11px; background:rgba(11,132,255,0.95); color:white; padding:4px 6px; border-radius:6px;'>R</div>" : "";
        if ($thumb) {
            $html .= "<div style=\"position:relative; width:92px; height:68px; border-radius:8px; overflow:hidden; border:1px solid #f0f2f6; background:#fafafa;\">";
            $html .= "$badge";
            $html .= "<a href=\"$purl\" target='_blank'><img src=\"$thumb\" alt=\"$alt\" style=\"width:100%; height:100%; object-fit:cover; display:block;\"></a>";
            $html .= "</div>";
        } else {
            $html .= "<div style=\"width:92px; height:68px; border-radius:8px; border:1px dashed #eee; display:flex; align-items:center; justify-content:center; color:#999;\">no</div>";
        }
    }
    $html .= "</div></div>";

    $html .= "<div><div style=\"font-weight:700; margin-bottom:6px;\">Top Videos (top 5)</div>";
    $html .= "<div style=\"display:flex; gap:8px; flex-wrap:wrap;\">";
    for ($i=0; $i<5; $i++) {
        if (!isset($vidItems[$i])) {
            $html .= "<div style=\"width:140px; height:78px; border-radius:8px; border:1px dashed #eee; display:flex; align-items:center; justify-content:center; color:#999;\">no</div>";
            continue;
        }
        $it = $vidItems[$i];
        $relevant = isRelevantItem($it, tokenizeQuery($docTitle));
        $thumb = htmlspecialchars($it['thumb_url'] ?? '');
        $title = htmlspecialchars($it['title'] ?? '');
		$vurl = htmlspecialchars($it['video_url'] ?? '');
        $badge = $relevant ? "<div style='position:absolute; top:6px; left:6px; font-size:11px; background:rgba(11,132,255,0.95); color:white; padding:4px 6px; border-radius:6px;'>R</div>" : "";
        if ($thumb) {
            $html .= "<div style=\"position:relative; width:140px; height:78px; border-radius:8px; overflow:hidden; border:1px solid #f0f2f6; background:#fafafa;\">";
            $html .= "$badge";
            $html .= "<a href=\"$vurl\" target='_blank'><img src=\"$thumb\" alt=\"$title\" style=\"width:100%; height:100%; object-fit:cover; display:block;\"></a>";
            $html .= "</div>";
        } else {
            $html .= "<div style=\"width:140px; height:78px; border-radius:8px; border:1px dashed #eee; display:flex; align-items:center; justify-content:center; color:#999;\">no</div>";
        }
    }
    $html .= "</div></div>";

    $html .= "</div>";
    $html .= "</div>";
    $html .= "</div>";
    $html .= "</div>";

    return $html;
}

$inputQuery = isset($_GET['query']) ? trim($_GET['query']) : '';

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Precision Results</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="background:#f3f6fb; padding:22px 16px 80px 16px; font-family:Inter,Segoe UI,Roboto,Arial,sans-serif;">

<?php

if ($inputQuery === '') {
    echo "<div style=\"max-width:1100px;margin:0 auto;color:#666;\">No query specified. Provide <code>?query=...</code> in the URL or use the form above.</div>";
    echo "</body></html>";
    exit;
}
echo "<div align='center'><h1 style='margin:0 0 8px 0; font-size:20px;'>Summarized Multimedia Documents Against Query : ".$inputQuery."</h1></div>";
$topDocs = BingWebResults($inputQuery, 10, false);
if (!is_array($topDocs) || count($topDocs) === 0) {
    echo "<div style=\"max-width:1100px;margin:0 auto;color:#a00;\">No web results returned for the input query.</div></body></html>";
    exit;
}

echo "<div style=\"max-width:1100px;margin:0 auto 18px auto;\">";

for ($i = 0; $i < count($topDocs); $i++) {
    $doc = $topDocs[$i];
    $docTitle = $doc['title'] ?? ($doc['link'] ?? '');
    if (trim($docTitle) === '') continue; 
    $tokens = tokenizeQuery($docTitle);

    $webItems = BingWebResults($docTitle, 3, false);
    if (!is_array($webItems)) $webItems = [];
    $imgItems = BingImageResults($docTitle);
    if (!is_array($imgItems)) $imgItems = [];
    $vidItems = scrapeBingVideoResults($docTitle);
    if (!is_array($vidItems)) $vidItems = [];

    $pweb = precisionAtK($webItems, $tokens, 3);
    $pimg = precisionAtK($imgItems, $tokens, 5);
    $pvid = precisionAtK($vidItems, $tokens, 5);

    echo renderDocumentTable($i, $docTitle, $webItems, $imgItems, $vidItems, $pweb, $pimg, $pvid);
}

echo "</div>";
?>

</body>
</html>
